<?php

use BuildWithSmk\Tenancy\Models\Tenant;
use BuildWithSmk\Tenancy\TenancyManager;
use BuildWithSmk\TenantPermission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('switches to a dedicated database and restores the central one', function () {
    $tenancy = app(TenancyManager::class);
    $tenant = $this->makeTenant('big', ['db_config' => ['driver' => 'sqlite', 'database' => $this->sqliteFile()]]);

    $tenancy->initialize($tenant);

    expect(DB::getDefaultConnection())->toBe('tenant')
        ->and(config('database.default'))->toBe('tenant')
        // The tenants table is still read from the central connection.
        ->and(Tenant::query()->where('slug', 'big')->exists())->toBeTrue()
        ->and((new Tenant)->getConnectionName())->toBe('testing');

    $tenancy->end();

    expect(DB::getDefaultConnection())->toBe('testing')
        ->and(config('database.default'))->toBe('testing');
});

it('switches to dedicated storage and restores the default disk', function () {
    $tenancy = app(TenancyManager::class);
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tenancy_disk_'.uniqid();
    $tenant = $this->makeTenant('big', ['storage_config' => ['driver' => 'local', 'root' => $root]]);
    $before = config('filesystems.default');

    $tenancy->run($tenant, function () use ($root) {
        expect(config('filesystems.default'))->toBe('tenant');

        Storage::put('hello.txt', 'hi');
        expect(file_exists($root.DIRECTORY_SEPARATOR.'hello.txt'))->toBeTrue();
    });

    expect(config('filesystems.default'))->toBe($before);

    @unlink($root.DIRECTORY_SEPARATOR.'hello.txt');
    @rmdir($root);
});

it('ends the previous tenant when another is initialized', function () {
    $tenancy = app(TenancyManager::class);
    $big = $this->makeTenant('big', ['db_config' => ['driver' => 'sqlite', 'database' => $this->sqliteFile()]]);
    $small = $this->makeTenant('small');

    $tenancy->initialize($big);
    $tenancy->initialize($small);

    expect($tenancy->current()->is($small))->toBeTrue()
        ->and(DB::getDefaultConnection())->toBe('testing');

    $tenancy->end();
});

it('restores the previous tenant after run()', function () {
    $tenancy = app(TenancyManager::class);
    $acme = $this->makeTenant('acme');
    $beta = $this->makeTenant('beta');

    $tenancy->initialize($acme);
    $inside = $tenancy->run($beta, fn (Tenant $tenant) => $tenancy->current()->slug);

    expect($inside)->toBe('beta')
        ->and($tenancy->current()->is($acme))->toBeTrue()
        ->and(app('tenancy.current')->is($acme))->toBeTrue();

    $tenancy->end();
    expect($tenancy->check())->toBeFalse();
});

it('keeps the permission package on the current tenant', function () {
    $tenancy = app(TenancyManager::class);
    $registrar = app(PermissionRegistrar::class);
    $acme = $this->makeTenant('acme');

    $tenancy->initialize($acme);
    expect($registrar->getCurrentTenantId())->toBe($acme->id);

    $tenancy->end();
    expect($registrar->getCurrentTenantId())->toBeNull();
});

it('leaves the permission package alone when the bridge is off', function () {
    config(['tenancy.permission_bridge' => false]);
    $registrar = app(PermissionRegistrar::class);

    app(TenancyManager::class)->run($this->makeTenant('acme'), function () use ($registrar) {
        expect($registrar->getCurrentTenantId())->toBeNull();
    });
});
