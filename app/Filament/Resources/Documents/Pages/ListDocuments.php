<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [

            Action::make('bulkUpload')
                ->label('Bulk upload')
                ->schema([
                    FileUpload::make('files')
                        ->label('Document files')
                        ->multiple()
                        ->storeFiles(false)
                        ->required()
                        ->maxSize(204800),
                ])
                ->action(function (array $data): void {
                    /** @var TemporaryUploadedFile[] $files */
                    $files = $data['files'];

                    foreach ($files as $file) {
                        $document = Document::create([
                            'title' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),

                        ]);

                        $document->addMedia($file)
                            ->usingFileName($file->getClientOriginalName())
                            ->toMediaCollection('documents');
                        $document->update(['status' => DocumentStatus::Uploaded]);

                    }

                    Notification::make()
                        ->title('Documents uploaded')
                        ->body(count($files).' document(s) queued for processing.')
                        ->success()
                        ->send();
                }),

        ];
    }
}
