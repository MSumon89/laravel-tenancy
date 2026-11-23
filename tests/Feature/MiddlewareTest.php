<?php

use BuildWithSmk\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('tenant')->group(function () {
        Route::get('/whoami', fn () => app()->bound('tenancy.current') ? app('tenancy.current')->slug : 'central');
        Route::get('/boom', fn () => throw new RuntimeException('boom'));
    });
});

it('leaves central domains untouched', function () {
    $this->get('http://app.test/whoami')->assertOk()->assertSee('central');
});

it('makes the host tenant current for the request only', function () {
    $this->makeTenant('acme');

    $this->get('http://acme.app.test/whoami')->assertOk()->assertSee('acme');

    expect(app(TenancyManager::class)->check())->toBeFalse()
        ->and(app()->bound('tenancy.current'))->toBeFalse();
});

it('returns 404 for an unknown host', function () {
    $this->get('http://nobody.app.test/whoami')->assertNotFound();
});

it('ends the tenant even when the request throws', function () {
    $this->makeTenant('acme');
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('http://acme.app.test/boom'))->toThrow(RuntimeException::class);
    expect(app(TenancyManager::class)->check())->toBeFalse();
});
