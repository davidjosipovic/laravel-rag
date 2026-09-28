<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\DoclingService;
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
    public function handle(DoclingService $docling): void
    {
        $document = Document::findOrFail($this->documentId);
        Chunk::where('document_id', $this->documentId)->delete();
        $document->update(['status' => DocumentStatus::Chunking]);

        foreach ($docling->chunk($document->content) as $index => $chunk) {
            Chunk::create([
                'document_id' => $this->documentId,
                'chunk_index' => $index,
                'content' => $chunk['text'],
                'heading' => $chunk['heading'],
            ]);
        }

        $document->update(['status' => DocumentStatus::Chunked]);
    }
}
