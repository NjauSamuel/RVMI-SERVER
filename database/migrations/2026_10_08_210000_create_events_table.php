<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('event_date')->nullable();
            $table->foreignId('cover_media_item_id')->nullable();
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['visibility', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
