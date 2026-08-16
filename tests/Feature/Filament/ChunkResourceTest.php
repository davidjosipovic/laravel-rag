<?php

use App\Filament\Resources\Chunks\ChunkResource;
use App\Filament\Resources\Chunks\Pages\ListChunks;
use App\Models\Chunk;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('chunk list page can be rendered and shows records', function () {
    $chunks = Chunk::factory()->count(3)->create();

    Livewire::test(ListChunks::class)
        ->assertOk()
        ->assertCanSeeTableRecords($chunks);
});

test('chunks cannot be edited or deleted', function () {
    $chunk = Chunk::factory()->create();

    expect(ChunkResource::canCreate())->toBeFalse()
        ->and(ChunkResource::canEdit($chunk))->toBeFalse()
        ->and(ChunkResource::canDelete($chunk))->toBeFalse();
});
