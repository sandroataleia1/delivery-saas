<?php

namespace App\Actions;

use App\Models\DiningTable;
use App\Models\TableOrder;
use App\Models\User;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenTable
{
    /** Sem usuário, a mesa está sendo aberta pelo próprio cliente, no QR Code. */
    public function handle(DiningTable $table, int $people, ?User $user, ?string $guestName = null): TableOrder
    {
        return DB::transaction(function () use ($table, $people, $user, $guestName) {
            $table = TableOperationLock::table($table);

            if (! $table->fresh()->active) {
                throw ValidationException::withMessages(['table' => 'Esta mesa está inativa.']);
            }

            if (TableOrder::where('open_table_id', $table->id)->exists()) {
                throw ValidationException::withMessages(['table' => "A mesa {$table->number} já está ocupada."]);
            }

            $order = new TableOrder;
            $order->forceFill([
                'company_id' => $table->company_id,
                'number' => (int) TableOrder::withoutGlobalScopes()->where('company_id', $table->company_id)->max('number') + 1,
                'dining_table_id' => $table->id,
                'open_table_id' => $table->id,
                'people' => $people,
                'status' => TableOrder::OPEN,
                'user_id' => $user?->id,
                'user_name' => $user->name ?? $guestName,
                'opened_at' => now(),
            ])->save();

            return $order;
        });
    }
}
