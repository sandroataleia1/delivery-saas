<?php

namespace App\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Período dos relatórios: mês atual por padrão, no máximo pouco mais de um ano. */
readonly class Period
{
    public function __construct(public Carbon $from, public Carbon $to) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [], ['from' => 'data inicial', 'to' => 'data final']);

        $from = Carbon::parse($data['from'] ?? today()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? today())->endOfDay();

        abort_if($from->diffInDays($to) > 400, 422, 'Escolha um período de até 400 dias.');

        return new self($from, $to);
    }

    /** @return array<int, Carbon> */
    public function range(): array
    {
        return [$this->from, $this->to];
    }

    /** @return array<int, string> */
    public function days(): array
    {
        $days = [];

        for ($day = $this->from->copy(); $day->lte($this->to); $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }
}
