<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
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
}
