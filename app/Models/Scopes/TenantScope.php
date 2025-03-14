<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    // public function apply(Builder $builder, Model $model): void
    // {
    //     $tenantId = session('tenant_id');
    //     if ($tenantId) {
    //         $builder->where('tenant_id', $tenantId);
    //     }
    // }

    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = session('tenant_id'); 

        if ($tenantId) {
            $builder->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
            });
        }
    }
}
