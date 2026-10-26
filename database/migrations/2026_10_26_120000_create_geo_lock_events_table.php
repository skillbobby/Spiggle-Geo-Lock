<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_lock_events', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('user_label')->nullable();
            $table->json('roles');
            $table->string('ip', 45)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('region')->nullable();
            $table->string('source', 16);
            $table->string('reason', 32);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_lock_events');
    }
};
