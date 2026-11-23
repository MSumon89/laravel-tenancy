<?php

namespace BuildWithSmk\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * For models stored in the shared database with a tenant_id column:
 * queries are scoped to the current tenant, and tenant_id is filled in
 * on create.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $column = config('tenancy.tenant_column', 'tenant_id');

            if (empty($model->{$column}) && app()->bound('tenancy.current') && app('tenancy.current')) {
                $model->{$column} = app('tenancy.current')->getKey();
            }
        });
    }

    /**
     * Escape hatch for central-side queries across every tenant.
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
