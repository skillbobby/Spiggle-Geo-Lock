<?php

declare(strict_types=1);

namespace GeoLock\Events;

use GeoLock\Data\GeoLockDecision;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the middleware blocks a request.
 *
 * Listen for this event to log, alert (Slack, Discord, ...) or otherwise
 * react to geo-lock activity.
 */
class GeoLockAccessDenied
{
    use Dispatchable;

    public function __construct(
        public readonly GeoLockDecision $decision,
    ) {}
}
