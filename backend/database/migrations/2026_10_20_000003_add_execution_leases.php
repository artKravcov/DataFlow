<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->string('worker_id', 128)->nullable();
            $table->string('lease_token_hash', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn(['worker_id', 'lease_token_hash', 'lease_expires_at', 'error_code']);
        });
    }
};
