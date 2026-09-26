<?php

namespace App\Ai;

/**
 * A question from the evaluation test set with the answer the system is expected to give.
 */
readonly class EvaluationQuestion
{
    public function __construct(
        public string $id,
        public string $category,
        public string $question,
        public string $expectedAnswer,
        public string $source,
    ) {}

    public function isUnanswerable(): bool
    {
        return $this->category === 'neodgovorivo';
    }

    /**
     * The titles of the documents that contain the answer; the test set separates them with semicolons.
     *
     * @return list<string>
     */
    public function sourceDocuments(): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $this->source))));
    }
}
