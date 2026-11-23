<?php

namespace BuildWithSmk\Tenancy\Console\Commands;

use BuildWithSmk\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class MigrateTenants extends Command
{
    protected $signature = 'tenants:migrate
                            {--tenant=* : Only these tenant slugs}
                            {--path=* : Migration path(s) to run instead of the app\'s default}
                            {--fresh : Run migrate:fresh instead (drops every table)}';

    protected $description = 'Run migrations on every tenant with a dedicated database. Shared-database tenants are covered by the normal `php artisan migrate`.';

    public function handle(TenancyManager $tenancy): int
    {
        $tenants = $tenancy->tenantModel()::query()
            ->whereNotNull('db_config')
            ->when($this->option('tenant'), fn ($query, array $slugs) => $query->whereIn('slug', $slugs))
            ->get()
            ->filter->hasDedicatedDatabase();

        if ($tenants->isEmpty()) {
            $this->components->info('No dedicated-database tenants found.');

            return self::SUCCESS;
        }

        if ($this->option('fresh') && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $connection = config('tenancy.dynamic.db_connection');

        foreach ($tenants as $tenant) {
            $this->components->task("{$tenant->name} ({$tenant->slug})", function () use ($tenancy, $tenant, $connection): bool {
                Config::set("database.connections.{$connection}", $tenancy->dedicatedDatabaseConfig($tenant->db_config));
                DB::purge($connection);

                $options = ['--database' => $connection, '--force' => true];

                if ($paths = $this->option('path')) {
                    $options['--path'] = $paths;
                }

                return $this->callSilently($this->option('fresh') ? 'migrate:fresh' : 'migrate', $options) === self::SUCCESS;
            });
        }

        DB::purge($connection);

        return self::SUCCESS;
    }

    protected function confirmToProceed(): bool
    {
        return $this->option('no-interaction')
            || $this->confirm('migrate:fresh drops every table in each dedicated tenant database. Continue?');
    }
}
