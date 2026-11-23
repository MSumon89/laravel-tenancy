<?php

use BuildWithSmk\Tenancy\TenancyManager;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->tenancy = app(TenancyManager::class);
});

it('resolves a tenant by its custom domain', function () {
    $tenant = $this->makeTenant('acme', ['domain' => 'acme-hotel.com']);

    expect($this->tenancy->resolveByHost('acme-hotel.com')?->is($tenant))->toBeTrue()
        ->and($this->tenancy->resolveByHost('ACME-HOTEL.com')?->is($tenant))->toBeTrue();
});

it('resolves a tenant by a subdomain of a central domain', function () {
    $tenant = $this->makeTenant('acme');

    expect($this->tenancy->resolveByHost('acme.app.test')?->is($tenant))->toBeTrue();
});

it('does not resolve a subdomain of a foreign or nested host', function () {
    $this->makeTenant('acme');

    expect($this->tenancy->resolveByHost('acme.attacker.example'))->toBeNull()
        ->and($this->tenancy->resolveByHost('x.acme.app.test'))->toBeNull()
        ->and($this->tenancy->resolveByHost('app.test'))->toBeNull();
});

it('does not resolve inactive or deleted tenants', function () {
    $inactive = $this->makeTenant('sleepy');
    $deleted = $this->makeTenant('gone');

    // Warm the host cache first: deactivation must still win.
    expect($this->tenancy->resolveByHost('sleepy.app.test'))->not->toBeNull();

    $inactive->update(['is_active' => false]);
    $deleted->delete();

    expect($this->tenancy->resolveByHost('sleepy.app.test'))->toBeNull()
        ->and($this->tenancy->resolveByHost('gone.app.test'))->toBeNull()
        ->and($deleted->fresh()->purgesOn()?->isFuture())->toBeTrue();
});

it('stops resolving an old domain as soon as it changes', function () {
    $tenant = $this->makeTenant('acme', ['domain' => 'old.com']);
    expect($this->tenancy->resolveByHost('old.com'))->not->toBeNull();

    $tenant->update(['domain' => 'new.com']);

    expect($this->tenancy->resolveByHost('old.com'))->toBeNull()
        ->and($this->tenancy->resolveByHost('new.com')?->is($tenant))->toBeTrue();
});

it('lists trusted host patterns for central and custom domains', function () {
    $this->makeTenant('acme', ['domain' => 'acme-hotel.com']);

    expect($this->tenancy->trustedHostPatterns())->toBe([
        '^(.+\.)?app\.test$',
        '^acme\-hotel\.com$',
    ]);
});

it('builds a tenant url from its domain or subdomain', function () {
    $custom = $this->makeTenant('acme', ['domain' => 'acme-hotel.com']);
    $sub = $this->makeTenant('beta');

    $request = Request::create('https://app.test:8443/');

    expect($custom->url($request))->toBe('https://acme-hotel.com:8443')
        ->and($sub->url($request))->toBe('https://beta.app.test:8443');
});

it('hides connection secrets when serialised', function () {
    $tenant = $this->makeTenant('acme', ['db_config' => ['password' => 'secret']]);

    expect($tenant->toArray())->not->toHaveKey('db_config')
        ->and($tenant->db_config['password'])->toBe('secret');
});
