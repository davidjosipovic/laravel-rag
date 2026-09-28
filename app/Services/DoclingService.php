<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DoclingService
{
    /**
     * @return array{document: array<string, mixed>, status: string, processing_time: float, errors: array<int, mixed>}
     *
     * @throws RuntimeException If the file cannot be opened.
     */
    public function convert(string $path): array
    {
        $file = @fopen($path, 'r');

        if ($file === false) {
            throw new RuntimeException("Unable to open file [{$path}] for conversion.");
        }

        $response = Http::timeout(600)
            ->attach('files', $file, basename($path))
            ->post(config('services.docling.url').'/v1/convert/file', [
                'to_formats' => 'md',
                'do_ocr' => 'true',
                'table_mode' => 'accurate',
                'image_export_mode' => 'placeholder',
            ])
            ->throw();

        return $response->json();
    }

    /**
     * Chunk markdown with Docling's HybridChunker. Chunks never cross a heading
     * boundary and stay under the tokenizer's token limit.
     *
     * @return list<array{text: string, heading: ?string}>
     */
    public function chunk(string $markdown): array
    {
        $response = Http::timeout(600)
            ->attach('files', $this->promotePseudoHeadings($markdown), 'document.md')
            ->post(config('services.docling.url').'/v1/chunk/hybrid/file', [
                'chunking_include_raw_text' => 'true',
                'chunking_use_markdown_tables' => 'true',
            ])
            ->throw();

        return array_values(array_map($this->toChunk(...), $response->json('chunks')));
    }

    /**
     * Word documents often mark section titles (e.g. drug names) with a short line
     * that is entirely bold or entirely uppercase. Turn those into markdown headings
     * one level below the current one so Docling's chunker splits on them.
     */
    private function promotePseudoHeadings(string $markdown): string
    {
        $depth = 0;

        return implode("\n", array_map(function (string $line) use (&$depth): string {
            $trimmed = trim($line);

            if (preg_match('/^(#{1,6})\s/u', $trimmed, $m)) {
                $depth = strlen($m[1]);

                return $line;
            }

            $title = trim((string) preg_replace('/\s+/u', ' ', str_replace('*', '', $trimmed)));

            $isHeading = $title !== ''
                && count(explode(' ', $title)) <= 8
                && ! preg_match('/[.:;!?]$/u', $title)
                && (preg_match('/^(\*\*[^*]*\*\*\s*)+$/u', $trimmed)
                    || (preg_match_all('/\p{L}/u', $title) >= 3 && mb_strtoupper($title) === $title));

            return $isHeading ? str_repeat('#', min($depth + 1, 6)).' '.$title : $line;
        }, preg_split('/\R/u', $markdown) ?: [$markdown]));
    }

    /**
     * @param  array{text: string, raw_text: ?string, headings: ?list<string>}  $chunk
     * @return array{text: string, heading: ?string}
     */
    private function toChunk(array $chunk): array
    {
        return [
            'text' => $chunk['raw_text'] ?? $chunk['text'],
            'heading' => $chunk['headings'] ? implode(' > ', $chunk['headings']) : null,
        ];
    }
}
