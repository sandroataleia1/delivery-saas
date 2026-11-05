<?php

namespace App\Actions;

use App\Models\Bill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cria a conta ou as parcelas mensais dela. */
class CreateBills
{
    /**
     * @param  array{type: string, description: string, category: string, amount: string, due_date: string}  $data
     * @return Collection<int, Bill>
     */
    public function handle(array $data, int $installments = 1, ?int $companyId = null): Collection
    {
        return DB::transaction(function () use ($data, $installments, $companyId) {
            $user = Auth::user();
            $group = $installments > 1 ? (string) Str::uuid() : null;
            $firstDue = Carbon::parse($data['due_date']);
            $amounts = $this->split((string) $data['amount'], $installments);

            return collect($amounts)->map(function (string $amount, int $index) use ($data, $user, $group, $firstDue, $installments, $companyId) {
                $bill = new Bill([
                    ...$data,
                    'amount' => $amount,
                    'due_date' => $firstDue->copy()->addMonthsNoOverflow($index),
                ]);

                $bill->company_id = $companyId ?? $user?->company_id;
                $bill->type = $data['type'];
                $bill->stock_entry_id = $data['stock_entry_id'] ?? null;
                $bill->status = Bill::OPEN;
                $bill->installment_group = $group;
                $bill->installment_number = $index + 1;
                $bill->installment_total = $installments;
                $bill->user_id = $user?->getKey();
                $bill->user_name = $user?->name ?? 'Sistema';
                $bill->save();

                return $bill;
            });
        });
    }

    /**
     * Divide em centavos inteiros; a sobra vai para a primeira parcela, para o total bater.
     *
     * @return array<int, string>
     */
    private function split(string $amount, int $installments): array
    {
        $cents = (int) bcmul($amount, '100', 0);
        $base = intdiv($cents, $installments);
        $remainder = $cents - ($base * $installments);

        return array_map(
            fn (int $index) => bcdiv((string) ($base + ($index === 0 ? $remainder : 0)), '100', 2),
            range(0, $installments - 1),
        );
    }
}
