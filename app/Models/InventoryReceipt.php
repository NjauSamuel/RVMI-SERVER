<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReceipt extends Model
{
    protected $fillable = [
        'inventory_asset_id',
        'file_key',
        'mime',
        'position',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id');
    }
}
