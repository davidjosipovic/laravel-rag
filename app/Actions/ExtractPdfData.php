<?php

namespace App\Actions;

use Paperdoc\Facades\Paperdoc;

class ExtractPdfData
{
    /**
     * @return array{source_path: string, content: string, metadata: array<string, mixed>}
     */
    public function handle(string $path): array
    {

        $document = Paperdoc::open($path);
        $content = Paperdoc::renderAs($document, 'md');

        $metadata = $document->getMetadata();

        return [
            'source_path' => $path,
            'content' => $content,
            'metadata' => $metadata,
        ];

    }
}
