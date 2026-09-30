<?php

namespace App\Console\Commands;

use App\Actions\HybridSearch\RetrieveRelevantChunks;
use App\Ai\EvaluationQuestion;
use App\Console\Commands\Concerns\UsesEvaluationTestSet;
use App\Models\Chunk;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Evaluates only the search: every test set question goes through the same retrieval as
 * POST /api/chat, without asking the model, and the passages are checked against the test set
 * in code, so retrieval changes can be compared run against run without an LLM.
 *
 * - source hit: at least one passage comes from a document listed in the izvor column (not
 *   checked for unanswerable questions, whose izvor only names the document that lacks the answer);
 * - coverage: the share of the expected answer's word stems and numbers found in the passages.
 *   Wording that is only in the expected answer keeps it below 100 %, so it is meant for
 *   comparing runs rather than as an absolute score;
 * - passage recall: the share of the passages listed in the odlomci column that were retrieved,
 *   matched by the last part of their heading; unlike the source hit it shows whether the right
 *   passages were found, not just any passage of the right document;
 * - reciprocal rank: 1 / the position of the first expected passage, averaged into the MRR;
 * - unanswerable questions should retrieve nothing above the minimum relevance.
 *
 * @phpstan-type RetrievedChunk array{id: int, document: ?string, heading: ?string, relevance: ?float}
 * @phpstan-type QuestionResult array{id: string, category: string, question: string, expected_answer: string, source: string, source_hit: ?bool, coverage: ?float, missing_terms: list<string>, passage_recall: ?float, reciprocal_rank: ?float, missing_passages: list<string>, chunks: list<RetrievedChunk>}
 */
#[Signature('rag:evaluate-retrieval
    {--questions= : CSV with id, kategorija, pitanje, ocekivani_odgovor and izvor columns (default: storage/app/private/evaluation/questions.csv)}
    {--label= : Short name of the change being evaluated, stored with the results}
    {--only= : Comma separated question ids to evaluate, e.g. Q01,Q17}
    {--compare= : Results file to compare with, or "latest" for the previous run}
    {--delay=6.5 : Seconds between questions; the Cohere trial key allows 10 reranks per minute}')]
#[Description('Evaluate retrieval against the test set without an LLM')]
class EvaluateRetrieval extends Command
{
    use UsesEvaluationTestSet;

    private const string RESULTS_DIRECTORY = 'evaluation/retrieval';

    /**
     * Coverage at which the passages are considered to contain the expected answer.
     */
    private const float COVERED = 0.8;

    /**
     * A coverage change smaller than this between runs is not listed in the comparison.
     */
    private const float COVERAGE_CHANGE = 0.1;

    /**
     * Croatian number words and their common forms; "drugi" is left out, because it mostly means "other".
     *
     * @var array<int, string>
     */
    private const array NUMBER_WORDS = [
        11 => 'jedanaest\p{L}{0,3}',
        12 => 'dvanaest\p{L}{0,3}',
        13 => 'trinaest\p{L}{0,3}',
        14 => 'četrnaest\p{L}{0,3}',
        15 => 'petnaest\p{L}{0,3}',
        16 => 'šesnaest\p{L}{0,3}',
        17 => 'sedamnaest\p{L}{0,3}',
        18 => 'osamnaest\p{L}{0,3}',
        19 => 'devetnaest\p{L}{0,3}',
        20 => 'dvadeset\p{L}{0,3}',
        30 => 'trideset\p{L}{0,3}',
        40 => 'četrdeset\p{L}{0,3}',
        50 => 'pedeset\p{L}{0,3}',
        10 => 'deset|deset[io]\p{L}{0,2}|desetak',
        1 => 'jedan|jedn[aeiou]\p{L}{0,2}|prv[aeiou]\p{L}{0,2}',
        2 => 'dva|dvije|dvaju|dvoje',
        3 => 'tri|troje|treć[aeiou]\p{L}{0,2}',
        4 => 'četiri|četvero|četvrt[aeiou]\p{L}{0,2}',
        5 => 'pet|pet[aeiou]|pet[aeiou]g|petero',
        6 => 'šest|šest[aeiou]\p{L}{0,2}|šestero',
        7 => 'sedam|sedm[aeiou]\p{L}{0,2}',
        8 => 'osam|osm[aeiou]\p{L}{0,2}',
        9 => 'devet|devet[aeiou]\p{L}{0,2}',
    ];

    public function handle(RetrieveRelevantChunks $retrieveRelevantChunks): int
    {
        $questions = $this->questions();
        $comparison = $this->option('compare') ? $this->loadResults(self::RESULTS_DIRECTORY, (string) $this->option('compare')) : null;
        $path = $this->resultsPath(self::RESULTS_DIRECTORY);

        $results = [
            'label' => $this->option('label'),
            'started_at' => now()->toIso8601String(),
            'commit' => $this->commit(),
            'embeddings' => config('ai.default_for_embeddings'),
            'reranker' => config('ai.default_for_reranking'),
            'min_relevance' => config()->float('ai.rag.min_relevance'),
            'max_chunks' => config()->integer('ai.rag.max_chunks'),
            'questions' => [],
        ];

        $this->components->info("Evaluating retrieval for {$questions->count()} questions.");

        $progress = $this->output->createProgressBar($questions->count());
        $progress->setFormat('%current%/%max% [%bar%] %message%');
        $progress->setMessage('');

        foreach ($questions as $question) {
            $this->paceRequests();

            $chunks = $this->retryTransient('Retrieval', fn () => $retrieveRelevantChunks->handle($question->question));

            $results['questions'][] = $this->questionResult($question, $chunks);
            $this->storeResults($path, $results);

            $progress->setMessage($question->id);
            $progress->advance();
        }

        $progress->finish();
        $this->newLine();

        $this->summarize($results['questions']);

        if ($comparison !== null) {
            $this->compare($results['questions'], $comparison);
        }

        $this->components->info('Results: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Chunk>  $chunks
     * @return QuestionResult
     */
    private function questionResult(EvaluationQuestion $question, Collection $chunks): array
    {
        $sources = array_map('mb_strtolower', $question->sourceDocuments());
        $retrievedDocuments = $chunks->toBase()->map(fn (Chunk $chunk): string => mb_strtolower((string) $chunk->document->title))->unique();

        $expectedTerms = $question->isUnanswerable() ? [] : $this->terms((string) preg_replace('/^kriva premisa\s*[–-]?\s*/iu', '', $question->expectedAnswer));
        $passageTerms = $this->terms($chunks->map(fn (Chunk $chunk): string => $chunk->heading.' '.$chunk->content)->implode(' '));
        $missingTerms = array_values(array_diff($expectedTerms, $passageTerms));

        $expectedPassages = $question->isUnanswerable() ? [] : $question->expectedPassages();
        $headings = $chunks->toBase()->map(fn (Chunk $chunk): string => $this->headingName((string) $chunk->heading))->values();
        $missingPassages = array_values(array_filter(
            $expectedPassages,
            fn (array $alternatives): bool => $headings->intersect(array_map($this->headingName(...), $alternatives))->isEmpty(),
        ));
        $expectedNames = collect($expectedPassages)->flatten()->map($this->headingName(...));
        $firstHit = $headings->search(fn (string $heading): bool => $expectedNames->contains($heading));

        return [
            ...$question->toArray(),
            'source_hit' => $sources === [] || $question->isUnanswerable() ? null : $retrievedDocuments->intersect($sources)->isNotEmpty(),
            'coverage' => $expectedTerms === [] ? null : round(1 - count($missingTerms) / count($expectedTerms), 3),
            'missing_terms' => $missingTerms,
            'passage_recall' => $expectedPassages === [] ? null : round(1 - count($missingPassages) / count($expectedPassages), 3),
            'reciprocal_rank' => $expectedPassages === [] ? null : ($firstHit === false ? 0.0 : round(1 / ($firstHit + 1), 3)),
            'missing_passages' => array_map(fn (array $alternatives): string => implode('|', $alternatives), $missingPassages),
            'chunks' => array_values($chunks->map(fn (Chunk $chunk): array => [
                'id' => $chunk->id,
                'document' => $chunk->document->title,
                'heading' => $chunk->heading,
                'relevance' => is_numeric($chunk->getAttribute('relevance')) ? round((float) $chunk->getAttribute('relevance'), 4) : null,
            ])->all()),
        ];
    }

    /**
     * The last part of a heading path, without Markdown emphasis, e.g. "Činjenice o gliomu > **Dijagnoza **"
     * becomes "dijagnoza", so the test set can name a passage by its own title.
     */
    private function headingName(string $heading): string
    {
        return mb_strtolower(trim(str_replace('*', '', (string) Str::afterLast($heading, '>'))));
    }

    /**
     * Word stems (the first five letters, so different Croatian word forms match) and numbers.
     * Numbers written as words are turned into digits first, because the documents often spell
     * out what the test set writes as digits ("prvi, osmi i petnaesti dan" for "1., 8. i 15. dan").
     *
     * @return list<string>
     */
    private function terms(string $text): array
    {
        $text = mb_strtolower($text);

        foreach (self::NUMBER_WORDS as $number => $pattern) {
            $text = (string) preg_replace('/\b(?:'.$pattern.')\b/u', (string) $number, $text);
        }

        preg_match_all('/\p{L}{5,}|\d+(?:[.,]\d+)?/u', $text, $matches);

        return array_values(array_unique(array_map(fn (string $term): string => mb_substr($term, 0, 5), $matches[0])));
    }

    /**
     * @param  list<QuestionResult>  $results
     */
    private function summarize(array $results): void
    {
        $questions = collect($results);
        $answerable = $questions->reject(fn (array $question): bool => $question['category'] === EvaluationQuestion::UNANSWERABLE);
        $withSource = $questions->whereNotNull('source_hit');
        $withCoverage = $questions->whereNotNull('coverage');
        $withPassages = $questions->whereNotNull('passage_recall');

        $this->newLine();
        $this->components->twoColumnDetail('Pogođen izvor', $withSource->where('source_hit', true)->count().'/'.$withSource->count());
        $this->components->twoColumnDetail('Pokrivenost odgovora (prosjek)', $this->percent((float) $withCoverage->avg('coverage')));
        $this->components->twoColumnDetail('Pokrivenost ≥ '.$this->percent(self::COVERED), $withCoverage->where('coverage', '>=', self::COVERED)->count().'/'.$withCoverage->count());
        $this->components->twoColumnDetail('Recall odlomaka (prosjek)', $this->percent((float) $withPassages->avg('passage_recall')));
        $this->components->twoColumnDetail('MRR', number_format((float) $withPassages->avg('reciprocal_rank'), 3, ','));
        $this->components->twoColumnDetail('Nedostaju očekivani odlomci', $this->questionIds($withPassages->where('passage_recall', '<', 1)));
        $this->components->twoColumnDetail('Bez odlomaka, a odgovor postoji', $this->questionIds($answerable->filter(fn (array $question): bool => $question['chunks'] === [])));
        $this->components->twoColumnDetail('Promašen izvor', $this->questionIds($withSource->where('source_hit', false)));
        $this->components->twoColumnDetail('Neodgovorivo, a ima odlomaka', $this->questionIds($questions->filter(
            fn (array $question): bool => $question['category'] === EvaluationQuestion::UNANSWERABLE && $question['chunks'] !== [],
        )));

        $this->newLine();
        $this->table(
            ['Kategorija', 'Pitanja', 'Pogođen izvor', 'Pokrivenost', 'Recall odlomaka'],
            $questions->groupBy('category')->map(fn (Collection $group, string $category): array => [
                $category,
                $group->count(),
                $group->whereNotNull('source_hit')->isEmpty() ? '–' : $group->where('source_hit', true)->count().'/'.$group->whereNotNull('source_hit')->count(),
                $group->whereNotNull('coverage')->isEmpty() ? '–' : $this->percent((float) $group->whereNotNull('coverage')->avg('coverage')),
                $group->whereNotNull('passage_recall')->isEmpty() ? '–' : $this->percent((float) $group->whereNotNull('passage_recall')->avg('passage_recall')),
            ])->values()->all(),
        );
    }

    /**
     * List the questions whose source hit, coverage or passage recall changed since the earlier results.
     *
     * @param  list<QuestionResult>  $questions
     * @param  array{label: string, questions: array<mixed>}  $comparison
     */
    private function compare(array $questions, array $comparison): void
    {
        $before = collect($comparison['questions'])->filter(fn (mixed $question): bool => is_array($question) && is_string($question['id'] ?? null))->keyBy('id');
        $changes = [];

        foreach ($questions as $question) {
            $previous = $before->get($question['id']);

            if (! is_array($previous)) {
                continue;
            }

            $previousCoverage = is_numeric($previous['coverage'] ?? null) ? (float) $previous['coverage'] : null;
            $previousHit = is_bool($previous['source_hit'] ?? null) ? $previous['source_hit'] : null;
            $previousRecall = is_numeric($previous['passage_recall'] ?? null) ? (float) $previous['passage_recall'] : null;
            $coverageChanged = $previousCoverage !== null && $question['coverage'] !== null && abs($question['coverage'] - $previousCoverage) >= self::COVERAGE_CHANGE;
            $recallChanged = $previousRecall !== null && $question['passage_recall'] !== null && $question['passage_recall'] !== $previousRecall;

            if ($coverageChanged || $recallChanged || $previousHit !== $question['source_hit']) {
                $changes[] = [
                    $question['id'],
                    $this->hitLabel($previousHit).' / '.$this->optionalPercent($previousCoverage).' / '.$this->optionalPercent($previousRecall),
                    $this->hitLabel($question['source_hit']).' / '.$this->optionalPercent($question['coverage']).' / '.$this->optionalPercent($question['passage_recall']),
                ];
            }
        }

        $this->newLine();
        $this->components->info("Usporedba s {$comparison['label']} (izvor / pokrivenost / recall odlomaka)");

        if ($changes === []) {
            $this->components->twoColumnDetail('Promjene', 'nema');

            return;
        }

        $this->table(['Pitanje', 'Prije', 'Sad'], $changes);
    }

    private function optionalPercent(?float $share): string
    {
        return $share === null ? '–' : $this->percent($share);
    }

    private function hitLabel(?bool $hit): string
    {
        return match ($hit) {
            true => 'da',
            false => 'ne',
            null => '–',
        };
    }
}
