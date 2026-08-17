<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Bus;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

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
