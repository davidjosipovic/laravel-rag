<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Document extends Model implements HasMedia
{
    use InteractsWithMedia;
    protected $fillable=['content','mime_type','metadata','title'];
    protected $casts=['metadata'=>'array'];
    public function chunks(): HasMany{
        return $this->hasMany(Chunk::class);
    }


}


