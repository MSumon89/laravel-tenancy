# Developer notes

Start here if you maintain this package. For *using* it, read the README.

## Layout

```
config/tenancy.php              every option, documented inline
database/migrations/            tenants table (skipped if it already exists)
src/
  TenancyServiceProvider.php    config, migrations, `tenant` alias, commands
  TenancyManager.php            THE core service: identify, initialize/end/run, resources
  Models/Tenant.php             always on the central connection
  Concerns/BelongsToTenant      global scope + tenant_id auto-fill
  Concerns/TenantScope
  Middleware/IdentifyTenant     host -> tenant, always ends in `finally`
  Console/Commands/             tenants:migrate, tenants:upgrade
tests/
  Feature/                      Testbench, SQLite (in-memory central, temp files for dedicated DBs)
  Fixtures/                     Booking model + bookings table
```

## Rules for changes

- **Whatever `initialize()` changes, `end()` must undo.** Record the replaced
  default in `$replaced` and restore it in `end()`. Workers serve many tenants
  in one process.
- `TenancyManager` is a singleton (apps inject it into controllers and commands).
  Keep its state limited to the current tenant.
- The `Tenant` model must never query through a tenant's connection. It uses
  `centralConnection()`.
- Never hard-code `tenant_id`. Use `config('tenancy.tenant_column')`.
- The permission package stays **optional**: refer to it only by class-name
  string, behind `class_exists()`. It is a dev dependency for tests only.
- New migrations: add the suffix to `TenancyServiceProvider::MIGRATIONS` and
  guard with `hasTable` / `hasColumn`.
- No app- or domain-specific columns (hotels, branding, billing). Apps extend
  the Tenant model instead.
- Keep the public API backward compatible within a major version.

## Running things

```bash
composer install
composer test            # vendor/bin/pest
composer format          # vendor/bin/pint
```

## Trying it inside a real app before releasing

```json
"repositories": [
    { "type": "path", "url": "../laravel-tenancy", "options": { "symlink": true } }
],
"require": { "buildwithsmk/laravel-tenancy": "*@dev" }
```

Then run `composer update buildwithsmk/laravel-tenancy`.

## Releasing

1. Update `CHANGELOG.md`. Don't add a `version` field to composer.json.
2. `composer test && composer format`
3. Commit, push, then tag: `git tag -a v1.0.1 -m "v1.0.1" && git push origin v1.0.1`
4. Create a GitHub Release from the tag and paste the CHANGELOG section.
5. Packagist picks it up via the GitHub hook (or click **Update**).

Never move or re-push an existing tag. Release a new version instead.

**Where it lives**

- Packagist: `buildwithsmk/laravel-tenancy`
- GitHub: `github.com/MSumon89/laravel-tenancy`
- PHP namespace: `BuildWithSmk\Tenancy`
