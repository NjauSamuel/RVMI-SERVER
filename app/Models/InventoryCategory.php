<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCategory extends Model
{
    protected $fillable = ['name'];

    public function assets(): HasMany
    {
        return $this->hasMany(InventoryAsset::class, 'category_id');
    }
}
