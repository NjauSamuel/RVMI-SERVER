<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_asset_id')->constrained('inventory_assets')->cascadeOnDelete();
            $table->string('file_key');
            $table->enum('mime', ['image', 'pdf']);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        $existing = DB::table('inventory_assets')
            ->whereNotNull('receipt_file_key')
            ->get(['id', 'receipt_file_key', 'receipt_mime', 'created_at', 'updated_at']);

        foreach ($existing as $asset) {
            DB::table('inventory_receipts')->insert([
                'inventory_asset_id' => $asset->id,
                'file_key' => $asset->receipt_file_key,
                'mime' => $asset->receipt_mime ?: 'image',
                'position' => 0,
                'created_at' => $asset->created_at,
                'updated_at' => $asset->updated_at,
            ]);
        }

        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropColumn(['receipt_file_key', 'receipt_mime']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->string('receipt_file_key')->nullable();
            $table->enum('receipt_mime', ['image', 'pdf'])->nullable();
        });

        $first = DB::table('inventory_receipts')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->unique('inventory_asset_id');

        foreach ($first as $receipt) {
            DB::table('inventory_assets')->where('id', $receipt->inventory_asset_id)->update([
                'receipt_file_key' => $receipt->file_key,
                'receipt_mime' => $receipt->mime,
            ]);
        }

        Schema::dropIfExists('inventory_receipts');
    }
};
