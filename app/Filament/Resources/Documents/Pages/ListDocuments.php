<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\ChunkDocument;
use App\Actions\ExtractPdfData;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('bulkUpload')
                ->label('Bulk upload PDFs')
                ->form([
                    FileUpload::make('files')
                        ->multiple()
                        ->label('Document file')
                        ->directory('documents')
                        ->disk('local')
                        ->required(),
                ])
                ->action(function (array $data, ExtractPdfData $extractPdfData, ChunkDocument $chunkDocument): void {

                    foreach ($data['files'] as $path) {

                        $data = $extractPdfData->handle($path);
                        $document = Document::create($data);
                        $chunkDocument->handle($document);

                    }
                }),

        ];
    }
}
