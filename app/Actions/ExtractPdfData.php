<?php

namespace App\Actions;

use Illuminate\Support\Facades\Storage;
use Paperdoc\Facades\Paperdoc;
use Smalot\PdfParser\Parser;

class ExtractPdfData
{
    /**
     * Split text into overlapping chunks.
     */

    private  $disk;
    private $parser;

    public function __construct(){
        $this->disk = Storage::disk('local');
        $this->parser = new Parser();
    }
    public function handle($path): array
    {

        $document=Paperdoc::open($path);
        $content = Paperdoc::renderAs($document, 'md');

        
        $metadata = $document->getMetadata();

        $firstLine = trim($content) !== ''
        ? explode("\n", trim($content))[0]
        : null;

        $title = trim($metadata['Title'] ?? '')
            ?: $firstLine
            ?: 'Untitled document';
            

        return([
            'title' => $title,
            'source_path' => $path,
            'content' => $content,
            'metadata' => $metadata,
            'mime_type' => $document->getThumbnail()['mimeType'],
        ]);

    }
}
