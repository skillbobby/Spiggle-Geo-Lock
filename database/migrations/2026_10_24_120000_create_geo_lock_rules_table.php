<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_lock_rules', function (Blueprint $table): void {
            $table->id();
            $table->json('roles');
            $table->string('type', 16);
            $table->json('countries');
            $table->json('regions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_lock_rules');
    }
};
