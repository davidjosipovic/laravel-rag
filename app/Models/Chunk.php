<?php

namespace App\Models;

use Database\Factories\ChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Chunk extends Model
{
    /** @use HasFactory<ChunkFactory> */
    use HasFactory, HasNeighbors;

    protected $hidden = ['embedding'];

    protected $fillable = ['document_id', 'chunk_index', 'metadata', 'embedding', 'content'];

    protected $casts = ['metadata' => 'array', 'embedding' => Vector::class];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
