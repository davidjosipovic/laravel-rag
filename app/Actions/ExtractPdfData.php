<?php

namespace App\Actions;

use Paperdoc\Facades\Paperdoc;

class ExtractPdfData
{
    /**
     * Split text into overlapping chunks.
     */

    public function handle($path): array
    {

        $document=Paperdoc::open($path);
        $content = Paperdoc::renderAs($document, 'md');

        
        $metadata = $document->getMetadata();

        return([
            'source_path' => $path,
            'content' => $content,
            'metadata' => $metadata,
            'mime_type' => $document->getThumbnail()['mimeType'],
        ]);

    }
}
