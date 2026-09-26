<?php

namespace App\Console\Commands;

use App\Actions\AnswerQuestion;
use App\Actions\Evaluation\GradeAnswer;
use App\Ai\Answer;
use App\Ai\EvaluationQuestion;
use App\Enums\EvaluationGrade;
use App\Models\Chunk;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Runs the evaluation test set through the same flow as POST /api/chat, grades every answer with the
 * judge and stores the results, so changes to the RAG pipeline can be compared run against run.
 *
 * Each question is asked several times at temperature 0, because a single run varies by a few
 * questions and hides small improvements or regressions.
 *
 * @phpstan-type RunResult array{grade: string, note: string, answer: ?string, sources: list<string>, seconds: float}
 * @phpstan-type QuestionResult array{id: string, category: string, question: string, expected_answer: string, runs: list<RunResult>}
 * @phpstan-type Comparison array{label: string, grades: array<string, EvaluationGrade>}
 */
#[Signature('rag:evaluate
    {--questions= : CSV with id, kategorija, pitanje, ocekivani_odgovor and izvor columns (default: storage/app/private/evaluation/questions.csv)}
    {--runs=3 : How many times to ask every question}
    {--temperature=0 : Temperature of the answering model}
    {--label= : Short name of the change being evaluated, stored with the results}
    {--only= : Comma separated question ids to evaluate, e.g. Q01,Q17}
    {--compare= : Results file to compare with, or "latest" for the previous run}
    {--delay=6.5 : Seconds between questions; the Cohere trial key allows 10 reranks per minute}')]
#[Description('Evaluate RAG answers against the test set and grade them with an LLM judge')]
class EvaluateRag extends Command
{
    private const string RESULTS_DIRECTORY = 'evaluation/results';

    private const int MAX_ATTEMPTS = 3;

    public function handle(AnswerQuestion $answerQuestion, GradeAnswer $gradeAnswer): int
    {
        $questions = $this->questions();
        $runs = max(1, (int) $this->option('runs'));
        $user = User::query()->firstOrFail();

        config(['ai.rag.temperature' => (float) $this->option('temperature')]);

        $comparison = $this->comparisonResults();
        $path = self::RESULTS_DIRECTORY.'/'.now()->format('Y-m-d_His').($this->option('label') ? '_'.str($this->option('label'))->slug() : '').'.json';

        $results = [
            'label' => $this->option('label'),
            'started_at' => now()->toIso8601String(),
            'commit' => $this->commit(),
            'temperature' => config()->float('ai.rag.temperature'),
            'answer_model' => config('ai.providers.'.config('ai.default').'.models.text.default'),
            'reranker' => config('ai.default_for_reranking'),
            'min_relevance' => config()->float('ai.rag.min_relevance'),
            'judge' => config('ai.rag.judge'),
            'runs' => $runs,
            'questions' => $this->questionResults($questions, []),
        ];

        $runResults = [];

        $this->components->info("Evaluating {$questions->count()} questions, {$runs} runs, temperature {$results['temperature']}.");

        $lastQuestionAt = null;

        foreach (range(1, $runs) as $run) {
            $progress = $this->output->createProgressBar($questions->count());
            $progress->setFormat("Run {$run}/{$runs} %current%/%max% [%bar%] %message%");
            $progress->setMessage('');

            foreach ($questions->values() as $index => $question) {
                if ($lastQuestionAt !== null) {
                    Sleep::for(max(0, (float) $this->option('delay') - (microtime(true) - $lastQuestionAt)))->seconds();
                }

                $lastQuestionAt = microtime(true);
                $runResults[$index][] = $this->evaluate($question, $user, $answerQuestion, $gradeAnswer);
                $results['questions'] = $this->questionResults($questions, $runResults);

                Storage::disk('local')->put($path, (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $progress->setMessage($question->id);
                $progress->advance();
            }

            $progress->finish();
            $this->newLine();
        }

        $this->summarize($results['questions'], $comparison);
        $this->components->info('Results: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    /**
     * Ask and grade one question. The conversation it creates is rolled back so evaluations don't
     * fill the conversation history; rate limits and timeouts are retried after a pause, and the
     * evaluation stops when they persist (e.g. the reranker's monthly quota is used up).
     *
     * @return RunResult
     */
    private function evaluate(EvaluationQuestion $question, User $user, AnswerQuestion $answerQuestion, GradeAnswer $gradeAnswer): array
    {
        $error = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $startedAt = microtime(true);

            DB::beginTransaction();

            try {
                $answer = $answerQuestion->handle($question->question, $user);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();

                if (! $this->isTransient($exception)) {
                    break;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('Answering is not available: '.$error, previous: $exception);
                }

                Sleep::for(61)->seconds();

                continue;
            } finally {
                DB::rollBack();
            }

            $seconds = round(microtime(true) - $startedAt, 2);

            $grade = $this->grade($question, $answer, $gradeAnswer);

            return [
                'grade' => $grade['grade']->value,
                'note' => $grade['note'],
                'answer' => $answer->answer,
                'sources' => array_values($answer->chunks
                    ->map(fn (Chunk $chunk): string => $chunk->document->title.($chunk->heading ? ' — '.$chunk->heading : ''))
                    ->unique()->all()),
                'seconds' => $seconds,
            ];
        }

        return ['grade' => EvaluationGrade::Error->value, 'note' => $error, 'answer' => null, 'sources' => [], 'seconds' => 0.0];
    }

    /**
     * Grade an answer, retrying only the grading when the judge's provider is busy. When it stays
     * unavailable (e.g. the daily quota is used up) the evaluation stops, because every further
     * answer would only be graded as an error; the results so far are already saved.
     *
     * @return array{grade: EvaluationGrade, note: string}
     */
    private function grade(EvaluationQuestion $question, Answer $answer, GradeAnswer $gradeAnswer): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $gradeAnswer->handle($question, $answer);
            } catch (Throwable $exception) {
                if (! $this->isTransient($exception)) {
                    return ['grade' => EvaluationGrade::Error, 'note' => 'Ocjenjivanje nije uspjelo: '.$exception->getMessage()];
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('The judge is not available: '.$exception->getMessage(), previous: $exception);
                }

                Sleep::for(61)->seconds();
            }
        }
    }

    private function isTransient(Throwable $exception): bool
    {
        return str($exception->getMessage())->lower()->contains(['rate limit', 'too many requests', '429', 'overloaded', 'unavailable', 'curl error 28', 'timed out']);
    }

    /**
     * @return Collection<int, EvaluationQuestion>
     */
    private function questions(): Collection
    {
        $path = $this->option('questions') ?: Storage::disk('local')->path('evaluation/questions.csv');
        $handle = is_readable($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            throw new RuntimeException("Cannot read the questions file {$path}.");
        }

        $header = array_map(fn (?string $column): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $column)), fgetcsv($handle, escape: '') ?: []);
        $questions = collect();

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }

            $values = array_combine($header, array_map(fn (?string $value): string => (string) $value, $row));

            $questions->push(new EvaluationQuestion(
                id: $values['id'],
                category: $values['kategorija'],
                question: $values['pitanje'],
                expectedAnswer: $values['ocekivani_odgovor'],
                source: $values['izvor'] ?? '',
            ));
        }

        fclose($handle);

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        return $only === [] ? $questions : $questions->filter(fn (EvaluationQuestion $question): bool => in_array($question->id, $only, true))->values();
    }

    /**
     * @param  Collection<int, EvaluationQuestion>  $questions
     * @param  array<int, list<RunResult>>  $runResults
     * @return list<QuestionResult>
     */
    private function questionResults(Collection $questions, array $runResults): array
    {
        return array_values($questions->map(fn (EvaluationQuestion $question, int $index): array => [
            'id' => $question->id,
            'category' => $question->category,
            'question' => $question->question,
            'expected_answer' => $question->expectedAnswer,
            'runs' => $runResults[$index] ?? [],
        ])->all());
    }

    /**
     * @param  list<QuestionResult>  $results
     * @param  Comparison|null  $comparison
     */
    private function summarize(array $results, ?array $comparison): void
    {
        $questions = collect($results);
        $runCount = count($results[0]['runs'] ?? []);
        $runIndexes = range(0, max(0, $runCount - 1));

        $countGrade = fn (EvaluationGrade $grade, int $run): int => $questions
            ->filter(fn (array $question): bool => ($question['runs'][$run]['grade'] ?? null) === $grade->value)
            ->count();

        $accuracies = collect($runIndexes)->map(fn (int $run): float => $countGrade(EvaluationGrade::Correct, $run) / max(1, $questions->count()));

        $this->newLine();
        $this->table(
            ['Ocjena', ...array_map(fn (int $run): string => 'Run '.($run + 1), $runIndexes), 'Većinska'],
            array_map(fn (EvaluationGrade $grade): array => [
                $grade->label(),
                ...array_map(fn (int $run): int => $countGrade($grade, $run), $runIndexes),
                $questions->filter(fn (array $question): bool => $this->majorityGrade($this->grades($question)) === $grade)->count(),
            ], EvaluationGrade::cases()),
        );

        $this->components->twoColumnDetail('Točnost (prosjek)', $this->percent((float) $accuracies->avg()));
        $this->components->twoColumnDetail('Točnost (raspon)', $this->percent((float) $accuracies->min()).' – '.$this->percent((float) $accuracies->max()));

        $unstable = $questions->filter(fn (array $question): bool => count(array_unique(array_column($question['runs'], 'grade'))) > 1);
        $this->components->twoColumnDetail('Nestabilna pitanja', $unstable->count().($unstable->isNotEmpty() ? ' ('.$unstable->pluck('id')->implode(', ').')' : ''));

        $this->newLine();
        $this->table(
            ['Kategorija', 'Pitanja', 'Točno (većinska)'],
            $questions->groupBy('category')->map(fn (Collection $group, string $category): array => [
                $category,
                $group->count(),
                $group->filter(fn (array $question): bool => $this->majorityGrade($this->grades($question)) === EvaluationGrade::Correct)->count(),
            ])->values()->all(),
        );

        if ($comparison !== null) {
            $this->compare($results, $comparison);
        }
    }

    /**
     * @param  list<QuestionResult>  $questions
     * @param  Comparison  $comparison
     */
    private function compare(array $questions, array $comparison): void
    {
        $changes = [];

        foreach ($questions as $question) {
            $before = $comparison['grades'][$question['id']] ?? null;
            $after = $this->majorityGrade($this->grades($question));

            if ($before !== null && $before !== $after) {
                $changes[] = [$question['id'], $before->label(), $after->label(), $after->severity() < $before->severity() ? 'bolje' : 'lošije'];
            }
        }

        $this->newLine();
        $this->components->info("Usporedba s {$comparison['label']} (većinske ocjene)");

        if ($changes === []) {
            $this->components->twoColumnDetail('Promjene', 'nema');

            return;
        }

        $this->table(['Pitanje', 'Prije', 'Sad', ''], $changes);
    }

    /**
     * @param  QuestionResult  $question
     * @return list<EvaluationGrade>
     */
    private function grades(array $question): array
    {
        return array_map(fn (array $run): EvaluationGrade => EvaluationGrade::from($run['grade']), $question['runs']);
    }

    /**
     * The grade given most often across runs; ties go to the worse grade.
     *
     * @param  list<EvaluationGrade>  $grades
     */
    private function majorityGrade(array $grades): EvaluationGrade
    {
        $counts = [];

        foreach ($grades as $grade) {
            $counts[$grade->value] = ($counts[$grade->value] ?? 0) + 1;
        }

        $majority = EvaluationGrade::Error;
        $majorityCount = 0;

        foreach ($counts as $value => $count) {
            $grade = EvaluationGrade::from($value);

            if ($count > $majorityCount || ($count === $majorityCount && $grade->severity() > $majority->severity())) {
                $majority = $grade;
                $majorityCount = $count;
            }
        }

        return $majority;
    }

    /**
     * Load earlier results as the majority grade per question.
     *
     * @return Comparison|null
     */
    private function comparisonResults(): ?array
    {
        $compare = (string) $this->option('compare');

        if ($compare === '') {
            return null;
        }

        $disk = Storage::disk('local');

        $path = $compare === 'latest'
            ? collect($disk->files(self::RESULTS_DIRECTORY))->filter(fn (string $file): bool => str_ends_with($file, '.json'))->sort()->last()
            : (str_contains($compare, '/') ? $compare : self::RESULTS_DIRECTORY.'/'.$compare);

        if ($path === null || ! $disk->exists($path)) {
            throw new RuntimeException("Results to compare with were not found: {$compare}.");
        }

        $data = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! is_array($data['questions'] ?? null)) {
            throw new RuntimeException("{$path} is not an evaluation results file.");
        }

        $grades = [];

        foreach ($data['questions'] as $question) {
            if (! is_array($question) || ! is_string($question['id'] ?? null) || ! is_array($question['runs'] ?? null)) {
                continue;
            }

            $grades[$question['id']] = $this->majorityGrade(array_values(array_map(
                fn (mixed $run): EvaluationGrade => EvaluationGrade::tryFrom(is_array($run) && is_string($run['grade'] ?? null) ? $run['grade'] : '') ?? EvaluationGrade::Error,
                $question['runs'],
            )));
        }

        return [
            'label' => is_string($data['label'] ?? null) ? $data['label'] : basename($path),
            'grades' => $grades,
        ];
    }

    private function commit(): ?string
    {
        $result = Process::run('git rev-parse --short HEAD');

        return $result->successful() ? trim($result->output()) : null;
    }

    private function percent(float $share): string
    {
        return number_format($share * 100, 1, ',').' %';
    }
}
