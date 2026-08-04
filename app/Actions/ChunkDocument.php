<?php

namespace App\Actions;

use App\Models\Chunk;

class ChunkDocument
{
    /**
     * Split text into overlapping chunks.
     */
    public function handle($record): void
    {
        $chunks =  (new TextChunker())->chunk($record->content);

        foreach ($chunks as $index => $text) {
            Chunk::create([
                'document_id' => $record->id,
                'chunk_index' => $index,
                'content' => $text,
            ]);
        }

    }
}
