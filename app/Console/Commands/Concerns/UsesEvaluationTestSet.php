<?php

namespace App\Console\Commands\Concerns;

use App\Ai\EvaluationQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Shared by the evaluation commands: reading the test set, pacing requests for rate limited
 * providers, retrying transient failures and storing results.
 *
 * The commands using it define the --questions, --only, --label and --delay options.
 */
trait UsesEvaluationTestSet
{
    private const int MAX_ATTEMPTS = 3;

    private ?float $lastRequestAt = null;

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

        for ($line = 2; ($row = fgetcsv($handle, escape: '')) !== false; $line++) {
            if ($row === [null]) {
                continue;
            }

            if (count($row) !== count($header)) {
                fclose($handle);

                throw new RuntimeException("Line {$line} of {$path} has ".count($row).' columns instead of '.count($header).'; is a value with a comma missing its quotes?');
            }

            $values = array_combine($header, array_map(fn (?string $value): string => (string) $value, $row));

            $questions->push(new EvaluationQuestion(
                id: $values['id'],
                category: $values['kategorija'],
                question: $values['pitanje'],
                expectedAnswer: $values['ocekivani_odgovor'],
                source: $values['izvor'] ?? '',
                expectedPassages: $values['odlomci'] ?? '',
            ));
        }

        fclose($handle);

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        return $only === [] ? $questions : $questions->filter(fn (EvaluationQuestion $question): bool => in_array($question->id, $only, true))->values();
    }

    /**
     * Wait until --delay seconds have passed since the previous request; the Cohere trial key
     * allows 10 reranks per minute.
     */
    private function paceRequests(): void
    {
        if ($this->lastRequestAt !== null) {
            Sleep::for(max(0, (float) $this->option('delay') - (microtime(true) - $this->lastRequestAt)))->seconds();
        }

        $this->lastRequestAt = microtime(true);
    }

    /**
     * Run the callback, retrying rate limits and timeouts after a pause. When they persist (e.g. the
     * reranker's monthly quota is used up) the evaluation stops; other errors are rethrown right away.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function retryTransient(string $what, callable $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (Throwable $exception) {
                if (! $this->isTransient($exception)) {
                    throw $exception;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException("{$what} is not available: ".$exception->getMessage(), previous: $exception);
                }

                Sleep::for(61)->seconds();
            }
        }
    }

    private function isTransient(Throwable $exception): bool
    {
        return str($exception->getMessage())->lower()->contains(['rate limit', 'too many requests', '429', 'overloaded', 'unavailable', 'curl error 28', 'timed out']);
    }

    private function resultsPath(string $directory): string
    {
        return $directory.'/'.now()->format('Y-m-d_His').($this->option('label') ? '_'.str($this->option('label'))->slug() : '').'.json';
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function storeResults(string $path, array $results): void
    {
        Storage::disk('local')->put($path, (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Load a stored results file: a path, a file name in the directory, or "latest" for the newest one.
     *
     * @return array{label: string, questions: array<mixed>}
     */
    private function loadResults(string $directory, string $name): array
    {
        $disk = Storage::disk('local');

        $path = $name === 'latest'
            ? collect($disk->files($directory))->filter(fn (string $file): bool => str_ends_with($file, '.json'))->sort()->last()
            : (str_contains($name, '/') ? $name : $directory.'/'.$name);

        if ($path === null || ! $disk->exists($path)) {
            throw new RuntimeException("Results to compare with were not found: {$name}.");
        }

        $data = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! is_array($data['questions'] ?? null)) {
            throw new RuntimeException("{$path} is not an evaluation results file.");
        }

        return [
            'label' => is_string($data['label'] ?? null) ? $data['label'] : basename($path),
            'questions' => $data['questions'],
        ];
    }

    /**
     * The current commit, with a "-dirty" suffix when there are uncommitted changes, so runs of
     * different uncommitted code can't be mistaken for the same code.
     */
    private function commit(): ?string
    {
        $result = Process::run('git describe --always --dirty');

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * The number of questions followed by their ids.
     *
     * @param  iterable<array{id: string}>  $questions
     */
    private function questionIds(iterable $questions): string
    {
        $ids = collect($questions)->pluck('id');

        return $ids->count().($ids->isNotEmpty() ? ' ('.$ids->implode(', ').')' : '');
    }

    private function percent(float $share): string
    {
        return number_format($share * 100, 1, ',').' %';
    }
}
