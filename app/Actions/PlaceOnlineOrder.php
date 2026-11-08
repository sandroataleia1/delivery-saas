<?php

namespace App\Actions;

use App\Http\Requests\StoreOnlineOrderRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\OnlineStore;
use App\Models\Product;
use App\Support\BrazilPhone;
use App\Support\Decimal;
use App\Support\ItemComposer;
use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recebe o pedido da loja online pública. Nada aqui tem usuário logado nem escopo de tenant:
 * toda consulta filtra company_id e todo registro novo recebe company_id explicitamente.
 * O pedido fica pendente até a equipe aceitar; nada de entrega, produção, venda ou estoque.
 */
class PlaceOnlineOrder
{
    public const GUEST = 'Cliente (loja online)';

    public function __construct(private ItemComposer $composer) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{order: OnlineOrder, token: string}
     */
    public function handle(OnlineStore $store, array $data): array
    {
        $data = Validator::make($data, StoreOnlineOrderRequest::payloadRules())->validate();
        $companyId = (int) $store->company_id;
        $isDelivery = $data['type'] === DeliveryOrder::DELIVERY;
        $phone = (string) BrazilPhone::normalize($data['customer']['phone']);
        $name = trim($data['customer']['name']);
        $address = $isDelivery ? [
            'street' => trim($data['address']['street']),
            'number' => trim($data['address']['number']),
            'complement' => trim($data['address']['complement'] ?? '') ?: null,
            'reference' => trim($data['address']['reference'] ?? '') ?: null,
            'delivery_zone_id' => (int) $data['address']['delivery_zone_id'],
        ] : null;
        $paysCash = $data['payment_method'] === 'cash';
        $changeFor = $paysCash && isset($data['change_for']) ? Decimal::money((string) $data['change_for']) : null;
        $notes = trim($data['notes'] ?? '') ?: null;

        $lines = array_map(fn (array $line): array => [
            'product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'],
            'notes' => trim($line['notes'] ?? '') ?: null, ...$this->normalizedSelection($line),
        ], array_values($data['items']));
        $hash = hash('sha256', json_encode([$companyId, $data['type'], $name, $phone, $address, $lines, $data['payment_method'], $changeFor, $notes], JSON_THROW_ON_ERROR));
        $rawSelections = array_map(fn (array $line): array => [
            'product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'],
            'notes' => trim($line['notes'] ?? '') ?: null, ...$this->rawSelection($line),
        ], array_values($data['items']));

        return DB::transaction(function () use ($store, $companyId, $data, $isDelivery, $phone, $name, $address, $changeFor, $notes, $lines, $rawSelections, $hash): array {
            $company = Company::whereKey($companyId)->lockForUpdate()->first();
            abort_unless($company && $company->active, 404);

            $existing = OnlineOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->content_hash, $hash), 409, 'Identificador já utilizado com outro conteúdo.');

                return ['order' => $existing, 'token' => $existing->tracking_token];
            }

            $store = OnlineStore::withoutGlobalScopes()->whereKey($store->id)->where('company_id', $companyId)->with('hours')->firstOrFail();
            $now = CarbonImmutable::now();
            if (! StoreSchedule::isOpen($store, $now)) {
                throw ValidationException::withMessages(['store' => 'A loja está fechada agora.']);
            }

            if (! ($isDelivery ? $store->accepts_delivery : $store->accepts_pickup)) {
                throw ValidationException::withMessages(['type' => 'A loja não aceita este tipo de pedido.']);
            }

            $zone = $isDelivery ? DeliveryZone::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($address['delivery_zone_id'])->first() : null;
            if ($isDelivery && $zone === null) {
                throw ValidationException::withMessages(['address.delivery_zone_id' => 'Não entregamos neste bairro.']);
            }

            $products = Product::withoutGlobalScopes()->where('company_id', $companyId)->where('active', true)->where('sellable', true)->where('sold_out', false)
                ->whereIn('id', array_column($lines, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $index => $line) {
                if (! $products->has($line['product_id'])) {
                    throw ValidationException::withMessages(["items.$index.product_id" => 'Produto indisponível.']);
                }
            }

            $composed = $this->composer->composeMany($companyId, $rawSelections);
            $snapshots = [];
            $subtotal = '0.00';
            foreach ($lines as $index => $line) {
                $product = $products->get($line['product_id']);
                $unitPrice = $composed[$index]['unit_price'];
                $lineTotal = Decimal::money(bcmul((string) $line['quantity'], $unitPrice, 5));
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $snapshots[] = ['product_id' => $product->id, 'product_name' => $product->name, 'unit' => $product->unit,
                    'quantity' => $line['quantity'], 'unit_price' => $unitPrice, 'total' => $lineTotal,
                    'options' => $composed[$index]['options'], 'options_label' => $composed[$index]['options_label'], 'notes' => $line['notes']];
            }
            $deliveryFee = $zone ? bcadd((string) $zone->fee, '0', 2) : '0.00';
            $total = bcadd($subtotal, $deliveryFee, 2);

            if (bccomp($subtotal, (string) $store->min_order, 2) < 0) {
                throw ValidationException::withMessages(['items' => 'Pedido mínimo de R$ '.Decimal::currency($store->min_order).'.']);
            }
            if ($changeFor !== null && bccomp($changeFor, $total, 2) < 0) {
                throw ValidationException::withMessages(['change_for' => 'O troco deve ser maior que o total.']);
            }

            $pending = OnlineOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('customer_phone', $phone)
                ->where('status', OnlineOrder::PENDING)->where('expires_at', '>', $now)->count();
            if ($pending >= (int) config('online.max_pending_per_phone')) {
                throw ValidationException::withMessages(['customer.phone' => 'Você já tem pedidos aguardando a loja. Aguarde a resposta.']);
            }

            $customer = $this->resolveCustomer($companyId, $phone, $name);
            if ($zone) {
                $customer->rememberAddress([...array_diff_key($address, ['delivery_zone_id' => true]), 'district' => $zone->name]);
            }

            $token = Str::random(48);
            $order = new OnlineOrder;
            $order->forceFill([
                'company_id' => $companyId,
                'number' => (int) OnlineOrder::withoutGlobalScopes()->where('company_id', $companyId)->max('number') + 1,
                'client_uuid' => $data['client_uuid'],
                'content_hash' => $hash,
                'tracking_token_hash' => hash('sha256', $token),
                'tracking_token' => $token,
                'status' => OnlineOrder::PENDING,
                'type' => $data['type'],
                'customer_id' => $customer->id,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'street' => $address['street'] ?? null,
                'number_address' => $address['number'] ?? null,
                'complement' => $address['complement'] ?? null,
                'district' => $zone?->name,
                'reference' => $address['reference'] ?? null,
                'delivery_zone_id' => $zone?->id,
                'zone_name' => $zone?->name,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'payment_method' => $data['payment_method'],
                'change_for' => $changeFor,
                'notes' => $notes,
                'expires_at' => $now->addMinutes((int) $store->accept_minutes),
            ])->save();

            foreach ($snapshots as $snapshot) {
                (new OnlineOrderItem)->forceFill(['company_id' => $companyId, 'online_order_id' => $order->id, ...$snapshot])->save();
            }

            return ['order' => $order, 'token' => $token];
        });
    }

    /**
     * Clientes antigos guardam o telefone como a equipe digitou: filtra pelos últimos 8 dígitos
     * em qualquer formato e confere a normalização em PHP. Vence o menor id. Nunca altera o nome.
     */
    private function resolveCustomer(int $companyId, string $phone, string $name): Customer
    {
        $pattern = '%'.implode('%', str_split(substr($phone, -8))).'%';
        $existing = Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('phone', 'like', $pattern)->orderBy('id')->get()
            ->first(fn (Customer $candidate): bool => BrazilPhone::normalize((string) $candidate->phone) === $phone);

        if ($existing) {
            return $existing;
        }

        $customer = new Customer;
        $customer->forceFill(['company_id' => $companyId, 'name' => $name, 'phone' => $phone])->save();

        return $customer;
    }

    /**
     * Seleção na ordem escolhida pelo cliente, usada só para compor o rótulo.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function rawSelection(array $line): array
    {
        $selection = $this->normalizedSelection($line);
        if (! empty($line['flavor_ids'])) {
            $selection['flavor_ids'] = array_map('intval', $line['flavor_ids']);
        }

        return $selection;
    }

    /**
     * Seleção normalizada (ids ordenados) para o content_hash. Linhas sem composição não ganham chaves.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizedSelection(array $line): array
    {
        $selection = [];
        if (! empty($line['size_id'])) {
            $selection['size_id'] = (int) $line['size_id'];
        }
        if (! empty($line['flavor_ids'])) {
            $flavorIds = array_map('intval', $line['flavor_ids']);
            sort($flavorIds);
            $selection['flavor_ids'] = $flavorIds;
        }
        if (! empty($line['options'])) {
            $options = array_map(fn (array $option): array => ['option_id' => (int) $option['option_id'], 'quantity' => (int) ($option['quantity'] ?? 1)], $line['options']);
            usort($options, fn (array $a, array $b): int => [$a['option_id'], $a['quantity']] <=> [$b['option_id'], $b['quantity']]);
            $selection['options'] = $options;
        }

        return $selection;
    }
}
