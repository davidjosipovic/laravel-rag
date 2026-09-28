<?php

namespace App\Ai;

/**
 * A question from the evaluation test set with the answer the system is expected to give.
 */
readonly class EvaluationQuestion
{
    /**
     * The category of questions the documents don't answer; the system should refuse them.
     */
    public const string UNANSWERABLE = 'neodgovorivo';

    public function __construct(
        public string $id,
        public string $category,
        public string $question,
        public string $expectedAnswer,
        public string $source,
    ) {}

    public function isUnanswerable(): bool
    {
        return $this->category === self::UNANSWERABLE;
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

    /**
     * The question as stored at the start of every result in the evaluation results files.
     *
     * @return array{id: string, category: string, question: string, expected_answer: string, source: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'question' => $this->question,
            'expected_answer' => $this->expectedAnswer,
            'source' => $this->source,
        ];
    }
}
