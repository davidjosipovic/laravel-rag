<?php

namespace App\Models;

use Database\Factories\ChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;

#[Hidden(['embedding'])]
#[Fillable(['document_id', 'chunk_index', 'metadata', 'embedding', 'content'])]
class Chunk extends Model
{
    /** @use HasFactory<ChunkFactory> */
    use HasFactory, HasNeighbors;

    protected function casts()
    {
        return [
            'metadata' => 'array',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
