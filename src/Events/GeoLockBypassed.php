<?php

declare(strict_types=1);

namespace GeoLock\Events;

use GeoLock\Data\GeoLockDecision;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a visitor bypasses the rules (emergency bypass or disabled geo-lock).
 *
 * Listen for this event to log, alert (Slack, Discord, ...) or otherwise
 * react to geo-lock activity.
 */
class GeoLockBypassed
{
    use Dispatchable;

    public function __construct(
        public readonly GeoLockDecision $decision,
    ) {}
}
