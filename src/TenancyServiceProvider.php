<?php

namespace BuildWithSmk\Tenancy;

use BuildWithSmk\Tenancy\Console\Commands\MigrateTenants;
use BuildWithSmk\Tenancy\Console\Commands\TenantUpgrade;
use BuildWithSmk\Tenancy\Middleware\IdentifyTenant;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Package migrations, keyed by name suffix. The suffix is how an
     * already-published copy is recognised.
     *
     * @var array<int, string>
     */
    protected const MIGRATIONS = [
        'create_tenants_table',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');

        $this->app->singleton(TenancyManager::class);
    }

    public function boot(): void
    {
        $this->registerMigrations();

        $this->app['router']->aliasMiddleware('tenant', IdentifyTenant::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tenancy.php' => config_path('tenancy.php'),
            ], 'tenancy-config');

            $this->publishes($this->migrationPublishMap(), 'tenancy-migrations');

            $this->commands([
                MigrateTenants::class,
                TenantUpgrade::class,
            ]);
        }
    }

    /**
     * Auto-load every migration the app has not published itself.
     */
    protected function registerMigrations(): void
    {
        $paths = collect(self::MIGRATIONS)
            ->reject(fn (string $suffix): bool => $this->publishedMigrationPath($suffix) !== null)
            ->map(fn (string $suffix): string => $this->packageMigrationPath($suffix))
            ->values()
            ->all();

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function migrationPublishMap(): array
    {
        $map = [];
        $timestamp = time();

        foreach (self::MIGRATIONS as $index => $suffix) {
            $map[$this->packageMigrationPath($suffix)] = $this->publishedMigrationPath($suffix)
                ?? database_path('migrations/'.date('Y_m_d_His', $timestamp + $index).'_'.$suffix.'.php');
        }

        return $map;
    }

    protected function packageMigrationPath(string $suffix): string
    {
        return glob(__DIR__.'/../database/migrations/*_'.$suffix.'.php')[0];
    }

    protected function publishedMigrationPath(string $suffix): ?string
    {
        return glob(database_path('migrations/*_'.$suffix.'.php'))[0] ?? null;
    }
}
