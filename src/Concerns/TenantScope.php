<?php

namespace BuildWithSmk\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * WHERE tenant_id = <current tenant>, while a tenant is active.
 * Without a current tenant (central admin, console) nothing is filtered.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound('tenancy.current') && app('tenancy.current')) {
            $builder->where(
                $model->qualifyColumn(config('tenancy.tenant_column', 'tenant_id')),
                app('tenancy.current')->getKey()
            );
        }
    }
}
