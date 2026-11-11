<?php

namespace App\Http\Resources\V1;

use App\Models\WaiterNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WaiterNotice
 */
class WaiterNoticeResource extends JsonResource
{
    /**
     * @return array{id: int, kind: string, title: string, body: ?string, table_id: ?int, order_id: ?int, read: bool, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'table_id' => $this->dining_table_id,
            'order_id' => $this->table_order_id,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
