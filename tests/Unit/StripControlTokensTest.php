<?php

use App\Ai\Middleware\StripControlTokens;

test('leaked control tokens are removed from the answer', function (string $text, string $expected) {
    expect((new StripControlTokens)->strip($text))->toBe($expected);
})->with([
    'single token' => ['<|channel>*   Denosumab se primjenjuje kao potkožna injekcija.', '*   Denosumab se primjenjuje kao potkožna injekcija.'],
    'thought channel' => ['<|channel> <|channel>thought <channel|>U Europi je incidencija viša.', 'U Europi je incidencija viša.'],
    'thought channel with content' => ["<|channel>thought\nThe user asks about doses.<channel|>Doza je 400 mg.", 'Doza je 400 mg.'],
    'nested token' => ['<|channel><|"|>thought <channel|>Nažalost, ta informacija nije dostupna.', 'Nažalost, ta informacija nije dostupna.'],
]);

test('answers without control tokens are left as they are', function () {
    $answer = "Doza imatiniba je 400 mg jednom dnevno.\n\n* uz obrok <i dovoljno tekućine>";

    expect((new StripControlTokens)->strip($answer))->toBe($answer);
});
