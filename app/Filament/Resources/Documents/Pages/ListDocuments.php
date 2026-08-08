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
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

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
                        ->label('Document files')
                        ->multiple()
                        ->disk('local')
                        ->directory('bulk-staging')
                        ->acceptedFileTypes(['application/pdf'])
                        ->required(),
                ])
                ->action(function (array $data, ExtractPdfData $extractPdfData, ChunkDocument $chunkDocument): void {
                    $succeeded = 0;
                    $failed = [];

                    foreach ($data['files'] as $path) {
                        try {
                            $fullPath = Storage::disk('local')->path($path);

                            $document = Document::create($extractPdfData->handle($fullPath));

                            $document->addMediaFromDisk($path, 'local')
                                ->toMediaCollection('documents');

                            $chunkDocument->handle($document);
                            $succeeded++;
                        } catch (\Throwable $e) {
                            report($e);
                            $failed[] = basename($path);
                            Storage::disk('local')->delete($path);
                        }
                    }

                    Notification::make()
                                ->title("Imported {$succeeded} document(s)")
                                ->body($failed ? 'Failed: ' . implode(', ', $failed) : null)
                        ->{$failed ? 'warning' : 'success'}()
                            ->send();
                }),

        ];
    }
}
