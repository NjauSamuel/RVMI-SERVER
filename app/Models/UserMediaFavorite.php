<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMediaFavorite extends Model
{
    protected $fillable = ['user_id', 'media_item_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
