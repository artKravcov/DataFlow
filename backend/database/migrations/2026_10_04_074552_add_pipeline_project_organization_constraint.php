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
        Schema::table('projects', function (Blueprint $table) {
            $table->unique(['id', 'organization_id']);
        });

        Schema::table('pipelines', function (Blueprint $table) {
            $table->foreign(['project_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('projects')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table) {
            $table->dropForeign(['project_id', 'organization_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['id', 'organization_id']);
        });
    }
};
