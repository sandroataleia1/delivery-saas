<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Registra o pedido com os preços atuais, que valem até o acerto. */
class CreateDeliveryOrder
{
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

            $products = Product::findMany(collect($data['items'])->pluck('product_id'))->keyBy('id');

            $lines = collect($data['items'])->map(function (array $item) use ($products, $user) {
                $product = $products[$item['product_id']];

                return [
                    'company_id' => $user->company_id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->sale_price,
                    'total' => Decimal::money(bcmul((string) $item['quantity'], $product->sale_price, 5)),
                    'notes' => $item['notes'] ?? null,
                ];
            });

            $address = $isDelivery ? $data['address'] : null;

            if ($address) {
                $this->rememberAddress($customer, $address);
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

            $order->items()->createMany($lines->all());

            return $order;
        });
    }

    private function rememberAddress(Customer $customer, array $address): void
    {
        $known = $customer->addresses()
            ->where('street', $address['street'])
            ->where('number', $address['number'])
            ->exists();

        if (! $known) {
            $saved = $customer->addresses()->make($address);
            $saved->company_id = $customer->company_id;
            $saved->save();
        }
    }
}
