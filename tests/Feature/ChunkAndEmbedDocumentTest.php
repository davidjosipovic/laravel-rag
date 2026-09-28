<?php

use App\Enums\DocumentStatus;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\DoclingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

test('chunking a document stores each docling chunk with its heading path', function () {
    Bus::fake();
    Http::fake([
        '*/v1/chunk/hybrid/file' => Http::response([
            'chunks' => [
                ['text' => "Lijekovi\nDocetaksel\nNalazi 72 sata prije.", 'raw_text' => 'Nalazi 72 sata prije.', 'headings' => ['Lijekovi', 'Docetaksel']],
                ['text' => 'Uvodni tekst.', 'raw_text' => 'Uvodni tekst.', 'headings' => null],
            ],
            'documents' => [],
            'processing_time' => 0.1,
        ]),
    ]);

    $document = Document::factory()->create([
        'status' => DocumentStatus::Extracted,
        'content' => "# Lijekovi\n## Docetaksel\nNalazi 72 sata prije.",
    ]);

    (new ChunkDocument($document->id))->handle(app(DoclingService::class));

    expect($document->chunks()->orderBy('chunk_index')->get(['heading', 'content'])->toArray())->toBe([
        ['heading' => 'Lijekovi > Docetaksel', 'content' => 'Nalazi 72 sata prije.'],
        ['heading' => null, 'content' => 'Uvodni tekst.'],
    ])->and($document->fresh()->status)->toBe(DocumentStatus::Chunked);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/chunk/hybrid/file')
        && collect($request->data())->pluck('contents', 'name')->only(['chunking_tokenizer', 'chunking_max_tokens'])->all()
            === ['chunking_tokenizer' => 'Qwen/Qwen3-Embedding-0.6B', 'chunking_max_tokens' => '600']);
});

test('bold and uppercase lines are sent to docling as nested markdown headings', function () {
    Bus::fake();
    Http::fake(['*/v1/chunk/hybrid/file' => Http::response(['chunks' => [], 'documents' => [], 'processing_time' => 0.1])]);

    $document = Document::factory()->create([
        'status' => DocumentStatus::Extracted,
        'content' => "# Lijekovi\n**Dabrafenib****/****trametinib**\nCiljana terapija.\nLIPOSOMALNI DOKSORUBICIN\nTekst.\n**Važno je javiti se liječniku.**\n**Neuropatija - **Oštećenje živaca.",
    ]);

    (new ChunkDocument($document->id))->handle(app(DoclingService::class));

    Http::assertSent(fn (Request $request) => collect($request->data())->firstWhere('name', 'files')['contents'] === "# Lijekovi\n## Dabrafenib/trametinib\nCiljana terapija.\n## LIPOSOMALNI DOKSORUBICIN\nTekst.\n**Važno je javiti se liječniku.**\n**Neuropatija - **Oštećenje živaca.");
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
