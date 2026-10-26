<?php

namespace Spiggle\GeoLock\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spiggle\GeoLock\Models\GeoLockEvent;
use Spiggle\GeoLock\Support\Decision;
use Spiggle\GeoLock\Support\Location;
use Throwable;

class DenialRecorder
{
    public function __construct(private AccessDecider $decider) {}

    public function record(Authenticatable $user, Location $location, Decision $decision, Request $request): void
    {
        $this->prune();

        $event = GeoLockEvent::query()->create([
            'user_id' => (string) $user->getAuthIdentifier(),
            'user_label' => $this->label($user),
            'roles' => $this->decider->roles($user),
            'ip' => $request->ip(),
            'country' => $location->country,
            'region' => $location->region,
            'source' => $location->source,
            'reason' => $decision->reason,
            'created_at' => now(),
        ]);

        $this->activity($user, $event, $location, $decision);
    }

    private function prune(): void
    {
        $days = (int) config('geo-lock.log_days', 90);

        if ($days < 1) {
            return;
        }

        GeoLockEvent::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    private function label(Authenticatable $user): ?string
    {
        $email = isset($user->email) ? trim((string) $user->email) : '';
        $name = isset($user->name) ? trim((string) $user->name) : '';

        if ($name !== '' && $email !== '') {
            return $name.' ('.$email.')';
        }

        if ($email !== '') {
            return $email;
        }

        return $name !== '' ? $name : null;
    }

    private function activity(Authenticatable $user, GeoLockEvent $event, Location $location, Decision $decision): void
    {
        if (! function_exists('activity')) {
            return;
        }

        try {
            $logger = activity('geo-lock')
                ->withProperties([
                    'event_id' => $event->getKey(),
                    'reason' => $decision->reason,
                    'country' => $location->country,
                    'region' => $location->region,
                    'ip' => $event->ip,
                    'roles' => $event->roles,
                ]);

            if ($user instanceof Model) {
                $logger->causedBy($user);
            }

            $logger->log('Geo lock denied access');
        } catch (Throwable) {
            // The panel stays locked when the shared activity log cannot be written.
        }
    }
}
