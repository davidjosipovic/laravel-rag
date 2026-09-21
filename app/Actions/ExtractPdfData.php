<?php

namespace App\Actions;

use App\Services\DoclingService;

class ExtractPdfData
{
    public function __construct(
        private DoclingService $docling,
    ) {}

    /**
     * @return array{source_path: string, content: string, metadata: array<string, mixed>}
     */
    public function handle(string $path): array
    {
        $result = $this->docling->convert($path);

        return [
            'source_path' => $path,
            'content' => $result['document']['md_content'] ?? '',
            'metadata' => [
                'filename' => $result['document']['filename'] ?? basename($path),
                'status' => $result['status'] ?? null,
                'processing_time' => $result['processing_time'] ?? null,
            ],
        ];
    }
}