<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->restrictOnDelete();
            $table->string('snapshot_node_id', 128);
            $table->foreignId('file_asset_id')->constrained('file_assets')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['execution_id', 'snapshot_node_id']);
            $table->unique('file_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_artifacts');
    }
};
