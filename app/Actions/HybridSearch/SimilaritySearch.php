<?php

namespace App\Actions\HybridSearch;

use App\Models\Chunk;

class SimilaritySearch
{
    public function handle(string $query, int $limit)
    {
        return Chunk::whereVectorSimilarTo('embedding',$query, minSimilarity:0.5)->limit($limit)->pluck('id')->all();
    }
}
