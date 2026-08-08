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


    protected function afterSave(): void
    {
        $this->record->load('media');
        $path=$this->record->getFirstMedia('documents')->getPath();
        if ($path){
            $this->record->update((new ExtractPdfData())->handle($path));
            (new ChunkDocument)->handle($this->record->refresh());
        }
        

    }
}
