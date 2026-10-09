<?php

declare(strict_types=1);

namespace GeoLock\Console\Commands;

use GeoLock\Models\GeoLockAttempt;
use Illuminate\Console\Command;
use Throwable;

class PruneCommand extends Command
{
    protected $signature = 'geo-lock:prune
        {--days= : Delete attempts older than this many days (defaults to the configured retention)}
        {--dry-run : Only report how many rows would be deleted}';

    protected $description = 'Prune the geo-lock audit trail';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('geo-lock.audit.keep_days', 90));

        if ($days <= 0) {
            $this->components->warn('Retention must be a positive number of days.');

            return self::FAILURE;
        }

        try {
            $query = GeoLockAttempt::query()->where('created_at', '<', now()->subDays($days));
            $count = (clone $query)->count();

            if ($this->option('dry-run')) {
                $this->components->info("Would delete {$count} attempt(s) older than {$days} days.");

                return self::SUCCESS;
            }

            $query->delete();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Deleted {$count} attempt(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
