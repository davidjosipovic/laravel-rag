<?php

namespace App\Jobs;

use App\Actions\ExtractPdfData;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;
    public int $timeout = 3600;
    /**
     * Create a new job instance.
     */
    public function __construct(public int $documentId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(ExtractPdfData $extractor): void
    {
        $document=Document::findOrFail($this->documentId);
        $path=$document->getFirstMedia('documents')->getPath();
        $document->update($extractor->handle($path));
    }
    }

