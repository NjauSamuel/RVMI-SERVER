<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_media_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained('media_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'media_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_media_favorites');
    }
};
