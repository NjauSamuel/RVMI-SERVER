<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonReferencePhoto extends Model
{
    protected $fillable = ['person_id', 's3_key', 'media_item_id'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
