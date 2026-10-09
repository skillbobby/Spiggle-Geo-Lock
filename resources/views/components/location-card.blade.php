@php
    use GeoLock\Enums\GeoLockOutcome;

    /** @var \GeoLock\Data\GeoLockDecision $decision */
    $location = $decision->location;
    $outcome = $decision->outcome;
@endphp

<x-filament::section
    :heading="__('geo-lock::geo-lock.settings.location.heading')"
    :description="__('geo-lock::geo-lock.settings.location.description')"
    icon="heroicon-o-map-pin"
    collapsible
>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="fi-geo-lock-stat">
            <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('geo-lock::geo-lock.settings.location.ip') }}
            </span>
            <p class="mt-1 font-mono text-sm">{{ $location->ip }}</p>
        </div>

        <div class="fi-geo-lock-stat">
            <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('geo-lock::geo-lock.settings.location.country') }}
            </span>
            <p class="mt-1 text-sm">
                {{ $location->countryLabel() ?? __('geo-lock::geo-lock.location.unknown') }}
            </p>
        </div>

        <div class="fi-geo-lock-stat">
            <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('geo-lock::geo-lock.settings.location.region') }}
            </span>
            <p class="mt-1 text-sm">
                {{ $location->regionLabel() ?? __('geo-lock::geo-lock.location.unknown') }}
            </p>
        </div>

        <div class="fi-geo-lock-stat">
            <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('geo-lock::geo-lock.settings.location.decision') }}
            </span>
            <p class="mt-1">
                <x-filament::badge :color="$outcome->getColor()" :icon="$outcome->getIcon()">
                    {{ $outcome->getLabel() }}
                </x-filament::badge>
            </p>
        </div>
    </div>

    <x-slot name="footer">
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span>
                {{ __('geo-lock::geo-lock.settings.location.summary', [
                    'rules' => $rules,
                    'active' => $activeRules,
                    'resolver' => $location->resolver,
                ]) }}
            </span>

            @if ($decision->rule)
                <span>
                    {{ __('geo-lock::geo-lock.settings.location.matched_rule', [
                        'rule' => $decision->rule->label(),
                    ]) }}
                </span>
            @endif
        </div>
    </x-slot>
</x-filament::section>
