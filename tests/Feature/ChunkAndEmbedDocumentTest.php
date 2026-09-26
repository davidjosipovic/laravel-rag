<?php

use App\Actions\TextChunker;
use App\Enums\DocumentStatus;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Embeddings;

test('chunking a document stores each chunk with its heading', function () {
    Bus::fake();

    $document = Document::factory()->create([
        'status' => DocumentStatus::Extracted,
        'content' => "**Docetaksel**\nNalazi najviše 72 sata prije terapije.\n**Paklitaksel**\nPrimjenjuje se jednom tjedno.",
    ]);

    (new ChunkDocument($document->id))->handle(new TextChunker);

    expect($document->chunks()->orderBy('chunk_index')->get(['heading', 'content'])->toArray())->toBe([
        ['heading' => 'Docetaksel', 'content' => 'Nalazi najviše 72 sata prije terapije.'],
        ['heading' => 'Paklitaksel', 'content' => 'Primjenjuje se jednom tjedno.'],
    ])->and($document->fresh()->status)->toBe(DocumentStatus::Chunked);
});

test('embedding prepends the heading to the chunk content', function () {
    Bus::fake();
    Embeddings::fake(fn ($prompt) => array_map(fn () => array_fill(0, 1024, 0.1), $prompt->inputs));

    $document = Document::factory()->create(['status' => DocumentStatus::Chunked]);
    Chunk::factory()->for($document)->create(['heading' => 'Docetaksel', 'content' => 'Nalazi prije terapije.', 'embedding' => null]);
    Chunk::factory()->for($document)->create(['heading' => null, 'content' => 'Tekst bez naslova.', 'embedding' => null]);

    (new EmbedChunks($document->id))->handle();

    Embeddings::assertGenerated(fn ($prompt) => $prompt->inputs === ["Docetaksel\n\nNalazi prije terapije.", 'Tekst bez naslova.']);
    expect($document->chunks()->whereNull('embedding')->count())->toBe(0)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Embedded);
});
