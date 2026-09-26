<?php

use App\Ai\Agents\Rag;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('thinking mode is disabled for the local model', function () {
    expect((new Rag)->providerOptions('local'))
        ->toMatchArray(['chat_template_kwargs' => ['enable_thinking' => false]]);
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

test('passage headings are shown next to the document title', function () {
    $chunk = (new Chunk)->forceFill(['heading' => 'EVEROLIMUS', 'content' => 'Ne uzimati dodatnu tabletu.']);
    $chunk->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi3.docx']));

    expect((new Rag(new Collection([$chunk])))->instructions())
        ->toContain("[1] AI_lijekovi3.docx — EVEROLIMUS\nNe uzimati dodatnu tabletu.");
});

test('false premises are corrected instead of answered with the not available sentence', function () {
    $instructions = ragWithPassage('Trastuzumab se aplicira kao intravenska infuzija.')->instructions();

    expect($instructions)
        ->toContain('ne odbijaj pitanje: reci da tvrdnja nije točna')
        ->toContain('Ako odlomci odgovaraju samo na dio pitanja, odgovori na taj dio.')
        ->and(strpos($instructions, 'ne odbijaj pitanje'))->toBeLessThan(strpos($instructions, Rag::NOT_AVAILABLE));
});

test('without passages the agent is told to reply with the not available sentence', function () {
    expect((new Rag)->instructions())
        ->toContain('nije pronađen nijedan odlomak')
        ->toContain(Rag::NOT_AVAILABLE)
        ->not->toContain('Odlomci iz baze znanja');
});

test('with passages the rules come after the passages', function () {
    $chunk = (new Chunk)->forceFill(['content' => 'Docetaksel se primjenjuje svaka 3 tjedna.']);
    $chunk->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi.docx']));

    $instructions = (new Rag(new Collection([$chunk])))->instructions();

    expect(strpos($instructions, 'Pravila:'))->toBeGreaterThan(strpos($instructions, 'Docetaksel se primjenjuje'));
});

test('answers are limited in length so a looping model cannot hit the request timeout', function () {
    expect(TextGenerationOptions::forAgent(new Rag)->maxTokens)->toBe(600);
});

test('the temperature comes from the config', function () {
    config(['ai.rag.temperature' => 0.0]);

    expect(TextGenerationOptions::forAgent(new Rag)->temperature)->toBe(0.0);
});

test('the local model gets a repeat penalty against repetition loops', function () {
    expect((new Rag)->providerOptions('local'))->toHaveKey('repeat_penalty');
});

function ragWithPassage(string $content): Rag
{
    $chunk = (new Chunk)->forceFill(['content' => $content]);
    $chunk->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi4.docx']));

    return new Rag(new Collection([$chunk]));
}

test('an answer taken from the passages is returned as is', function () {
    $agent = ragWithPassage('Trastuzumab se aplicira kao intravenska infuzija ili subkutana injekcija.');

    expect($agent->reply('Trastuzumab se aplicira kao intravenska infuzija. Obratite se liječniku.'))
        ->toBe('Trastuzumab se aplicira kao intravenska infuzija. Obratite se liječniku.');
});

test('the not available sentence after an answer taken from the passages is removed', function (string $text) {
    $agent = ragWithPassage('Trastuzumab se aplicira kao intravenska infuzija ili subkutana injekcija.');

    expect($agent->reply($text))->toBe('Trastuzumab se aplicira kao intravenska infuzija ili subkutana injekcija.');
})->with([
    'full sentence' => 'Trastuzumab se aplicira kao intravenska infuzija ili subkutana injekcija. '.Rag::NOT_AVAILABLE,
    'first half only' => "Trastuzumab se aplicira kao intravenska infuzija ili subkutana injekcija.\nNažalost, ta informacija nije dostupna u bazi znanja.",
]);

test('a made up answer is replaced with no reply', function (string $text) {
    $agent = ragWithPassage('Olaparib se može kombinirati s bevacizumabom kod raka jajnika.');

    expect($agent->reply($text))->toBeNull();
})->with([
    'made up' => 'Bevacizumab uzrokuje krvarenje, hipertenziju, tromboembolije i proteinuriju.',
    'made up and unsure' => 'Bevacizumab uzrokuje krvarenje i hipertenziju. '.Rag::NOT_AVAILABLE,
]);

test('there is no reply when the model says the knowledge base has no answer', function (string $text) {
    expect(ragWithPassage('Docetaksel se primjenjuje svaka 3 tjedna.')->reply($text))->toBeNull();
})->with([
    'not available' => Rag::NOT_AVAILABLE,
    'empty' => '  ',
]);

test('words from the passage heading count as taken from the passages', function () {
    $chunk = (new Chunk)->forceFill(['heading' => 'Kapecitabin', 'content' => 'Česta nuspojava je hand-foot sindrom.']);
    $chunk->setRelation('document', (new Document)->forceFill(['title' => 'AI_lijekovi.docx']));

    expect((new Rag(new Collection([$chunk])))->reply('Kapecitabin: česta nuspojava je hand-foot sindrom.'))
        ->toBe('Kapecitabin: česta nuspojava je hand-foot sindrom.');
});

test('greetings are not checked against passages', function () {
    expect((new Rag)->reply('Pozdrav! Rado ću pomoći.'))->toBe('Pozdrav! Rado ću pomoći.');
});
