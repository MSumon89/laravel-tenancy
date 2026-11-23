# buildwithsmk/laravel-tenancy

Lightweight multi-tenancy for Laravel. Every tenant starts on the **shared database**, and any tenant can later move to its own **dedicated database, Redis or storage**, all from one codebase.

- Identify the tenant from a **custom domain** or a **subdomain** (`acme.yourapp.com`).
- `BelongsToTenant` trait: queries are scoped and `tenant_id` is filled in automatically.
- Per-tenant **dedicated database / Redis cache / storage disk**, switched on for the request and cleanly restored afterwards.
- `tenants:upgrade`: move a tenant from the shared DB to its own DB, rows included.
- Safe for queues, scheduled commands and Octane (`run()`, automatic cleanup).
- Works with [buildwithsmk/laravel-tenant-permission](https://github.com/MSumon89/laravel-tenant-permission) out of the box.

| Tier | Database | Cache | Storage |
|---|---|---|---|
| `starter` (default) | shared, isolated by `tenant_id` | shared | shared |
| `business` | dedicated | shared | shared |
| `enterprise` | dedicated | dedicated | dedicated |

The tier is just a label. What actually switches is whatever `db_config`, `redis_config` and `storage_config` a tenant has.

---

## 1. Install

```bash
composer require buildwithsmk/laravel-tenancy
php artisan migrate
```

This creates the `tenants` table in your central database. To change the options, publish the config:

```bash
php artisan vendor:publish --tag=tenancy-config
```

Set your main domain in `.env`:

```dotenv
CENTRAL_DOMAIN=yourapp.com
```

---

## 2. Identify the tenant

Wrap your tenant routes in the `tenant` middleware:

```php
Route::middleware('tenant')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

For each request:

| Host | Result |
|---|---|
| `yourapp.com` (a central domain) | No tenant. Passes through |
| `acme.yourapp.com` | The tenant whose `subdomain` is `acme` |
| `acme-hotel.com` | The tenant whose `domain` is `acme-hotel.com` |
| Anything else, or an inactive or deleted tenant | 404 |

Only one level under a central domain counts, so `acme.attacker.com` or `x.acme.yourapp.com` never resolve.

Anywhere in your app:

```php
use BuildWithSmk\Tenancy\TenancyManager;

$tenancy = app(TenancyManager::class);

$tenancy->check();    // is a tenant active?
$tenancy->current();  // the Tenant model, or null
app('tenancy.current'); // same, bound while a tenant is active
```

**Trusted hosts.** Let custom domains through Laravel's `TrustHosts`, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustHosts(at: fn () => app(\BuildWithSmk\Tenancy\TenancyManager::class)->trustedHostPatterns());
})
```

**Other ways to find the tenant** (from the logged-in user, a URL segment, a header): write your own middleware and call `initialize()` yourself:

```php
public function handle(Request $request, Closure $next)
{
    $tenancy = app(TenancyManager::class);
    $tenancy->initialize(Tenant::findOrFail($request->user()->tenant_id));

    try {
        return $next($request);
    } finally {
        $tenancy->end();
    }
}
```

---

## 3. Tenant-aware models

For tables in the shared database, add a `tenant_id` column and the trait:

```php
use BuildWithSmk\Tenancy\Concerns\BelongsToTenant;

class Booking extends Model
{
    use BelongsToTenant;
}
```

```php
Schema::create('bookings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->index();
    // ...
});
```

- While a tenant is active, every query gets `WHERE bookings.tenant_id = <current tenant>`.
- `tenant_id` is filled in on create (unless you set it yourself).
- With no tenant active (central admin, console) nothing is filtered.
- To query across tenants anyway: `Booking::withoutTenantScope()->get()`.

Use a different column name with `tenancy.tenant_column`.

> Keep `tenant_id` on your tables even for tenants with a dedicated database. `tenants:upgrade` copies it across, and the scope keeps working the same everywhere.

---

## 4. Creating tenants

```php
use BuildWithSmk\Tenancy\Models\Tenant;

// Shared database (starter)
Tenant::create([
    'name' => 'Acme Hotel',
    'slug' => 'acme',
    'subdomain' => 'acme',            // acme.yourapp.com
    'domain' => 'acme-hotel.com',     // optional custom domain
]);

// Dedicated resources (enterprise)
Tenant::create([
    'name' => 'Big Resort',
    'slug' => 'big-resort',
    'domain' => 'bigresort.com',
    'tier' => 'enterprise',
    'db_config' => ['database' => 'tenant_big_resort', 'host' => '10.0.0.5'],
    'redis_config' => ['host' => '10.0.0.6', 'port' => 6379],
    'storage_config' => ['driver' => 's3', 'bucket' => 'big-resort', 'region' => 'ap-south-1', 'key' => '...', 'secret' => '...'],
]);
```

- `db_config` is **merged over your central connection**, so give only what differs (usually `database`, and maybe `host`, `username`, `password`).
- `redis_config` is a full Redis connection. `storage_config` is a full filesystem disk.
- While the tenant is active, these become the **default** DB connection, cache store and filesystem disk. Your code doesn't change.
- The `tenants` table itself always stays on the central connection.
- Connection details are hidden from `toArray()` / JSON.

Deleting a tenant soft-deletes it. It stops resolving at once, and `$tenant->purgesOn()` tells you when your own cleanup job may remove it for good (`tenancy.deletion_grace_days`, default 30).

---

## 5. Queues, scheduled commands and Octane

The middleware cleans up after every request. Outside a request, you start and end the tenant yourself. `run()` does both, even if the callback throws:

```php
$tenancy = app(TenancyManager::class);

// A scheduled command looping over tenants
Tenant::where('is_active', true)->each(function (Tenant $tenant) use ($tenancy) {
    $tenancy->run($tenant, fn () => app(NightAudit::class)->runFor(today()));
});
```

In a queued job, store the tenant id and wrap `handle()`:

```php
class SendInvoice implements ShouldQueue
{
    public function __construct(public int $tenantId, public int $invoiceId) {}

    public function handle(TenancyManager $tenancy): void
    {
        $tenancy->run(Tenant::findOrFail($this->tenantId), function () {
            // ...
        });
    }
}
```

`initialize()` ends any previous tenant first, and `end()` restores the original default connection, cache store and disk. So one worker can safely serve many tenants in a row.

---

## 6. Artisan commands

```bash
# Run migrations on every dedicated-database tenant
php artisan tenants:migrate
php artisan tenants:migrate --tenant=big-resort --path=database/migrations/tenant

# Move a tenant from the shared DB to its own DB, copying its rows
php artisan tenants:upgrade acme --to=business --database=tenant_acme
php artisan tenants:upgrade acme --to=enterprise --database=tenant_acme --host=10.0.0.5 --username=acme --password=secret
```

`tenants:upgrade`:

1. Runs your migrations on the new database. **Create the empty database first.**
2. Copies every row with that tenant's `tenant_id` from each shared table, in chunks, with foreign key checks off.
3. Sets the tenant's `tier` and `db_config`.

The old rows stay in the shared database. Check the new one, then delete them yourself. Unspecified options (host, port, username, password) default to your central connection's.

---

## 7. With buildwithsmk/laravel-tenant-permission

Install both packages, in any order, and set:

```dotenv
PERMISSION_TENANCY=true
```

That's all. Whenever this package makes a tenant current, permission checks run in that tenant too, and they go back to the global scope on `end()`. You don't need any resolver or middleware. To turn the link off, set `tenancy.permission_bridge` to `false`.

> **Dedicated-database tenants:** the permission tables follow the default connection, so for those tenants roles and permissions are read from the tenant's own database. `tenants:migrate` creates the tables there, but you need to seed that tenant's roles in that database.

---

## 8. Adding your own tenant columns

Add a migration for your columns, extend the model, and point the config at it:

```php
namespace App\Models;

use BuildWithSmk\Tenancy\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    protected $fillable = [
        'name', 'slug', 'domain', 'subdomain', 'tier',
        'db_config', 'redis_config', 'storage_config', 'is_active', 'provisioned_at',
        // yours
        'logo_url', 'primary_color',
    ];

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }
}
```

```php
// config/tenancy.php
'tenant_model' => App\Models\Tenant::class,
```

---

## 9. Upgrading from the old in-repo copy (`smk/tenancy`)

1. Remove the `packages/smk/tenancy` path repository and `smk/tenancy` from composer.json. Then run `composer require buildwithsmk/laravel-tenancy`.
2. Replace the namespace `Smk\Tenancy` with `BuildWithSmk\Tenancy` across `app/`, `bootstrap/`, `config/`, `database/`, `routes/` and `tests/`.
3. **Your existing `tenants` table is left alone.** The package migration skips it because the table already exists.
4. **Branding columns are no longer in the package** (`tagline`, `logo_url`, colours, social links, check-in times…). Your data is safe. So that fresh installs still get these columns:
   - copy the old `2026_08_11_043618_add_branding_fields_to_tenants_table.php` into your `database/migrations/` **with the same filename**, so it counts as already run;
   - extend the Tenant model with those columns in `$fillable` (section 8).
5. `deleted_at` is now part of the package's `tenants` table. If your app has its own migration adding it, wrap that migration in `if (! Schema::hasColumn('tenants', 'deleted_at'))` so fresh installs don't fail.
6. Behaviour changes to know about:
   - `end()` now restores the default DB connection, cache store and disk. Before, a dedicated-database tenant left the default connection switched.
   - The `tenant` middleware ends the tenant even when the request throws.
   - `tenants:upgrade` keeps `tenant_id` on copied rows (before, it dropped it, which made those rows invisible to `BelongsToTenant`). It also takes `--port` and `--force`.
   - `db_config`, `redis_config` and `storage_config` are hidden from `toArray()` / JSON.
   - `tier` is a string column on new installs. Existing enum columns keep working.

---

## Requirements

PHP 8.2+, Laravel 11, 12 or 13.

## License

MIT. See [LICENSE](LICENSE).
