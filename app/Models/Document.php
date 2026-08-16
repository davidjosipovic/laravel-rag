<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Document extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = ['content', 'mime_type', 'metadata', 'title', 'status'];

    protected $casts = ['metadata' => 'array'];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
