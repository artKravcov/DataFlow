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
        Schema::create('pipeline_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('edge_id', 128);
            $table->string('source_node_id', 128);
            $table->string('source_port', 128);
            $table->string('target_node_id', 128);
            $table->string('target_port', 128);
            $table->unique(['pipeline_id', 'edge_id']);
            $table->unique(['pipeline_id', 'target_node_id', 'target_port']);
            $table->unique([
                'pipeline_id',
                'source_node_id',
                'source_port',
                'target_node_id',
                'target_port',
            ]);
            $table->foreign(['pipeline_id', 'source_node_id'])
                ->references(['pipeline_id', 'node_id'])
                ->on('pipeline_nodes')
                ->cascadeOnDelete();
            $table->foreign(['pipeline_id', 'target_node_id'])
                ->references(['pipeline_id', 'node_id'])
                ->on('pipeline_nodes')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_edges');
    }
};
