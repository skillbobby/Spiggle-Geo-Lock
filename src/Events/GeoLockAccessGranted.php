<?php

declare(strict_types=1);

namespace GeoLock\Events;

use GeoLock\Data\GeoLockDecision;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the middleware allows a request to reach the panel.
 *
 * Listen for this event to log, alert (Slack, Discord, ...) or otherwise
 * react to geo-lock activity.
 */
class GeoLockAccessGranted
{
    use Dispatchable;

    public function __construct(
        public readonly GeoLockDecision $decision,
    ) {}
}
