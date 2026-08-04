<?php

namespace App\Services;

class TextChunker
{
    /**
     * Split text into overlapping chunks.
     */
    public static function chunk(string $text, int $chunkSize = 1000, int $overlap = 200): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;
        while ($start < $length) {
            $piece = trim(mb_substr($text, $start, $chunkSize));
            if ($piece !== '') {
                $chunks[] = $piece;
            }
            $start += $chunkSize - $overlap;
        }

        return $chunks;
    }
}
