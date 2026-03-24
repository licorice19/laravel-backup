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
        Schema::create('backup_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->unique();
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('driver', 20);
            $table->boolean('has_database')->default(true);
            $table->boolean('has_files')->default(false);
            $table->json('manifest')->nullable();
            $table->timestamp('created_at');
            
            $table->index('filename');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_metadata');
    }
};
