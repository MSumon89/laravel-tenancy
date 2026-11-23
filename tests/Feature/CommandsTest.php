<?php

use BuildWithSmk\Tenancy\TenancyManager;
use BuildWithSmk\Tenancy\Tests\Fixtures\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('migrates every dedicated-database tenant', function () {
    $file = $this->sqliteFile();
    $this->makeTenant('big', ['db_config' => ['driver' => 'sqlite', 'database' => $file]]);
    $this->makeTenant('small');

    $this->artisan('tenants:migrate')->assertSuccessful();

    config(['database.connections.check' => ['driver' => 'sqlite', 'database' => $file]]);
    expect(Schema::connection('check')->hasTable('bookings'))->toBeTrue();
    DB::purge('check');
});

it('says so when there is nothing to migrate', function () {
    $this->makeTenant('small');

    $this->artisan('tenants:migrate')
        ->expectsOutputToContain('No dedicated-database tenants found')
        ->assertSuccessful();
});

it('upgrades a tenant to a dedicated database with its rows', function () {
    $tenancy = app(TenancyManager::class);
    $acme = $this->makeTenant('acme');
    $beta = $this->makeTenant('beta');
    $tenancy->run($acme, fn () => Booking::create(['title' => 'A1']));
    $tenancy->run($acme, fn () => Booking::create(['title' => 'A2']));
    $tenancy->run($beta, fn () => Booking::create(['title' => 'B1']));

    $file = $this->sqliteFile();

    $this->artisan('tenants:upgrade', ['slug' => 'acme', '--database' => $file, '--force' => true])
        ->assertSuccessful();

    $acme->refresh();
    expect($acme->tier)->toBe('business')
        ->and($acme->db_config)->toBe(['driver' => 'sqlite', 'database' => $file]);

    // On its dedicated database, acme sees exactly its own rows.
    $titles = $tenancy->run($acme, fn () => Booking::orderBy('title')->pluck('title')->all());
    expect($titles)->toBe(['A1', 'A2']);
});

it('refuses to upgrade a tenant twice', function () {
    $this->makeTenant('big', ['db_config' => ['driver' => 'sqlite', 'database' => $this->sqliteFile()]]);

    $this->artisan('tenants:upgrade', ['slug' => 'big', '--force' => true])
        ->expectsOutputToContain('already has a dedicated database')
        ->assertFailed();
});
