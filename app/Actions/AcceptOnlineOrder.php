<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\Option;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aceita o pedido da loja online e cria o delivery pelo mesmo núcleo do balcão, com os preços
 * do snapshot. Ordem de lock: Company → pedido online → produtos (por id) → opções (por id).
 */
class AcceptOnlineOrder
{
    public const EXPIRED_REASON = 'Prazo de aceite encerrado.';

    public function __construct(private CreateDeliveryOrder $createDeliveryOrder) {}

    public function handle(OnlineOrder $order): DeliveryOrder
    {
        Gate::authorize('online_orders.accept');
        $user = Auth::user();

        // Devolve null quando o prazo venceu: o "expired" precisa ser gravado antes do erro.
        $delivery = DB::transaction(function () use ($order, $user): ?DeliveryOrder {
            Company::whereKey($user->company_id)->lockForUpdate()->firstOrFail();
            $locked = OnlineOrder::where('company_id', $user->company_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OnlineOrder::ACCEPTED) {
                return DeliveryOrder::where('company_id', $user->company_id)->where('online_order_id', $locked->id)->firstOrFail();
            }

            if ($locked->status !== OnlineOrder::PENDING) {
                throw ValidationException::withMessages(['online_order' => 'Este pedido não está mais aguardando aceite.']);
            }

            if (now()->greaterThanOrEqualTo($locked->expires_at)) {
                $locked->forceFill(['status' => OnlineOrder::EXPIRED, 'decided_at' => now(), 'decision_reason' => self::EXPIRED_REASON])->save();

                return null;
            }

            $lines = $locked->items()->get();
            $this->ensureAvailable($user->company_id, $lines);

            $customer = Customer::where('company_id', $user->company_id)->findOrFail($locked->customer_id);
            $delivery = $this->createDeliveryOrder->persist($user, $customer, [
                'client_uuid' => (string) Str::uuid(),
                'type' => $locked->type,
                'street' => $locked->street,
                'number_address' => $locked->number_address,
                'complement' => $locked->complement,
                'district' => $locked->zone_name,
                'reference' => $locked->reference,
                'delivery_fee' => (string) $locked->delivery_fee,
                'payment_method' => $locked->payment_method,
                'change_for' => $locked->change_for,
                'notes' => $locked->notes,
            ], $lines->map(fn (OnlineOrderItem $line): array => $line->only(
                'product_id', 'product_name', 'unit', 'quantity', 'unit_price', 'total', 'options', 'options_label', 'notes',
            ))->all(), $locked->id);

            $locked->forceFill(['status' => OnlineOrder::ACCEPTED, 'decided_at' => now(), 'decided_by_id' => $user->id, 'decided_by_name' => $user->name])->save();

            return $delivery;
        });

        if ($delivery === null) {
            throw ValidationException::withMessages(['online_order' => 'O prazo de aceite deste pedido venceu.']);
        }

        return $delivery;
    }

    /**
     * Trava e revalida os produtos e as opções do snapshot, nesta ordem e por id.
     *
     * @param  Collection<int, OnlineOrderItem>  $lines
     */
    private function ensureAvailable(int $companyId, Collection $lines): void
    {
        $productIds = $lines->pluck('product_id')->unique()->sort()->values();
        $products = Product::where('company_id', $companyId)->whereIn('id', $productIds)
            ->where('active', true)->where('sellable', true)->where('sold_out', false)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $optionIds = $lines->flatMap(fn (OnlineOrderItem $line): array => [
            ...array_column($line->options['flavors'] ?? [], 'id'),
            ...array_column($line->options['extras'] ?? [], 'id'),
        ])->unique()->sort()->values();
        $options = $optionIds->isEmpty() ? collect() : Option::where('company_id', $companyId)->whereIn('id', $optionIds)
            ->where('active', true)->where('paused', false)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $hasMissingProduct = $productIds->contains(fn (int $id): bool => ! $products->has($id));
        $hasMissingOption = $optionIds->contains(fn (int $id): bool => ! $options->has($id));

        if ($hasMissingProduct || $hasMissingOption) {
            throw ValidationException::withMessages(['online_order' => 'Um item ficou indisponível. Recuse o pedido ou fale com o cliente.']);
        }
    }
}
