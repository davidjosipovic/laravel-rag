<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Jobs\ProcessDocument;
use App\Filament\Resources\Documents\DocumentResource;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;

use App\Models\Document;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Bus;


class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function afterCreate(): void
    {
        $this->record->load('media');
        $media = $this->record->getFirstMedia('documents');

        if (!$media) {
            return;
        }
       # dd(Document::getFirstMedia('document')->getPath());

        Bus::chain([
            new ProcessDocument($this->record->id),
            new ChunkDocument($this->record->id),
            new EmbedChunks($this->record->id),
        ])->dispatch();


    }
}
