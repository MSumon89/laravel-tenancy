<?php

namespace BuildWithSmk\Tenancy\Console\Commands;

use BuildWithSmk\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantUpgrade extends Command
{
    protected $signature = 'tenants:upgrade
                            {slug : The tenant slug}
                            {--to=business : Target tier}
                            {--host= : Host of the dedicated database (default: the central host)}
                            {--port= : Port (default: the central port)}
                            {--database= : Database name (default: tenant_<slug>). For SQLite, the file path}
                            {--username= : Username (default: the central username)}
                            {--password= : Password (default: the central password)}
                            {--force : Skip the confirmation}';

    protected $description = 'Move a tenant from the shared database to a dedicated one, copying its rows.';

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = $tenancy->tenantModel()::query()->where('slug', $this->argument('slug'))->first();

        if (! $tenant) {
            $this->components->error("Tenant [{$this->argument('slug')}] not found.");

            return self::FAILURE;
        }

        if ($tenant->hasDedicatedDatabase()) {
            $this->components->error("{$tenant->name} already has a dedicated database.");

            return self::FAILURE;
        }

        $central = $tenancy->centralConnection();
        $dbConfig = array_filter([
            'driver' => config("database.connections.{$central}.driver"),
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'database' => $this->option('database') ?? "tenant_{$tenant->slug}",
            'username' => $this->option('username'),
            'password' => $this->option('password'),
        ], fn ($value) => $value !== null);

        $target = $dbConfig['database'];

        if (! $this->option('force') && ! $this->confirm("Copy {$tenant->name}'s data into [{$target}]? The database must already exist and be empty.")) {
            return self::SUCCESS;
        }

        $connection = config('tenancy.dynamic.db_connection');
        Config::set("database.connections.{$connection}", $tenancy->dedicatedDatabaseConfig($dbConfig));
        DB::purge($connection);

        $this->components->task('Migrating the dedicated database', fn () => $this->callSilently('migrate', [
            '--database' => $connection,
            '--force' => true,
        ]) === self::SUCCESS);

        $column = config('tenancy.tenant_column', 'tenant_id');
        $source = Schema::connection($central);
        $destination = Schema::connection($connection);

        // tenant_id is kept: BelongsToTenant still scopes by it on the
        // dedicated database, so rows without it would be invisible.
        $destination->withoutForeignKeyConstraints(function () use ($tenant, $central, $connection, $column, $source, $destination): void {
            foreach ($this->tableNames($source) as $table) {
                if (! $source->hasColumn($table, $column) || ! $destination->hasTable($table)) {
                    continue;
                }

                $query = DB::connection($central)->table($table)->where($column, $tenant->getKey());
                $count = $query->count();

                if ($count === 0) {
                    continue;
                }

                $this->components->task("Copying {$count} row(s) from [{$table}]", function () use ($query, $connection, $table): void {
                    $query->orderBy($query->getConnection()->getSchemaBuilder()->getColumnListing($table)[0])
                        ->chunk(500, fn ($rows) => DB::connection($connection)->table($table)->insert(
                            $rows->map(fn ($row): array => (array) $row)->all()
                        ));
                });
            }
        });

        $tenant->update([
            'tier' => $this->option('to'),
            'db_config' => $dbConfig,
        ]);

        DB::purge($connection);

        $this->components->info("{$tenant->name} is now on [{$this->option('to')}] with a dedicated database.");
        $this->components->warn('The old rows are still in the shared database. Check the new database, then remove them yourself.');

        return self::SUCCESS;
    }

    /**
     * Tables of the connection's own database only. Laravel 12+ lists every
     * schema on the server unless asked for the current one.
     *
     * @return array<int, string>
     */
    protected function tableNames(SchemaBuilder $schema): array
    {
        $tables = method_exists($schema, 'getCurrentSchemaName')
            ? $schema->getTables($schema->getCurrentSchemaName())
            : $schema->getTables();

        return array_column($tables, 'name');
    }
}
