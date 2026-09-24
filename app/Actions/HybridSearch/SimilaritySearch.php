<?php

namespace App\Actions\HybridSearch;

use App\Models\Chunk;
use Illuminate\Support\Str;

class SimilaritySearch
{
    /**
     * Qwen3-Embedding is trained to embed search queries with a task instruction in front of them.
     */
    private const QUERY_INSTRUCTION = "Instruct: Given a question, retrieve passages that answer the question\nQuery: ";

    /**
     * Find the chunks most semantically similar to the query, best matches first.
     *
     * The similarity threshold is kept low on purpose: the results are fused with full-text
     * search and reranked afterwards, which is where irrelevant chunks get filtered out.
     *
     * @return int[]
     */
    public function handle(string $query, int $limit): array
    {
        $embedding = Str::of(self::QUERY_INSTRUCTION.$query)->toEmbeddings(cache: true);

        return Chunk::whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.3)
            ->limit($limit)
            ->pluck('id')
            ->all();
    }
}
