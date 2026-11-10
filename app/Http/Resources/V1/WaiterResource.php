<?php

namespace App\Http\Resources\V1;

use App\Models\OnlineStore;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class WaiterResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, permissions: array<int, string>, company: array{id: int, name: string, logo_url: ?string}}
     */
    public function toArray(Request $request): array
    {
        $store = OnlineStore::withoutGlobalScopes()->where('company_id', $this->company_id)->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'permissions' => array_values($this->permissions()),
            'company' => [
                'id' => $this->company_id,
                'name' => $this->company->trade_name,
                'logo_url' => $store?->logoUrl(),
            ],
        ];
    }
}
