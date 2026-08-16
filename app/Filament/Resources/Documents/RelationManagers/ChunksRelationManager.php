<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Filament\Resources\Chunks\ChunkResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChunksRelationManager extends RelationManager
{
    protected static string $relationship = 'chunks';

    protected static ?string $relatedResource = ChunkResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->columns([
                TextColumn::make('chunk_index')->sortable(),
                TextColumn::make('content')->limit(50),
            ])
            ->headerActions([]) // no "create" button
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
