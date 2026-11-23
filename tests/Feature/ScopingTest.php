<?php

use BuildWithSmk\Tenancy\TenancyManager;
use BuildWithSmk\Tenancy\Tests\Fixtures\Booking;

it('fills tenant_id and scopes queries to the current tenant', function () {
    $tenancy = app(TenancyManager::class);
    $acme = $this->makeTenant('acme');
    $beta = $this->makeTenant('beta');

    $tenancy->run($acme, fn () => Booking::create(['title' => 'A1']));
    $tenancy->run($beta, fn () => Booking::create(['title' => 'B1']));

    $tenancy->initialize($acme);

    expect(Booking::pluck('title')->all())->toBe(['A1'])
        ->and(Booking::first()->tenant_id)->toBe($acme->id)
        ->and(Booking::withoutTenantScope()->count())->toBe(2);

    $tenancy->end();

    expect(Booking::count())->toBe(2);
});

it('keeps an explicit tenant_id on create', function () {
    $acme = $this->makeTenant('acme');
    $beta = $this->makeTenant('beta');

    app(TenancyManager::class)->run($acme, fn () => Booking::create(['title' => 'X', 'tenant_id' => $beta->id]));

    expect(Booking::first()->tenant_id)->toBe($beta->id);
});

it('uses the configured tenant column', function () {
    config(['tenancy.tenant_column' => 'owner_id']);
    $acme = $this->makeTenant('acme');

    $sql = app(TenancyManager::class)->run($acme, fn () => Booking::query()->toRawSql());

    expect($sql)->toContain('"bookings"."owner_id" = '.$acme->id);
});
