<?php

use App\Actions\TextChunker;

/**
 * @return list<?string>
 */
function chunkHeadings(string $text): array
{
    return array_column(iterator_to_array((new TextChunker)->chunk($text), false), 'heading');
}

/**
 * @param  list<array{text: string, heading: ?string}>  $chunks
 * @return list<int>
 */
function chunkWordCounts(array $chunks): array
{
    return array_map(fn (array $chunk): int => count(preg_split('/\s+/u', $chunk['text'])), $chunks);
}

test('markdown headings form a heading path without bold markers', function () {
    $text = "# **Činjenice o gliomu**\nUvod.\n## **Liječenje**\nOperacija.";

    expect(chunkHeadings($text))->toBe(['Činjenice o gliomu', 'Činjenice o gliomu > Liječenje']);
});

test('bold lines are headings', function () {
    $text = "**Docetaksel**\nDocetaksel je lijek.\n**Kapecitabin**** **\nKapecitabin je lijek.\n**Dabrafenib****/****trametinib**\nCiljana terapija.";

    expect(chunkHeadings($text))->toBe(['Docetaksel', 'Kapecitabin', 'Dabrafenib/trametinib']);
});

test('uppercase lines are headings', function () {
    $text = "TRABEKTIDIN\nTrabektedin je lijek.\nLIPOSOMALNI DOKSORUBICIN\nLiposomalni doksorubicin je lijek.";

    expect(chunkHeadings($text))->toBe(['TRABEKTIDIN', 'LIPOSOMALNI DOKSORUBICIN']);
});

test('bold and uppercase headings nest under the current markdown heading', function () {
    $text = "# Lijekovi\n**Docetaksel**\nDocetaksel je lijek.\n**Paklitaksel**\nPaklitaksel je lijek.";

    expect(chunkHeadings($text))->toBe(['Lijekovi > Docetaksel', 'Lijekovi > Paklitaksel']);
});

test('lines that only start with bold text or are sentences are not headings', function () {
    $text = "**Periferna neuropatija - **Oštećenje perifernih živaca.\n**Važno je javiti se liječniku.**\nKKS, DKS.";

    expect(chunkHeadings($text))->toBe([null]);
});

test('chunks respect the word limit and overlap with whole sentences', function () {
    $sentences = array_map(fn (int $i): string => "Rečenica broj {$i} ima šest riječi.", range(1, 30));

    $chunks = iterator_to_array((new TextChunker)->chunk(implode(' ', $sentences), maxWords: 30, overlapWords: 12), false);

    expect($chunks)->toHaveCount(10)
        ->and(chunkWordCounts($chunks))->each->toBeLessThanOrEqual(30)
        ->and($chunks[0]['text'])->toEndWith('Rečenica broj 5 ima šest riječi.')
        ->and($chunks[1]['text'])->toStartWith('Rečenica broj 4 ima šest riječi.')
        ->and(end($chunks)['text'])->toEndWith('Rečenica broj 30 ima šest riječi.');
});

test('a sentence longer than the limit is split into word windows', function () {
    $chunks = iterator_to_array((new TextChunker)->chunk(str_repeat('riječ ', 25), maxWords: 10, overlapWords: 0), false);

    expect(chunkWordCounts($chunks))->toBe([10, 10, 5]);
});

test('chunk size and overlap are validated', function (int $maxWords, int $overlapWords) {
    iterator_to_array((new TextChunker)->chunk('Tekst.', $maxWords, $overlapWords));
})->throws(InvalidArgumentException::class)->with([
    'overlap equal to size' => [10, 10],
    'zero size' => [0, -1],
]);

test('invalid UTF-8 fails instead of silently dropping text', function () {
    iterator_to_array((new TextChunker)->chunk("Tekst \xC3\x28 lijeka."));
})->throws(RuntimeException::class);
