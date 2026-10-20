<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pipeline_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 128);
            $table->string('type', 128);
            $table->unsignedSmallInteger('type_version');
            $table->jsonb('config');
            $table->jsonb('position');
            $table->jsonb('schema_hints')->nullable();
            $table->unique(['pipeline_id', 'node_id']);
            $table->index(['pipeline_id', 'type']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_nodes');
    }
};
