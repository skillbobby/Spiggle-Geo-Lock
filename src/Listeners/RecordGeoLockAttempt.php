<?php

declare(strict_types=1);

namespace GeoLock\Listeners;

use GeoLock\Events\GeoLockAccessDenied;
use GeoLock\Events\GeoLockAccessGranted;
use GeoLock\Events\GeoLockBypassed;
use GeoLock\Models\GeoLockAttempt;
use Throwable;

/**
 * Persists geo-lock decisions to the audit table.
 */
class RecordGeoLockAttempt
{
    public function handle(GeoLockAccessDenied|GeoLockAccessGranted|GeoLockBypassed $event): void
    {
        if (! config('geo-lock.audit.enabled', true)) {
            return;
        }

        $decision = $event->decision;

        $shouldRecord = match (true) {
            $decision->denied() => true,
            $decision->isBypassed() => (bool) config('geo-lock.audit.record_bypasses', false),
            default => (bool) config('geo-lock.audit.record_grants', false),
        };

        if (! $shouldRecord) {
            return;
        }

        try {
            GeoLockAttempt::fromDecision($decision)->save();
        } catch (Throwable $exception) {
            // Auditing must never break the request.
            report($exception);
        }
    }
}
