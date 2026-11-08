<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\User;
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

            $lines = collect($data['items'])->values()->map(function (array $item, int $index) use ($products, $composed) {
                $product = $products[$item['product_id']];
                $price = $composed[$index]['unit_price'];

                return [
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
            })->all();

            $address = $isDelivery ? $data['address'] : null;

            if ($address) {
                $customer->rememberAddress($address);
            }

            return $this->persist($user, $customer, [
                'client_uuid' => $data['client_uuid'],
                'type' => $data['type'],
                'street' => $address['street'] ?? null,
                'number_address' => $address['number'] ?? null,
                'complement' => $address['complement'] ?? null,
                'district' => $address['district'] ?? null,
                'reference' => $address['reference'] ?? null,
                'delivery_fee' => $isDelivery ? ($data['delivery_fee'] ?? '0') : '0',
                'payment_method' => $data['payment_method'],
                'change_for' => $data['change_for'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $lines);
        });
    }

    /**
     * Núcleo comum ao balcão e ao aceite da loja online: numera, grava o pedido com as linhas
     * já compostas e encaminha cada linha à produção. Não trava nada: quem chama já está numa
     * transação com a empresa travada.
     *
     * @param  array{client_uuid: string, type: string, street?: ?string, number_address?: ?string, complement?: ?string, district?: ?string, reference?: ?string, delivery_fee: string, payment_method: string, change_for?: ?string, notes?: ?string}  $header
     * @param  array<int, array{product_id: int, product_name: string, unit: string, quantity: mixed, unit_price: string, options: mixed, options_label: ?string, total: string, notes: ?string}>  $lines
     */
    public function persist(User $user, Customer $customer, array $header, array $lines, ?int $onlineOrderId = null): DeliveryOrder
    {
        $order = new DeliveryOrder;
        $order->forceFill([
            'company_id' => $user->company_id,
            'number' => (int) DeliveryOrder::where('company_id', $user->company_id)->max('number') + 1,
            'client_uuid' => $header['client_uuid'],
            'customer_id' => $customer->id,
            'type' => $header['type'],
            'status' => DeliveryOrder::RECEIVED,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'street' => $header['street'] ?? null,
            'number_address' => $header['number_address'] ?? null,
            'complement' => $header['complement'] ?? null,
            'district' => $header['district'] ?? null,
            'reference' => $header['reference'] ?? null,
            'subtotal' => array_reduce($lines, fn (string $sum, array $line) => bcadd($sum, (string) $line['total'], 2), '0.00'),
            'delivery_fee' => $header['delivery_fee'],
            'payment_method' => $header['payment_method'],
            'change_for' => $header['change_for'] ?? null,
            'notes' => $header['notes'] ?? null,
            'online_order_id' => $onlineOrderId,
            'user_id' => $user->id,
            'user_name' => $user->name,
        ])->save();

        $rows = array_map(fn (array $line) => ['company_id' => $user->company_id, ...$line], $lines);

        foreach ($order->items()->createMany($rows) as $item) {
            (new DispatchProduction)->handle($item);
        }

        return $order;
    }
}
