<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->timestamp('queue_next_check_at')->nullable();
            $table->unsignedInteger('queue_checks')->default(0);
            $table->index(['status', 'queue_next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropIndex(['status', 'queue_next_check_at']);
            $table->dropColumn(['queue_next_check_at', 'queue_checks']);
        });
    }
};
