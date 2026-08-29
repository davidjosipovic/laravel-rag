<?php

namespace App\Actions\HybridSearch;

use App\Models\Chunk;

class FullTextSearch
{
    public function handle(string $query, int $limit)
    {
        return Chunk::whereFullText('content',$query)->limit($limit)->pluck('id')->all();
    }
}