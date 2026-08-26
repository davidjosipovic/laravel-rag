<?php

namespace App\Jobs;

use App\Actions\TextChunker;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ChunkDocument implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $documentId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(TextChunker $chunker): void
    {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => 'chunking']);

        $chunks = $chunker->chunk($document->content);

        foreach ($chunks as $index => $text) {
            Chunk::create([
                'document_id' => $this->documentId,
                'chunk_index' => $index,
                'content' => $text,
            ]);
        }

    }
}
