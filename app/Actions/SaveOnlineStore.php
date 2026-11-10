<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Support\Audit;
use App\Support\PublicImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SaveOnlineStore
{
    /**
     * @param  array<string, mixed>  $data  Campos da loja e, em `hours`, o conjunto completo de turnos.
     */
    public function handle(array $data): OnlineStore
    {
        $companyId = Auth::user()->company_id;
        $logo = $data['logo'] ?? null;
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        unset($data['logo'], $data['remove_logo']);
        $newLogoPath = $logo instanceof UploadedFile ? PublicImage::store($logo, $companyId) : null;

        try {
            return $this->save($data, $companyId, $newLogoPath, $removeLogo);
        } catch (\Throwable $e) {
            PublicImage::discard($newLogoPath);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data, int $companyId, ?string $newLogoPath, bool $removeLogo): OnlineStore
    {
        return DB::transaction(function () use ($data, $companyId, $newLogoPath, $removeLogo) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

            $hours = $data['hours'] ?? [];
            unset($data['hours']);

            $store = OnlineStore::where('company_id', $companyId)->first() ?? new OnlineStore;
            $oldLogoPath = $store->logo_path;
            $store->forceFill([...$data, 'company_id' => $companyId]);
            if ($newLogoPath !== null || $removeLogo) {
                $store->logo_path = $newLogoPath;
                PublicImage::deleteAfterCommit($oldLogoPath);
            }
            $store->save();

            $old = OnlineStoreHour::where('company_id', $companyId)->get(['weekday', 'opens_at', 'closes_at'])->toArray();
            OnlineStoreHour::where('company_id', $companyId)->delete();
            $rows = collect($hours)->map(fn (array $shift) => [
                'company_id' => $companyId,
                'weekday' => (int) $shift['weekday'],
                'opens_at' => $shift['opens_at'].':00',
                'closes_at' => $shift['closes_at'].':00',
            ])->all();
            OnlineStoreHour::insert($rows);

            if ($old !== [] || $rows !== []) {
                Audit::record(
                    'hours_updated',
                    $store,
                    'Horários da loja online atualizados.',
                    old: ['hours' => $old],
                    new: ['hours' => array_map(fn (array $row) => collect($row)->only(['weekday', 'opens_at', 'closes_at'])->all(), $rows)],
                );
            }

            return $store;
        });
    }
}
