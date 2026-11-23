<?php

namespace BuildWithSmk\Tenancy;

use BuildWithSmk\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Holds the current tenant and points DB / cache / storage at its resources.
 *
 * initialize() is the single entry point and end() undoes everything it
 * changed, so a worker (queue, Octane, scheduler loop) can serve many
 * tenants in one process without leaking state between them.
 */
class TenancyManager
{
    public const CUSTOM_DOMAINS_CACHE_KEY = 'tenancy:custom-domains';

    /** Optional companion package, referenced by name so it stays optional. */
    protected const PERMISSION_REGISTRAR = 'BuildWithSmk\\TenantPermission\\PermissionRegistrar';

    protected ?Tenant $tenant = null;

    protected bool $initialized = false;

    /**
     * Defaults that initialize() replaced, restored by end().
     *
     * @var array<string, string|null>
     */
    protected array $replaced = [];

    // -----------------------------------------------------------------
    // Identification
    // -----------------------------------------------------------------

    /**
     * Find the active tenant for a host: its custom `domain`, or
     * "{subdomain}.{central domain}". The id lookup is cached briefly
     * because it runs on every request.
     */
    public function resolveByHost(string $host): ?Tenant
    {
        $host = strtolower($host);
        $ttl = (int) config('tenancy.cache_ttl', 300);

        $tenantId = $ttl > 0
            ? Cache::remember("tenancy:host:{$host}", $ttl, fn () => $this->findTenantIdByHost($host))
            : $this->findTenantIdByHost($host);

        if (! $tenantId) {
            return null;
        }

        // Re-check is_active: the cached id outlives a deactivation.
        return $this->tenantModel()::query()->where('is_active', true)->find($tenantId);
    }

    protected function findTenantIdByHost(string $host): ?int
    {
        $subdomain = $this->subdomainOf($host);

        $tenant = $this->tenantModel()::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('domain', $host)
                ->when($subdomain !== null, fn ($query) => $query->orWhere('subdomain', $subdomain)))
            ->first();

        return $tenant?->getKey();
    }

    /**
     * The tenant label of "{label}.{central domain}", or null for any other
     * host. Never the first label of an arbitrary host, otherwise
     * "acme.attacker.example" would resolve to tenant "acme".
     */
    protected function subdomainOf(string $host): ?string
    {
        foreach (config('tenancy.central_domains', []) as $central) {
            $suffix = '.'.strtolower($central);

            if (str_ends_with($host, $suffix)) {
                $label = substr($host, 0, -strlen($suffix));

                return $label !== '' && ! str_contains($label, '.') ? $label : null;
            }
        }

        return null;
    }

    public function isCentralDomain(string $host): bool
    {
        return in_array(strtolower($host), array_map('strtolower', config('tenancy.central_domains', [])), true);
    }

    /**
     * Host patterns for Laravel's TrustHosts middleware: every central
     * domain and its subdomains, plus each tenant's custom domain.
     *
     * @return array<int, string>
     */
    public function trustedHostPatterns(): array
    {
        $central = array_map(
            fn (string $domain): string => '^(.+\.)?'.preg_quote($domain).'$',
            config('tenancy.central_domains', [])
        );

        $custom = Cache::remember(self::CUSTOM_DOMAINS_CACHE_KEY, (int) config('tenancy.cache_ttl', 300), function (): array {
            return $this->tenantModel()::query()
                ->whereNotNull('domain')
                ->where('domain', '!=', '')
                ->pluck('domain')
                ->all();
        });

        return [...$central, ...array_map(fn (string $domain): string => '^'.preg_quote($domain).'$', $custom)];
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    /**
     * Make this tenant current and switch to its dedicated resources.
     * Ends the previous tenant first if one is active.
     */
    public function initialize(Tenant $tenant): void
    {
        if ($this->initialized) {
            $this->end();
        }

        $this->tenant = $tenant;
        $this->initialized = true;

        app()->instance('tenancy.current', $tenant);

        $this->bootDatabase($tenant);
        $this->bootCache($tenant);
        $this->bootStorage($tenant);
        $this->syncPermissionScope($tenant->getKey());
    }

    /**
     * Back to the central state. The middleware calls this after every
     * request. Call it yourself after each tenant in jobs and commands,
     * or use run().
     */
    public function end(): void
    {
        if (! $this->initialized) {
            return;
        }

        if (array_key_exists('db', $this->replaced)) {
            Config::set('database.default', $this->replaced['db']);
            DB::setDefaultConnection($this->replaced['db']);
            DB::purge(config('tenancy.dynamic.db_connection'));
        }

        if (array_key_exists('cache', $this->replaced)) {
            Config::set('cache.default', $this->replaced['cache']);
            Cache::forgetDriver(config('tenancy.dynamic.cache_store', 'tenant'));
            Redis::purge(config('tenancy.dynamic.redis_connection'));
        }

        if (array_key_exists('disk', $this->replaced)) {
            Config::set('filesystems.default', $this->replaced['disk']);
            Storage::forgetDisk(config('tenancy.dynamic.storage_disk'));
        }

        app()->forgetInstance('tenancy.current');
        $this->syncPermissionScope(null);

        $this->replaced = [];
        $this->tenant = null;
        $this->initialized = false;
    }

    /**
     * Run a callback as a tenant, then restore whatever was current before.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;

        $this->initialize($tenant);

        try {
            return $callback($tenant);
        } finally {
            $this->end();

            if ($previous !== null) {
                $this->initialize($previous);
            }
        }
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    // -----------------------------------------------------------------
    // Resources
    // -----------------------------------------------------------------

    protected function bootDatabase(Tenant $tenant): void
    {
        if (! $tenant->hasDedicatedDatabase()) {
            // Shared database: isolation comes from BelongsToTenant's scope.
            return;
        }

        $name = config('tenancy.dynamic.db_connection');

        Config::set("database.connections.{$name}", $this->dedicatedDatabaseConfig($tenant->db_config));
        DB::purge($name);

        $this->replaced['db'] = config('database.default');
        Config::set('database.default', $name);
        DB::setDefaultConnection($name);
    }

    /**
     * The central connection's settings, overridden by a tenant's db_config.
     *
     * @param  array<string, mixed>  $dbConfig
     * @return array<string, mixed>
     */
    public function dedicatedDatabaseConfig(array $dbConfig): array
    {
        return array_merge(
            config('database.connections.'.$this->centralConnection(), []),
            $dbConfig
        );
    }

    protected function bootCache(Tenant $tenant): void
    {
        if (! $tenant->hasDedicatedCache()) {
            return;
        }

        $connection = config('tenancy.dynamic.redis_connection');
        $store = config('tenancy.dynamic.cache_store', 'tenant');

        Config::set("database.redis.{$connection}", $tenant->redis_config);
        Redis::purge($connection);

        Config::set("cache.stores.{$store}", ['driver' => 'redis', 'connection' => $connection]);
        Cache::forgetDriver($store);

        $this->replaced['cache'] = config('cache.default');
        Config::set('cache.default', $store);
    }

    protected function bootStorage(Tenant $tenant): void
    {
        if (! $tenant->hasDedicatedStorage()) {
            return;
        }

        $disk = config('tenancy.dynamic.storage_disk');

        Config::set("filesystems.disks.{$disk}", $tenant->storage_config);
        Storage::forgetDisk($disk);

        $this->replaced['disk'] = config('filesystems.default');
        Config::set('filesystems.default', $disk);
    }

    /**
     * Keep buildwithsmk/laravel-tenant-permission on the same tenant, when
     * it is installed.
     */
    protected function syncPermissionScope(int|string|null $tenantId): void
    {
        if (! config('tenancy.permission_bridge', true) || ! class_exists(self::PERMISSION_REGISTRAR)) {
            return;
        }

        app(self::PERMISSION_REGISTRAR)->setCurrentTenantId($tenantId === null ? null : (int) $tenantId);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function centralConnection(): string
    {
        return config('tenancy.defaults.db_connection') ?? config('database.default');
    }

    /**
     * @return class-string<Tenant>
     */
    public function tenantModel(): string
    {
        return config('tenancy.tenant_model', Tenant::class);
    }
}
