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
}
