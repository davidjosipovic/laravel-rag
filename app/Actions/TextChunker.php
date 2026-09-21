<?php

namespace App\Actions;

class TextChunker
{
    /**
     * Split text into overlapping chunks of N words.
     */
    public function chunk(string $text, int $chunkSize = 200, int $overlap = 40): \Generator
    {
        if ($overlap >= $chunkSize) {
            throw new \InvalidArgumentException('Overlap must be smaller than chunk size.');
        }

        preg_match_all('/\S+\s*/u', $text, $matches);
        $words = $matches[0];
        $total = count($words);

        if ($total === 0) {
            return;
        }

        $step = $chunkSize - $overlap;

        for ($start = 0; $start < $total; $start += $step) {
            yield trim(implode('', array_slice($words, $start, $chunkSize)));

            if ($start + $chunkSize >= $total) {
                break;
            }
        }
    }
}