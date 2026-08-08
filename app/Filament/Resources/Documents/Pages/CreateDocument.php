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

    protected function afterCreate(): void
    {
        $this->record->load('media');
        $path=$this->record->getFirstMedia('documents')->getPath();
        if ($path){
            $this->record->update((new ExtractPdfData())->handle($path));
            (new ChunkDocument)->handle($this->record->refresh());
        }
        

    }
}
