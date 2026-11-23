<?php

namespace BuildWithSmk\Tenancy\Tests;

use BuildWithSmk\Tenancy\Models\Tenant;
use BuildWithSmk\Tenancy\TenancyServiceProvider;
use BuildWithSmk\TenantPermission\PermissionServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $sqliteFiles = [];

    protected function getPackageProviders($app): array
    {
        // The permission package is a dev dependency, loaded to test the bridge.
        return [TenancyServiceProvider::class, PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('tenancy.defaults.db_connection', 'testing');
        $app['config']->set('tenancy.central_domains', ['app.test']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->sqliteFiles as $file) {
            @unlink($file);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTenant(string $slug, array $attributes = []): Tenant
    {
        return Tenant::query()->create($attributes + [
            'name' => ucfirst($slug),
            'slug' => $slug,
            'subdomain' => $slug,
        ]);
    }

    /**
     * An empty SQLite database file, deleted after the test.
     */
    protected function sqliteFile(): string
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tenancy_'.uniqid().'.sqlite';
        touch($file);

        return $this->sqliteFiles[] = $file;
    }
}
