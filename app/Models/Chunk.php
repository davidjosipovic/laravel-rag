<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Chunk extends Model
{   
    use HasNeighbors;
    protected $fillable=['document_id','chunk_index','metadata','embedding','content'];
    protected $casts=['metadata'=>'array','embedding'=>Vector::class];

      
    public function document() : BelongsTo{
        return $this->belongsTo(Document::class);
    }
}
