<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Bus;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;

        if (! $record instanceof Document) {
            return;
        }

        $record->load('media');
        $media = $record->getFirstMedia('documents');

        if (! $media) {
            return;
        }

        Bus::chain([
            new ProcessDocument($record->id),
            new ChunkDocument($record->id),
            new EmbedChunks($record->id),
        ])->dispatch();

    }
}
