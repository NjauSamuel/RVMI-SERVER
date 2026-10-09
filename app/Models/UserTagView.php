<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTagView extends Model
{
    protected $fillable = ['user_id', 'tag_id', 'view_count', 'last_viewed_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'last_viewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
