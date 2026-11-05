<?php

namespace App\Models\Concerns;

use App\Support\Audit;

/**
 * Registra criação, alteração e exclusão do modelo na auditoria.
 *
 * Personalização no modelo:
 *  - auditExclude(): campos ignorados por completo;
 *  - auditRedact(): campos cuja alteração aparece, mas sem o valor;
 *  - auditLabel(): nome legível do registro;
 *  - auditDisplayValue(): valor legível de um campo (ex.: nome em vez de id).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            Audit::record('created', $model, new: $model->auditValues($model->getAttributes()));
        });

        static::updated(function ($model) {
            $new = $model->auditValues($model->getChanges());

            if ($new !== []) {
                $old = array_intersect_key($model->getRawOriginal(), $new);

                Audit::record('updated', $model, old: $model->auditValues($old), new: $new);
            }
        });

        static::deleted(function ($model) {
            Audit::record('deleted', $model, old: $model->auditValues($model->getRawOriginal()));
        });
    }

    public function auditValues(array $attributes): array
    {
        $values = array_diff_key($attributes, array_flip([
            ...$this->auditExclude(), 'id', 'company_id', 'created_at', 'updated_at',
        ]));

        foreach ($values as $field => $value) {
            $values[$field] = match (true) {
                in_array($field, $this->auditRedact(), true) => '••••••••',
                $this->hasCast($field, ['bool', 'boolean']) => (bool) $value,
                default => $this->auditDisplayValue($field, $value),
            };
        }

        return $values;
    }

    public function auditCompanyId(): ?int
    {
        return $this->company_id;
    }

    public function auditLabel(): string
    {
        return $this->name ?? '#'.$this->getKey();
    }

    protected function auditExclude(): array
    {
        return [];
    }

    protected function auditRedact(): array
    {
        return [];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return $value;
    }
}
