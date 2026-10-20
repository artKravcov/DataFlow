<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipelines', fn (Blueprint $table) => $table->unique(['id', 'organization_id']));
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pipeline_id');
            $table->foreign(['pipeline_id', 'organization_id'])->references(['id', 'organization_id'])->on('pipelines')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('revision');
            $table->string('status', 20);
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'created_at']);
        });
        DB::statement("CREATE UNIQUE INDEX executions_one_active_pipeline ON executions (pipeline_id) WHERE status IN ('queued', 'running')");
        Schema::create('execution_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->restrictOnDelete();
            $table->string('snapshot_node_id', 128);
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['execution_id', 'snapshot_node_id']);
        });
        Schema::create('execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->restrictOnDelete();
            $table->string('snapshot_node_id', 128)->nullable();
            $table->uuid('event_id');
            $table->string('level', 20);
            $table->string('code', 64);
            $table->string('message', 1024);
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at');
            $table->unique(['execution_id', 'event_id']);
            $table->index(['execution_id', 'id']);
        });
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('execution_id')->constrained()->restrictOnDelete();
            $table->string('message_type', 64);
            $table->jsonb('payload');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('execution_logs');
        Schema::dropIfExists('execution_nodes');
        Schema::dropIfExists('executions');
        Schema::table('pipelines', fn (Blueprint $table) => $table->dropUnique(['id', 'organization_id']));
    }
};
