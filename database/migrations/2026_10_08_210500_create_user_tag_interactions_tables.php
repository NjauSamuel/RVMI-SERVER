<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tag_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'tag_id']);
        });

        Schema::create('user_tag_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->unique(['user_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tag_views');
        Schema::dropIfExists('user_tag_favorites');
    }
};
