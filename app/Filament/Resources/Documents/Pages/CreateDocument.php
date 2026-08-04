<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\ChunkDocument;
use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        $disk = Storage::disk('local');
        $full_path = $disk->path($data['source_path']);

        $parser = new Parser;
        $pdf = $parser->parseFile($full_path);
        $data['content'] = $pdf->getText();
        $data['metadata'] = $pdf->getDetails();

        $firstLine = trim($data['content']) !== '' ? explode("\n", trim($data['content']))[0] : null;

        $data['title'] = trim($data['metadata']['Title'] ?? '')
            ?: $firstLine
            ?: 'Untitled document';
        $data['mime_type'] = Storage::mimeType($data['source_path']);

        return $data;
    }

    protected function afterCreate(): void
    {
        (new ChunkDocument)->handle($this->record);

    }
}
