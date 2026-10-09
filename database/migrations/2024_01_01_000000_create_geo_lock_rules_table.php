<?php

declare(strict_types=1);

use GeoLock\Enums\RuleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->string('name')->nullable();
            $table->text('description')->nullable();

            // The roles this rule applies to. An empty list means "every role".
            $table->json('roles')->nullable();

            $table->string('type')->default(RuleType::Blacklist->value);

            // ISO 3166-1 alpha-2 codes, and ISO 3166-2 subdivision codes.
            $table->json('countries')->nullable();
            $table->json('regions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);

            $table->timestamps();

            $table->index('is_active');
            $table->index('type');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    protected function table(): string
    {
        return (string) config('geo-lock.rules.table', 'geo_lock_rules');
    }
};
