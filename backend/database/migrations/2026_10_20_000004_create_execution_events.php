<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('type', 32);
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['execution_id', 'event_id']);
            $table->unique(['execution_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_events');
    }
};
