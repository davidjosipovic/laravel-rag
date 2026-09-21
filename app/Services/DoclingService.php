<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DoclingService
{
    /**
     * @return array{document: array<string, mixed>, status: string, processing_time: float, errors: array<int, mixed>}
     */
    public function convert(string $path): array
    {
        $response = Http::timeout(600)
            ->attach('files', fopen($path, 'r'), basename($path))
            ->post(config('services.docling.url') . '/v1/convert/file', [
                'to_formats' => 'md',
                'do_ocr' => 'true',
                'table_mode' => 'accurate',
                'image_export_mode' => 'placeholder',
            ])
            ->throw();

        return $response->json();
    }
}