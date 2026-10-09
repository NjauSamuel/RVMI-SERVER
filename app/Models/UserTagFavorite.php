<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTagFavorite extends Model
{
    protected $fillable = ['user_id', 'tag_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
