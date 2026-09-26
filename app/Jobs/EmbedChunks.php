<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
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
    public int $tries = 2;
    // public int $timeout = 3600;
    // public int $maxExceptions = 3;

    public function __construct(public int $documentId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => DocumentStatus::Embedding]);
        $processed = 0;

        Chunk::whereNull('embedding')
            ->where('document_id', $this->documentId)
            ->chunkById(
                10,
                function ($chunks) use (&$processed) {
                    $inputs = $chunks->map(
                        fn (Chunk $chunk) => $chunk->heading
                            ? $chunk->heading."\n\n".$chunk->content
                            : $chunk->content
                    )->all();

                    $vectors = Embeddings::for($inputs)->generate()->embeddings;

                    foreach ($chunks->values() as $i => $chunk) {
                        $chunk->update(['embedding' => $vectors[$i]]);
                    }

                    $processed += $chunks->count();
                }
            );
        if ($processed === 0) {
            throw new RuntimeException("No chunks to embed for document {$this->documentId}.");
        }
        $document->update(['status' => DocumentStatus::Embedded]);
    }
}
