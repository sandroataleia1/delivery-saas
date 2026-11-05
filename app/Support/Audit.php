<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Ponto único de escrita da auditoria. Se a gravação falhar a exceção sobe de
 * propósito: nenhuma operação deve acontecer sem deixar registro.
 */
class Audit
{
    public static function record(
        string $event,
        ?Model $subject = null,
        ?string $description = null,
        array $old = [],
        array $new = [],
        ?User $actor = null,
        ?int $companyId = null,
        ?string $actorName = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = Request::instance();
        $isHttp = ! app()->runningInConsole() || app()->runningUnitTests();

        $log = new AuditLog;

        $log->forceFill([
            'company_id' => $companyId ?? self::companyIdFor($subject, $actor),
            'user_id' => $actor?->getKey(),
            'user_name' => $actorName ?? $actor?->name ?? 'Sistema',
            'event' => $event,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'description' => Str::limit($description ?? self::describe($event, $subject), 250),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $isHttp ? $request->ip() : null,
            'user_agent' => $isHttp ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'url' => $isHttp ? Str::limit($request->method().' '.$request->path(), 490, '') : null,
        ]);

        $log->save();

        return $log;
    }

    private static function companyIdFor(?Model $subject, ?User $actor): ?int
    {
        if ($subject && method_exists($subject, 'auditCompanyId')) {
            return $subject->auditCompanyId() ?? $actor?->company_id;
        }

        return $actor?->company_id;
    }

    private static function describe(string $event, ?Model $subject): string
    {
        $eventLabel = __("audit.events.$event");

        if (! $subject) {
            return $eventLabel;
        }

        $model = __('audit.models.'.class_basename($subject));
        $label = method_exists($subject, 'auditLabel') ? $subject->auditLabel() : '#'.$subject->getKey();

        return "$eventLabel de $model: $label";
    }
}
