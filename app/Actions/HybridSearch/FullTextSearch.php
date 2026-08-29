<?php

namespace App\Actions\HybridSearch;

use App\Models\Chunk;

class FullTextSearch
{
    /**
     * Summary of handle
     *
     * @return int[]
     */
    public function handle(string $query, int $limit): array
    {
        return Chunk::whereFullText('content', $query)->limit($limit)->pluck('id')->all();
    }
}
