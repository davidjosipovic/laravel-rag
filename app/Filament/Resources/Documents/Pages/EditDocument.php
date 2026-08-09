<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Jobs\ChunkDocument;
use App\Filament\Resources\Documents\DocumentResource;
use App\Jobs\EmbedChunks;
use App\Jobs\ProcessDocument;
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
        $this->record->load('media');
        $media = $this->record->getFirstMedia('documents');

        if (!$media) {
            return;
        }

        Bus::chain([
            new ProcessDocument($this->record->id),
            new ChunkDocument($this->record->id),
            new EmbedChunks($this->record->id),
        ])->dispatch();


    }
}
