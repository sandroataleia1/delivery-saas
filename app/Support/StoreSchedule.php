<?php

namespace App\Support;

use App\Models\OnlineStore;
use Carbon\CarbonImmutable;

/**
 * Regras de funcionamento da loja online: grade de turnos semanais e pausa manual.
 * Sempre calcula no fuso de São Paulo; o intervalo é fechado na abertura e aberto no fechamento.
 */
class StoreSchedule
{
    public const TIMEZONE = 'America/Sao_Paulo';

    private const WEEKDAYS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    public static function isOpen(OnlineStore $store, CarbonImmutable $at): bool
    {
        if (! $store->enabled || $store->isPaused()) {
            return false;
        }

        $local = $at->setTimezone(self::TIMEZONE);
        $minute = $local->hour * 60 + $local->minute;
        $weekday = $local->dayOfWeek;
        $previousWeekday = ($weekday + 6) % 7;

        foreach ($store->hours as $shift) {
            $opens = self::minutes($shift->opens_at);
            $closes = self::minutes($shift->closes_at);
            $overnight = $closes <= $opens;

            if ((int) $shift->weekday === $weekday && $minute >= $opens && ($overnight || $minute < $closes)) {
                return true;
            }

            if ((int) $shift->weekday === $previousWeekday && $overnight && $minute < $closes) {
                return true;
            }
        }

        return false;
    }

    /**
     * Próxima abertura em até 7 dias, ignorando a pausa e o estado habilitado.
     */
    public static function nextOpening(OnlineStore $store, CarbonImmutable $at): ?CarbonImmutable
    {
        $local = $at->setTimezone(self::TIMEZONE);

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $local->startOfDay()->addDays($offset);
            $candidates = $store->hours
                ->filter(fn ($shift) => (int) $shift->weekday === $day->dayOfWeek)
                ->map(fn ($shift) => $day->addMinutes(self::minutes($shift->opens_at)))
                ->filter(fn (CarbonImmutable $opening) => $opening->greaterThan($local))
                ->sort(fn (CarbonImmutable $a, CarbonImmutable $b) => $a->getTimestamp() <=> $b->getTimestamp());

            if ($candidates->isNotEmpty()) {
                return $candidates->first();
            }
        }

        return null;
    }

    public static function label(OnlineStore $store, CarbonImmutable $at): string
    {
        if (self::isOpen($store, $at)) {
            return 'Aberto';
        }

        $next = $store->isPaused() ? null : self::nextOpening($store, $at);

        if ($next === null) {
            return 'Fechado no momento';
        }

        $today = $at->setTimezone(self::TIMEZONE)->startOfDay();
        $when = match (true) {
            $next->isSameDay($today) => 'hoje',
            $next->isSameDay($today->addDay()) => 'amanhã',
            default => self::WEEKDAYS[$next->dayOfWeek],
        };
        $time = $next->minute === 0 ? $next->hour.'h' : $next->hour.'h'.$next->format('i');

        return "Fechado · abre {$when} às {$time}";
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
