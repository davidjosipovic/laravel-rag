<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\ChunkDocument;
use App\Actions\ExtractPdfData;
use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        $data = (new ExtractPdfData())->handle($data['source_path']);

        return $data;
    }

    protected function afterCreate(): void
    {
        (new ChunkDocument)->handle($this->record);

    }
}
