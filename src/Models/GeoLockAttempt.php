<?php

declare(strict_types=1);

namespace GeoLock\Models;

use GeoLock\Data\GeoLockDecision;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Support\CountryData;
use GeoLock\Support\RegionData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An audited geo-lock decision.
 *
 * @property int $id
 * @property string $ip
 * @property string|null $country_code
 * @property string|null $region_code
 * @property string|null $city
 * @property string|null $resolver
 * @property int|string|null $user_id
 * @property string|null $user_type
 * @property array<int, string>|null $roles
 * @property GeoLockOutcome $outcome
 * @property string|null $reason
 * @property int|null $rule_id
 * @property string|null $url
 * @property string|null $method
 * @property string|null $user_agent
 */
class GeoLockAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return [
            ...parent::getCasts(),
            'roles' => 'array',
            'outcome' => GeoLockOutcome::class,
            'rule_id' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return config('geo-lock.audit.table', 'geo_lock_attempts');
    }

    /**
     * @return BelongsTo<GeoLockRule, self>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(config('geo-lock.rules.model', GeoLockRule::class), 'rule_id');
    }

    /**
     * @return MorphTo<Model, self>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDenied(Builder $query): Builder
    {
        return $query->whereNotIn('outcome', [
            GeoLockOutcome::Allowed->value,
            GeoLockOutcome::Bypassed->value,
            GeoLockOutcome::NoRules->value,
            GeoLockOutcome::Disabled->value,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAllowed(Builder $query): Builder
    {
        return $query->whereIn('outcome', [
            GeoLockOutcome::Allowed->value,
            GeoLockOutcome::Bypassed->value,
            GeoLockOutcome::NoRules->value,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->latest('id');
    }

    public function countryLabel(): ?string
    {
        return filled($this->country_code) ? CountryData::label($this->country_code) : null;
    }

    public function regionLabel(): ?string
    {
        return filled($this->region_code) ? RegionData::label($this->region_code) : null;
    }

    public static function fromDecision(GeoLockDecision $decision): self
    {
        $location = $decision->location;
        $user = $decision->user;
        $request = request();

        return new self([
            'ip' => $location->ip,
            'country_code' => $location->countryCode,
            'region_code' => $location->regionCode,
            'city' => $location->city,
            'resolver' => $location->resolver,
            'user_id' => $user?->getAuthIdentifier(),
            'user_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'roles' => $decision->roles,
            'outcome' => $decision->outcome,
            'reason' => $decision->reason,
            'rule_id' => $decision->rule?->getKey(),
            'url' => mb_substr((string) $request?->fullUrl(), 0, 2048) ?: null,
            'method' => $request?->getMethod(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 512) ?: null,
        ]);
    }
}
