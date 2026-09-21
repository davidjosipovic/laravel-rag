<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Extracted = 'extracted';
    case Chunking = 'chunking';
    case Chunked = 'chunked';
    case Embedding = 'embedding';
    case Embedded = 'embedded';
    case Failed = 'failed';
}
