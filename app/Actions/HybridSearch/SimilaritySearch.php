<?php

namespace App\Actions\HybridSearch;

use App\Models\Chunk;

class SimilaritySearch
{
    /**
     * Summary of handle
     *
     * @return int[]
     */
    public function handle(string $query, int $limit): array
    {
        return Chunk::whereVectorSimilarTo('embedding', $query, minSimilarity: 0.5)->limit($limit)->pluck('id')->all();
    }
}
