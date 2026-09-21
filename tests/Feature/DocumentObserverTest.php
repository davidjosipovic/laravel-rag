<?php

use App\Enums\DocumentStatus;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Support\Facades\Bus;

test('setting status to uploaded dispatches ProcessDocument', function () {
    Bus::fake();

    $document = Document::factory()->create(['status' => DocumentStatus::Pending]);
    $document->update(['status' => DocumentStatus::Uploaded]);

    Bus::assertDispatched(ProcessDocument::class, fn (ProcessDocument $job) => $job->documentId === $document->id);
    Bus::assertNotDispatched(ChunkDocument::class);
    Bus::assertNotDispatched(EmbedChunks::class);
});

test('setting status to extracted dispatches ChunkDocument', function () {
    Bus::fake();

    $document = Document::factory()->create(['status' => DocumentStatus::Uploaded]);
    $document->update(['status' => DocumentStatus::Extracted]);

    Bus::assertDispatched(ChunkDocument::class, fn (ChunkDocument $job) => $job->documentId === $document->id);
    Bus::assertNotDispatched(ProcessDocument::class);
    Bus::assertNotDispatched(EmbedChunks::class);
});

test('setting status to chunked dispatches EmbedChunks', function () {
    Bus::fake();

    $document = Document::factory()->create(['status' => DocumentStatus::Extracted]);
    $document->update(['status' => DocumentStatus::Chunked]);

    Bus::assertDispatched(EmbedChunks::class, fn (EmbedChunks $job) => $job->documentId === $document->id);
    Bus::assertNotDispatched(ProcessDocument::class);
    Bus::assertNotDispatched(ChunkDocument::class);
});

test('updating an unrelated attribute does not dispatch any job', function () {
    Bus::fake();

    $document = Document::factory()->create(['status' => DocumentStatus::Uploaded]);
    $document->update(['title' => 'Renamed']);

    Bus::assertNothingDispatched();
});
