<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use LogicException;

/**
 * Registro imutável: pode ser criado e lido, nunca alterado ou excluído.
 * No MariaDB/MySQL a trava também existe em triggers, na migration da tabela.
 */
class AuditLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A auditoria não pode ser alterada.'));
        static::deleting(fn () => throw new LogicException('A auditoria não pode ser excluída.'));
    }

    /** Formato legível usado nas telas. */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->created_at->format('d/m/Y H:i:s'),
            'user' => $this->user_name,
            'event' => $this->event,
            'event_label' => __("audit.events.{$this->event}"),
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'url' => $this->url,
            'changes' => $this->changes(),
        ];
    }

    private function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        return collect(array_keys($old + $new))->map(fn (string $field) => [
            'field' => Lang::has("audit.fields.$field") ? __("audit.fields.$field") : $field,
            'old' => self::readable($old[$field] ?? null),
            'new' => self::readable($new[$field] ?? null),
        ])->all();
    }

    private static function readable(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_bool($value) => $value ? 'Sim' : 'Não',
            is_array($value) => implode(', ', array_map(self::listItem(...), $value)),
            default => (string) $value,
        };
    }

    /** Item de lista: turno de horário, chave de permissão ou, para outros formatos, os valores em sequência. */
    private static function listItem(mixed $item): string
    {
        if (! is_array($item)) {
            return self::permissionLabel($item);
        }

        if (isset($item['weekday'], $item['opens_at'], $item['closes_at'])) {
            $weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

            return ($weekdays[(int) $item['weekday']] ?? $item['weekday']).' '
                .substr((string) $item['opens_at'], 0, 5).'–'.substr((string) $item['closes_at'], 0, 5);
        }

        return implode(' ', array_map(
            fn (mixed $part): string => is_scalar($part) ? (string) $part : (string) json_encode($part, JSON_UNESCAPED_UNICODE),
            $item,
        ));
    }

    private static function permissionLabel(mixed $key): string
    {
        return collect(config('permissions.groups'))
            ->flatMap(fn (array $group) => $group['permissions'])
            ->get($key, (string) $key);
    }
}
