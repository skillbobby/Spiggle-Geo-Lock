<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->string('ip', 45)->index();
            $table->string('country_code', 2)->nullable();
            $table->string('region_code', 6)->nullable();
            $table->string('city')->nullable();
            $table->string('resolver')->nullable();

            $table->nullableMorphs('user');

            $table->json('roles')->nullable();
            $table->string('outcome')->index();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('rule_id')->nullable()->index();

            $table->string('url', 2048)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return (string) config('geo-lock.audit.table', 'geo_lock_attempts');
    }
};
