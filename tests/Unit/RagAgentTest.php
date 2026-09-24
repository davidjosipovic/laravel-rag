<?php

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Enums\Lab;

test('thinking mode is disabled for the local model', function () {
    expect((new Rag)->providerOptions('local'))
        ->toBe(['chat_template_kwargs' => ['enable_thinking' => false]]);
});

test('no provider options are sent to other providers', function () {
    $agent = new Rag;

    expect($agent->providerOptions(Lab::Anthropic))->toBe([])
        ->and($agent->providerOptions('openai'))->toBe([]);
});

test('retrieved passages are included in the instructions', function () {
    $chunk = (new Chunk)->forceFill(['content' => 'Krvne nalaze treba uzorkovati najviše 72 sata prije terapije.']);
    $chunk->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi.docx']));

    $instructions = (new Rag(new Collection([$chunk])))->instructions();

    expect($instructions)
        ->toContain("[1] AI_lijekovi.docx\nKrvne nalaze treba uzorkovati najviše 72 sata prije terapije.");
});

test('the instructions say when nothing relevant was found', function () {
    expect((new Rag)->instructions())->toContain('baza znanja nije pronašla ništa relevantno');
});
