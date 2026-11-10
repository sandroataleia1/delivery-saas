<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PauseOnlineStore
{
    public function pause(?string $reason): OnlineStore
    {
        return DB::transaction(function () use ($reason) {
            $store = $this->lockedStore();
            $reason = filled($reason) ? trim($reason) : null;

            $store->forceFill([
                'paused_at' => now(),
                'paused_by_name' => Auth::user()->name,
                'paused_reason' => $reason,
            ])->save();

            Audit::record('paused', $store, $reason ? "Loja online pausada: {$reason}" : 'Loja online pausada.');

            return $store;
        });
    }

    public function resume(): OnlineStore
    {
        return DB::transaction(function () {
            $store = $this->lockedStore();

            $store->forceFill([
                'paused_at' => null,
                'paused_by_name' => null,
                'paused_reason' => null,
            ])->save();

            Audit::record('resumed', $store, 'Loja online reaberta.');

            return $store;
        });
    }

    private function lockedStore(): OnlineStore
    {
        $companyId = Auth::user()->company_id;
        Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

        return OnlineStore::where('company_id', $companyId)->firstOrFail();
    }
}
