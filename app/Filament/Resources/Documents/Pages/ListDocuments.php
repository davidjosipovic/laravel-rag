<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('bulkUpload')
                ->label('Bulk upload PDFs')
                ->form([FileUpload::make('files')
                    ->multiple()
                    ->label('Document file')
                    ->directory('documents')
                    ->disk('local')
                    ->required(), ])
                ->action(function (array $data): void {
                    $disk = Storage::disk('local');
                    $parser = new Parser;

                    foreach ($data['files'] as $path) {

                        $fullpath = $disk->path($path);
                        $pdf = $parser->parseFile($fullpath);

                        $content=$pdf->getText();
                        $metadata=$pdf->getDetails();

                        $firstLine = trim($content) !== ''
                        ? explode("\n", trim($content))[0]
                        : null;

                        $title = trim($metadata['Title'] ?? '')
                            ?: $firstLine
                            ?: 'Untitled document';

                        Document::create([
                            'title' => $title,
                            'source_path' => $path,
                            'content' => $content,
                            'metadata' => $metadata,
                            'mime_type' => Storage::mimeType($path),
                        ]);

                    }
                }),

        ];
    }
    


}
