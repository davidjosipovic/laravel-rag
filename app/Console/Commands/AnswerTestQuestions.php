<?php

namespace App\Console\Commands;

use App\Actions\AnswerQuestion;
use App\Ai\Agents\Rag;
use App\Ai\Answer;
use App\Ai\EvaluationQuestion;
use App\Console\Commands\Concerns\UsesEvaluationTestSet;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs the test set through the same flow as POST /api/chat and stores every answer together with
 * the passages it was based on, so the answers can be reviewed and graded afterwards.
 *
 * Nothing is graded here, only refusals are counted, because whether the system refused is not a
 * judgement call.
 *
 * @phpstan-type Passage array{document: ?string, heading: ?string, content: string}
 * @phpstan-type RunResult array{answer: ?string, refused: bool, error: ?string, passages: list<Passage>, seconds: float}
 * @phpstan-type QuestionResult array{id: string, category: string, question: string, expected_answer: string, source: string, runs: list<RunResult>}
 */
#[Signature('rag:answer-test-questions
    {--questions= : CSV with id, kategorija, pitanje, ocekivani_odgovor and izvor columns (default: storage/app/private/evaluation/questions.csv)}
    {--runs=1 : How many times to ask every question}
    {--temperature=0 : Temperature of the answering model}
    {--label= : Short name of the change being evaluated, stored with the results}
    {--only= : Comma separated question ids to ask, e.g. Q01,Q17}
    {--delay=6.5 : Seconds between questions; the Cohere trial key allows 10 reranks per minute}')]
#[Description('Answer the test set questions and store the answers for review')]
class AnswerTestQuestions extends Command
{
    use UsesEvaluationTestSet;

    private const string RESULTS_DIRECTORY = 'evaluation/answers';

    public function handle(AnswerQuestion $answerQuestion): int
    {
        $questions = $this->questions();
        $runs = max(1, (int) $this->option('runs'));
        $user = User::query()->firstOrFail();

        config(['ai.rag.temperature' => (float) $this->option('temperature')]);

        $path = $this->resultsPath(self::RESULTS_DIRECTORY);

        $results = [
            'label' => $this->option('label'),
            'started_at' => now()->toIso8601String(),
            'commit' => $this->commit(),
            'temperature' => config()->float('ai.rag.temperature'),
            'answer_model' => config('ai.providers.'.config('ai.default').'.models.text.default'),
            'reranker' => config('ai.default_for_reranking'),
            'min_relevance' => config()->float('ai.rag.min_relevance'),
            'runs' => $runs,
            'questions' => $this->questionResults($questions, []),
        ];

        $runResults = [];

        $this->components->info("Answering {$questions->count()} questions, {$runs} runs, temperature {$results['temperature']}.");

        foreach (range(1, $runs) as $run) {
            $progress = $this->output->createProgressBar($questions->count());
            $progress->setFormat("Run {$run}/{$runs} %current%/%max% [%bar%] %message%");
            $progress->setMessage('');

            foreach ($questions->values() as $index => $question) {
                $this->paceRequests();

                $runResults[$index][] = $this->answer($question, $user, $answerQuestion);
                $results['questions'] = $this->questionResults($questions, $runResults);

                $this->storeResults($path, $results);

                $progress->setMessage($question->id);
                $progress->advance();
            }

            $progress->finish();
            $this->newLine();
        }

        $this->summarize($results['questions']);
        $this->components->info('Answers: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    /**
     * Ask one question. The conversation it creates is rolled back so test runs don't fill the
     * conversation history.
     *
     * @return RunResult
     */
    private function answer(EvaluationQuestion $question, User $user, AnswerQuestion $answerQuestion): array
    {
        $startedAt = microtime(true);

        $answer = $this->retryTransient('Answering', function () use ($question, $user, $answerQuestion): Answer|Throwable {
            DB::beginTransaction();

            try {
                return $answerQuestion->handle($question->question, $user);
            } catch (Throwable $exception) {
                return $this->isTransient($exception) ? throw $exception : $exception;
            } finally {
                DB::rollBack();
            }
        });

        if ($answer instanceof Throwable) {
            return ['answer' => null, 'refused' => false, 'error' => $answer->getMessage(), 'passages' => [], 'seconds' => 0.0];
        }

        return [
            'answer' => $answer->answer,
            'refused' => str_contains($answer->answer, Rag::NOT_AVAILABLE),
            'error' => null,
            'passages' => array_values($answer->chunks->map(fn (Chunk $chunk): array => [
                'document' => $chunk->document->title,
                'heading' => $chunk->heading,
                'content' => $chunk->content,
            ])->all()),
            'seconds' => round(microtime(true) - $startedAt, 2),
        ];
    }

    /**
     * @param  Collection<int, EvaluationQuestion>  $questions
     * @param  array<int, list<RunResult>>  $runResults
     * @return list<QuestionResult>
     */
    private function questionResults(Collection $questions, array $runResults): array
    {
        return array_values($questions->map(fn (EvaluationQuestion $question, int $index): array => [
            ...$question->toArray(),
            'runs' => $runResults[$index] ?? [],
        ])->all());
    }

    /**
     * Count refusals, the only thing that can be told without judging the answers: refusing an
     * answerable question and answering an unanswerable one are both mistakes.
     *
     * @param  list<QuestionResult>  $results
     */
    private function summarize(array $results): void
    {
        $questions = collect($results);
        $runs = $questions->flatMap(fn (array $question): array => $question['runs']);

        $wronglyRefused = $questions->filter(fn (array $question): bool => $question['category'] !== EvaluationQuestion::UNANSWERABLE
            && collect($question['runs'])->contains('refused', true));
        $wronglyAnswered = $questions->filter(fn (array $question): bool => $question['category'] === EvaluationQuestion::UNANSWERABLE
            && collect($question['runs'])->contains(fn (array $run): bool => ! $run['refused'] && $run['error'] === null));
        $failed = $questions->filter(fn (array $question): bool => collect($question['runs'])->contains(fn (array $run): bool => $run['error'] !== null));

        $this->newLine();
        $this->components->twoColumnDetail('Odgovori', (string) $runs->count());
        $this->components->twoColumnDetail('Odbijeno, a odgovor postoji', $this->questionIds($wronglyRefused));
        $this->components->twoColumnDetail('Odgovoreno na neodgovorivo', $this->questionIds($wronglyAnswered));
        $this->components->twoColumnDetail('Greške', $this->questionIds($failed));
        $this->components->twoColumnDetail('Prosječno trajanje', number_format((float) $runs->where('error', null)->avg('seconds'), 1, ',').' s');
    }
}
