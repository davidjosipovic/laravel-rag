<?php

namespace App\Actions;

class TextChunker
{
    /**
     * Split text into overlapping chunks.
     */
    public function chunk(string $text, int $chunkSize = 1000, int $overlap = 200): \Generator
    {
        $paragraphs = preg_split("/\n\s*\n/", $text);

        if ($paragraphs === false) {
            return;
        }


        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($paragraph) > $chunkSize) {
                yield $buffer;
                $buffer = mb_substr($buffer, -$overlap) . "\n\n" . $paragraph;  // small string
            } else {
                $buffer = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
            }
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }
}
