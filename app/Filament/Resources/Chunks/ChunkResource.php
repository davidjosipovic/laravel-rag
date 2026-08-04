<?php

namespace App\Filament\Resources\Chunks;

use App\Filament\Resources\Chunks\Pages\CreateChunk;
use App\Filament\Resources\Chunks\Pages\EditChunk;
use App\Filament\Resources\Chunks\Pages\ListChunks;
use App\Filament\Resources\Chunks\Schemas\ChunkForm;
use App\Filament\Resources\Chunks\Schemas\ChunkInfolist;
use App\Filament\Resources\Chunks\Tables\ChunksTable;
use App\Models\Chunk;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ChunkResource extends Resource
{
    protected static ?string $model = Chunk::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChartPie;

    protected static ?string $recordTitleAttribute = 'chunk_index';


    public static function table(Table $table): Table
    {
        return ChunksTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
{
    return ChunkInfolist::configure($schema);
}

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChunks::route('/')
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
