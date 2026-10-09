<?php

declare(strict_types=1);

namespace GeoLock\Models;

use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Data\GeoLocation;
use GeoLock\Database\Factories\GeoLockRuleFactory;
use GeoLock\Enums\RuleType;
use GeoLock\Support\CountryData;
use GeoLock\Support\RegionData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A single geographic access rule.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $description
 * @property array<int, string>|null $roles
 * @property RuleType $type
 * @property array<int, string>|null $countries
 * @property array<int, string>|null $regions
 * @property bool $is_active
 * @property int $priority
 */
class GeoLockRule extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return GeoLockRuleFactory
     */
    protected static function newFactory(): Factory
    {
        return GeoLockRuleFactory::new();
    }

    /**
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return [
            ...parent::getCasts(),
            'roles' => 'array',
            'type' => RuleType::class,
            'countries' => 'array',
            'regions' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return config('geo-lock.rules.table', 'geo_lock_rules');
    }

    protected static function booted(): void
    {
        $forget = static function (): void {
            app(GeoLockRuleRepository::class)->flush();
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rules are evaluated by descending priority, then by insertion order.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEvaluation(Builder $query): Builder
    {
        return $query->orderByDesc('priority')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<int, string>  $roles
     * @return Builder<self>
     */
    public function scopeForRoles(Builder $query, array $roles): Builder
    {
        return $query->where(function (Builder $query) use ($roles): void {
            $query->whereNull('roles');

            foreach ($roles as $index => $role) {
                $query->orWhereJsonContains('roles', $role);
            }
        });
    }

    /**
     * @return HasMany<GeoLockAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(GeoLockAttempt::class, 'rule_id');
    }

    public function isWhitelist(): bool
    {
        return $this->type === RuleType::Whitelist;
    }

    public function isBlacklist(): bool
    {
        return $this->type === RuleType::Blacklist;
    }

    /**
     * Whether this rule applies to a user holding the given roles.
     *
     * @param  array<int, string>  $roles
     */
    public function appliesToRoles(array $roles): bool
    {
        $ruleRoles = array_values(array_filter($this->roles ?? [], filled(...)));

        if ($ruleRoles === []) {
            return (bool) config('geo-lock.access.apply_rules_without_roles', true);
        }

        return array_intersect($ruleRoles, $roles) !== [];
    }

    /**
     * Whether the resolved location falls inside this rule's scope.
     */
    public function matches(GeoLocation $location): bool
    {
        if (! $location->isResolved()) {
            return false;
        }

        if (! $this->matchesCountry($location->countryCode)) {
            return false;
        }

        $regions = array_values(array_filter($this->regions ?? [], filled(...)));

        // An empty region list means "the whole country".
        if ($regions === []) {
            return true;
        }

        return $location->regionCode !== null
            && in_array($location->regionCode, $regions, strict: true);
    }

    public function matchesCountry(?string $countryCode): bool
    {
        if (blank($countryCode)) {
            return false;
        }

        $countries = array_values(array_filter($this->countries ?? [], filled(...)));

        return in_array(strtoupper($countryCode), array_map(strtoupper(...), $countries), strict: true);
    }

    /**
     * @return Collection<int, string>
     */
    public function countryLabels(): Collection
    {
        return collect($this->countries ?? [])->map(fn (string $code): string => CountryData::label($code));
    }

    /**
     * @return Collection<int, string>
     */
    public function regionLabels(): Collection
    {
        return collect($this->regions ?? [])->map(fn (string $code): string => RegionData::label($code));
    }

    /**
     * @return array<int, string>
     */
    public function roleLabels(): array
    {
        return array_values(array_filter($this->roles ?? [], filled(...)));
    }

    public function label(): string
    {
        return $this->name ?: __('geo-lock::geo-lock.rules.unnamed', ['id' => $this->getKey()]);
    }

    /**
     * A short, human readable summary of the rule scope.
     */
    public function scopeSummary(): string
    {
        $regions = $this->regions ?? [];

        if ($regions !== []) {
            return collect($regions)->take(3)->map(fn (string $code): string => RegionData::label($code))->implode(', ')
                .(count($regions) > 3 ? ' +'.(count($regions) - 3) : '');
        }

        $countries = $this->countries ?? [];

        return collect($countries)->take(3)->map(fn (string $code): string => CountryData::label($code))->implode(', ')
            .(count($countries) > 3 ? ' +'.(count($countries) - 3) : '');
    }
}
