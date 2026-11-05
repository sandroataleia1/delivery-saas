<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isola os registros por empresa e preenche company_id na criação.
 * Todo modelo de dados de empresa deve usar este trait.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if ($companyId = Tenant::id()) {
                $query->where($query->qualifyColumn('company_id'), $companyId);
            }
        });

        static::creating(function ($model) {
            $model->company_id ??= Tenant::id();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
