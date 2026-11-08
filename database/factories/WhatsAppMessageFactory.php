<?php

namespace Database\Factories;

use App\Models\OnlineOrder;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Exige `online_order_id` (não há factory de OnlineOrder); `company_id` sai do pedido.
 *
 * @extends Factory<WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes) => OnlineOrder::query()->withoutGlobalScopes()->findOrFail($attributes['online_order_id'])->company_id,
            'event' => 'received',
            'phone' => '5511988887777',
            'body' => 'Recebemos seu pedido.',
            'status' => WhatsAppMessage::PENDING,
            'attempts' => 0,
        ];
    }
}
