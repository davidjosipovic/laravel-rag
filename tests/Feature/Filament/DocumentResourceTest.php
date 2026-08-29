<?php

use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Jobs\ChunkDocument;
use App\Jobs\EmbedChunks;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('document list page can be rendered and shows records', function () {
    $documents = Document::factory()->count(3)->create();

    Livewire::test(ListDocuments::class)
        ->assertOk()
        ->assertCanSeeTableRecords($documents);
});

test('a document can be created and queues processing jobs', function () {
    Bus::fake();

    Livewire::test(CreateDocument::class)
        ->fillForm([
            'source_path' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = Document::sole();

    expect($document->getFirstMedia('documents'))->not->toBeNull();

    Bus::assertChained([
        ProcessDocument::class,
        ChunkDocument::class,
        EmbedChunks::class,
    ]);
});

test('bulk uploading files creates a document per file and queues processing', function () {
    Bus::fake();

    Livewire::test(ListDocuments::class)
        ->callAction('bulkUpload', data: [
            'files' => [
                UploadedFile::fake()->create('one.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'),
            ],
        ])
        ->assertNotified();

    expect(Document::count())->toBe(2);

    Bus::assertDispatchedTimes(ProcessDocument::class, 2);
    Bus::assertChained([
        ProcessDocument::class,
        ChunkDocument::class,
        EmbedChunks::class,
    ]);
});

test('a document can be deleted from the edit page', function () {
    $document = Document::factory()->create();

    Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
        ->callAction(DeleteAction::class);

    assertDatabaseMissing('documents', ['id' => $document->id]);
});

test('documents can be bulk deleted from the list page', function () {
    $documents = Document::factory()->count(3)->create();

    Livewire::test(ListDocuments::class)
        ->assertCanSeeTableRecords($documents)
        ->selectTableRecords($documents)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanNotSeeTableRecords($documents);

    $documents->each(fn (Document $document) => assertDatabaseMissing('documents', ['id' => $document->id]));
});
