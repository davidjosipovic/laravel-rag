<?php

namespace App\Observers;

use App\Enums\DocumentStatus;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Document $document): void
    {
        if (! $document->wasChanged('status')) {
            return;
        }

        match ($document->status) {
            DocumentStatus::Uploaded => ProcessDocument::dispatch($document->id),
            DocumentStatus::Extracted => ChunkDocument::dispatch($document->id),
            DocumentStatus::Chunked => EmbedChunks::dispatch($document->id),
            default => null,
        };
    }
}
