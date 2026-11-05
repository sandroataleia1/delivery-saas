<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\DiningTable;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenTable
{
    public function handle(DiningTable $table, int $people, User $user): TableOrder
    {
        return DB::transaction(function () use ($table, $people, $user) {
            Company::whereKey($table->company_id)->lockForUpdate()->firstOrFail();

            if (! $table->fresh()->active) {
                throw ValidationException::withMessages(['table' => 'Esta mesa está inativa.']);
            }

            if (TableOrder::where('open_table_id', $table->id)->exists()) {
                throw ValidationException::withMessages(['table' => "A mesa {$table->number} já está ocupada."]);
            }

            $order = new TableOrder;
            $order->forceFill([
                'company_id' => $table->company_id,
                'number' => (int) TableOrder::max('number') + 1,
                'dining_table_id' => $table->id,
                'open_table_id' => $table->id,
                'people' => $people,
                'status' => TableOrder::OPEN,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'opened_at' => now(),
            ])->save();

            return $order;
        });
    }
}
