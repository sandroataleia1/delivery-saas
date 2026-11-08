<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Support\Decimal;
use App\Support\ItemComposer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Registra o pedido com os preços atuais, que valem até o acerto. */
class CreateDeliveryOrder
{
    public function __construct(private ItemComposer $composer) {}

    public function handle(array $data): DeliveryOrder
    {
        return DB::transaction(function () use ($data) {
            $user = Auth::user();
            Company::whereKey($user->company_id)->lockForUpdate()->firstOrFail();

            $customer = Customer::findOrFail($data['customer_id']);
            $isDelivery = $data['type'] === DeliveryOrder::DELIVERY;

            $existing = DeliveryOrder::where('client_uuid', $data['client_uuid'])->first();

            if ($existing) {
                return $existing;
            }

            $products = Product::where('company_id', $user->company_id)->whereIn('id', collect($data['items'])->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $composed = $this->composer->composeMany($user->company_id, array_values($data['items']));

            $lines = collect($data['items'])->values()->map(function (array $item, int $index) use ($products, $user, $composed) {
                $product = $products[$item['product_id']];
                $price = $composed[$index]['unit_price'];

                return [
                    'company_id' => $user->company_id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $item['quantity'],
                    'unit_price' => $price,
                    'options' => $composed[$index]['options'],
                    'options_label' => $composed[$index]['options_label'],
                    'total' => Decimal::money(bcmul((string) $item['quantity'], $price, 5)),
                    'notes' => $item['notes'] ?? null,
                ];
            });

            $address = $isDelivery ? $data['address'] : null;

            if ($address) {
                $customer->rememberAddress($address);
            }

            $order = new DeliveryOrder;
            $order->forceFill([
                'company_id' => $user->company_id,
                'number' => (int) DeliveryOrder::max('number') + 1,
                'client_uuid' => $data['client_uuid'],
                'customer_id' => $customer->id,
                'type' => $data['type'],
                'status' => DeliveryOrder::RECEIVED,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'street' => $address['street'] ?? null,
                'number_address' => $address['number'] ?? null,
                'complement' => $address['complement'] ?? null,
                'district' => $address['district'] ?? null,
                'reference' => $address['reference'] ?? null,
                'subtotal' => $lines->reduce(fn (string $sum, array $line) => bcadd($sum, $line['total'], 2), '0.00'),
                'delivery_fee' => $isDelivery ? ($data['delivery_fee'] ?? '0') : '0',
                'payment_method' => $data['payment_method'],
                'change_for' => $data['change_for'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ])->save();

            foreach ($order->items()->createMany($lines->all()) as $item) {
                (new DispatchProduction)->handle($item);
            }

            return $order;
        });
    }
}
