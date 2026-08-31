<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Embeddings;
use RuntimeException;

class EmbedChunks implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 1;

    public function __construct(public int $documentId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => 'embedding']);
        $processed=0;

        Chunk::whereNull('embedding')
            ->where('document_id', $this->documentId)
            ->chunkById(
                100,
                function ($chunks) use (&$processed) {
                    $vectors = Embeddings::for($chunks->pluck('content')->all())->generate()->embeddings;
                    foreach ($chunks as $i => $chunk) {
                        $chunk->update(['embedding' => $vectors[$i]]);
                    }
                    $processed+=$chunks->count();

                }
            );
        if ($processed===0){
            throw new RuntimeException("No chunks to embed for document {$this->documentId}.");
        }
        $document->update(['status' => 'done']);

    }
}
