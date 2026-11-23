<?php

namespace BuildWithSmk\Tenancy\Models;

use BuildWithSmk\Tenancy\TenancyManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A tenant. Lives on the central connection, even while a
 * dedicated-database tenant is active.
 *
 * Extend this model to add your own columns, and point
 * `tenancy.tenant_model` at your class.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $domain
 * @property string|null $subdomain
 * @property string $tier
 * @property array<string, mixed>|null $db_config
 * @property array<string, mixed>|null $redis_config
 * @property array<string, mixed>|null $storage_config
 * @property bool $is_active
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $deleted_at
 */
class Tenant extends Model
{
    use SoftDeletes;

    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'subdomain',
        'tier',
        'db_config',
        'redis_config',
        'storage_config',
        'is_active',
        'provisioned_at',
    ];

    /**
     * Connection details are secrets: keep them out of toArray() / JSON.
     *
     * @var array<int, string>
     */
    protected $hidden = ['db_config', 'redis_config', 'storage_config'];

    protected $casts = [
        'db_config' => 'array',
        'redis_config' => 'array',
        'storage_config' => 'array',
        'is_active' => 'boolean',
        'provisioned_at' => 'datetime',
    ];

    /**
     * Always the central connection, never a tenant's switched default.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? app(TenancyManager::class)->centralConnection();
    }

    /**
     * A new or changed custom domain must be trusted (TrustHosts) at once,
     * and a changed host must stop resolving to its old tenant.
     */
    protected static function booted(): void
    {
        $forget = function (Tenant $tenant): void {
            Cache::forget(TenancyManager::CUSTOM_DOMAINS_CACHE_KEY);

            $hosts = array_filter([$tenant->domain, $tenant->getOriginal('domain')]);

            foreach (array_filter([$tenant->subdomain, $tenant->getOriginal('subdomain')]) as $subdomain) {
                foreach (config('tenancy.central_domains', []) as $central) {
                    $hosts[] = "{$subdomain}.{$central}";
                }
            }

            foreach (array_unique($hosts) as $host) {
                Cache::forget('tenancy:host:'.strtolower($host));
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public function hasDedicatedDatabase(): bool
    {
        return ! empty($this->db_config);
    }

    public function hasDedicatedCache(): bool
    {
        return ! empty($this->redis_config);
    }

    public function hasDedicatedStorage(): bool
    {
        return ! empty($this->storage_config);
    }

    public function isStarter(): bool
    {
        return $this->tier === 'starter';
    }

    public function isBusiness(): bool
    {
        return $this->tier === 'business';
    }

    public function isEnterprise(): bool
    {
        return $this->tier === 'enterprise';
    }

    public static function purgeAfterDays(): int
    {
        return (int) config('tenancy.deletion_grace_days', 30);
    }

    /**
     * When a soft-deleted tenant may be removed for good. Null if not deleted.
     */
    public function purgesOn(): ?Carbon
    {
        return $this->deleted_at?->copy()->addDays(static::purgeAfterDays());
    }

    /**
     * This tenant's base URL: its custom domain, or its subdomain under the
     * first central domain. Scheme and port come from the current request.
     */
    public function url(?Request $request = null): string
    {
        $request ??= request();

        $host = $this->domain ?: "{$this->subdomain}.".config('tenancy.central_domains.0');

        $port = $request->getPort();
        $portSuffix = in_array($port, [80, 443], true) ? '' : ":{$port}";

        return "{$request->getScheme()}://{$host}{$portSuffix}";
    }
}
