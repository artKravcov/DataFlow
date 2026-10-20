<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_key')->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('state', 20);
            $table->timestamps();
            $table->index(['organization_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_assets');
    }
};
