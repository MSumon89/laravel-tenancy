# Changelog

## 1.0.0 (2026-11-23)

First public release, extracted from the private in-repo copy `smk/tenancy`.
The namespace changed from `Smk\Tenancy` to `BuildWithSmk\Tenancy`.

### Added
- `TenancyManager::run($tenant, $callback)`: runs as a tenant, then restores the previous state.
- Automatic link with `buildwithsmk/laravel-tenant-permission`: permission
  checks follow the current tenant (`tenancy.permission_bridge`).
- `tenancy.tenant_column` to rename the `tenant_id` column.
- `tenancy.dynamic.cache_store` for the dedicated cache store name.
- `TenancyManager::isCentralDomain()`, `centralConnection()`, `tenantModel()`,
  `dedicatedDatabaseConfig()`.
- `tenants:migrate --tenant=… --path=…`, and `tenants:upgrade --port --force`.
- Pest test suite (Testbench).

### Fixed
- `end()` left a dedicated-database tenant's connection as the default. It now
  restores the original DB connection, cache store and filesystem disk.
- The `tenant` middleware didn't end the tenant when the request threw.
- The `Tenant` model followed the switched default connection. It now always uses the central one.
- `tenants:upgrade` dropped `tenant_id` from copied rows, which hid them from
  `BelongsToTenant`. It also picked the driver from the connection *name*,
  listed tables from every schema on Laravel 12+, inserted all rows in one
  query, and failed on foreign keys.
- Changing a tenant's domain or subdomain kept the old host resolving until the cache expired.
- Host matching is now case-insensitive.

### Changed
- Requires PHP 8.2+ and Laravel 11+.
- App-specific branding columns and fields were removed from the package.
  Add them in your app (README section 8).
- `tenants` includes `deleted_at`, and `tier` is a string column.
- `db_config`, `redis_config` and `storage_config` are hidden from serialisation.
