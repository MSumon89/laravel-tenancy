<?php

use BuildWithSmk\Tenancy\Models\Tenant;

return [

    /*
    |--------------------------------------------------------------------------
    | Central domains
    |--------------------------------------------------------------------------
    |
    | Requests to these hosts (marketing site, central admin panel) are never
    | resolved to a tenant. Subdomains of these hosts are: "acme.yourapp.com"
    | resolves the tenant whose `subdomain` is "acme". The first entry is also
    | used to build Tenant::url() for tenants without a custom domain.
    |
    */
    'central_domains' => [
        env('CENTRAL_DOMAIN', 'yourapp.com'),
        'localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant model
    |--------------------------------------------------------------------------
    |
    | Swap in your own model to add columns, relations or casts. It must
    | extend BuildWithSmk\Tenancy\Models\Tenant.
    |
    */
    'tenant_model' => Tenant::class,

    /*
    |--------------------------------------------------------------------------
    | Tenant column
    |--------------------------------------------------------------------------
    |
    | Column that BelongsToTenant models use to store their tenant's id.
    |
    */
    'tenant_column' => 'tenant_id',

    /*
    |--------------------------------------------------------------------------
    | Shared (central) resources
    |--------------------------------------------------------------------------
    |
    | Used by every tenant without a dedicated resource. `db_connection` is
    | also the CENTRAL connection: the tenants table always lives there, even
    | while a dedicated-database tenant is active.
    |
    */
    'defaults' => [
        'db_connection' => env('DB_CONNECTION', 'mysql'),
        'redis_connection' => 'default',
        'storage_disk' => 'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic resource names
    |--------------------------------------------------------------------------
    |
    | When a tenant has a dedicated resource, the package registers it under
    | these names and makes it the default for the rest of the request.
    |
    */
    'dynamic' => [
        'db_connection' => 'tenant',
        'redis_connection' => 'tenant',
        'cache_store' => 'tenant',
        'storage_disk' => 'tenant',
    ],

    /*
    |--------------------------------------------------------------------------
    | Host lookup cache
    |--------------------------------------------------------------------------
    |
    | Tenant identification runs on every request, so the host -> tenant id
    | lookup is cached for this many seconds. 0 disables it. Deactivation is
    | still checked on every request.
    |
    */
    'cache_ttl' => 300,

    /*
    |--------------------------------------------------------------------------
    | Deletion grace period
    |--------------------------------------------------------------------------
    |
    | Tenants are soft-deleted. A deleted tenant stops resolving at once and
    | Tenant::purgesOn() returns when your own cleanup job may remove it.
    |
    */
    'deletion_grace_days' => env('TENANT_DELETION_GRACE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | buildwithsmk/laravel-tenant-permission integration
    |--------------------------------------------------------------------------
    |
    | When that package is installed, its permission checks follow the
    | current tenant automatically. Set to false to manage it yourself.
    |
    */
    'permission_bridge' => true,

];
