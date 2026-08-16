<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Embeddings;

class EmbedChunks implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 1;

    public function __construct(public int $documentId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => 'embedding ']);

        Chunk::whereNull('embedding')
            ->where('document_id', $this->documentId)
            ->chunkById(
                100,
                function ($chunks) {
                    $vectors = Embeddings::for($chunks->pluck('content')->all())->generate()->embeddings;
                    foreach ($chunks as $i => $chunk) {
                        $chunk->update(['embedding' => $vectors[$i]]);
                    }
                }
            );
        $document->update(['status' => 'done']);

    }
}
