<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\ChunkDocument;
use App\Actions\ExtractPdfData;
use App\Filament\Resources\Documents\DocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

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
        $data = (new ExtractPdfData)->handle($data['source_path']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->chunks()->delete(); // remove old chunks first

        (new ChunkDocument)->handle($this->record);

    }
}
