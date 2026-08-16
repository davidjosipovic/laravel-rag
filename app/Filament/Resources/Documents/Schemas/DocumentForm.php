<?php

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Schema;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpatieMediaLibraryFileUpload::make('source_path')
                    ->label('Document file')
                    ->collection('documents')
                    ->required()
                    ->maxSize(204800),
            ]);
    }
}
