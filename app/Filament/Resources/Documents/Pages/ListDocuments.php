<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Bus;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

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
                        ]);

                        $document->addMedia($file)
                            ->usingFileName($file->getClientOriginalName())
                            ->toMediaCollection('documents');

                        Bus::chain([
                            new ProcessDocument($document->id),
                            new ChunkDocument($document->id),
                            new EmbedChunks($document->id),
                        ])->dispatch();
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
