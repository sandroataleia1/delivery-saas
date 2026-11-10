<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\OnlineStore;
use App\Models\Option;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aceita o pedido da loja online e cria o delivery pelo mesmo núcleo do balcão, com os preços
 * do snapshot. Ordem de lock: Company → pedido online → produtos (por id) → opções (por id).
 */
class AcceptOnlineOrder
{
    public const EXPIRED_REASON = 'Prazo de aceite encerrado.';

    /** Nome gravado como responsável quando a loja aceita sozinha. */
    public const AUTOMATIC = 'Automático';

    public function __construct(private CreateDeliveryOrder $createDeliveryOrder) {}

    public function handle(OnlineOrder $order): DeliveryOrder
    {
        Gate::authorize('online_orders.accept');

        return $this->accept($order, Auth::user());
    }

    /**
     * Aceite sem equipe, logo depois de o cliente enviar o pedido, quando a loja liga o aceite automático.
     * Devolve null se não deu para aceitar: o pedido fica aguardando a equipe, como no modo manual.
     */
    public function handleAutomatic(OnlineOrder $order): ?DeliveryOrder
    {
        $enabled = OnlineStore::withoutGlobalScopes()->where('company_id', $order->company_id)->where('auto_accept', true)->exists();
        if (! $enabled || $order->status !== OnlineOrder::PENDING) {
            return null;
        }

        try {
            return $this->accept($order, null);
        } catch (ValidationException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function accept(OnlineOrder $order, ?User $user): DeliveryOrder
    {
        $companyId = (int) ($user?->company_id ?? $order->company_id);

        // Devolve null quando o prazo venceu: o "expired" precisa ser gravado antes do erro.
        $delivery = DB::transaction(function () use ($order, $user, $companyId): ?DeliveryOrder {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $locked = OnlineOrder::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OnlineOrder::ACCEPTED) {
                return DeliveryOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('online_order_id', $locked->id)->firstOrFail();
            }

            if ($locked->status !== OnlineOrder::PENDING) {
                throw ValidationException::withMessages(['online_order' => 'Este pedido não está mais aguardando aceite.']);
            }

            if (now()->greaterThanOrEqualTo($locked->expires_at)) {
                $locked->forceFill(['status' => OnlineOrder::EXPIRED, 'decided_at' => now(), 'decision_reason' => self::EXPIRED_REASON])->save();

                return null;
            }

            $lines = $locked->items()->get();
            $this->ensureAvailable($companyId, $lines);

            $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($locked->customer_id);
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

            $locked->forceFill(['status' => OnlineOrder::ACCEPTED, 'decided_at' => now(), 'decided_by_id' => $user?->id, 'decided_by_name' => $user?->name ?? self::AUTOMATIC])->save();

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
        $products = Product::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $productIds)
            ->where('active', true)->where('sellable', true)->where('sold_out', false)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $optionIds = $lines->flatMap(fn (OnlineOrderItem $line): array => [
            ...array_column($line->options['flavors'] ?? [], 'id'),
            ...array_column($line->options['extras'] ?? [], 'id'),
        ])->unique()->sort()->values();
        $options = $optionIds->isEmpty() ? collect() : Option::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $optionIds)
            ->where('active', true)->where('paused', false)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $hasMissingProduct = $productIds->contains(fn (int $id): bool => ! $products->has($id));
        $hasMissingOption = $optionIds->contains(fn (int $id): bool => ! $options->has($id));

        if ($hasMissingProduct || $hasMissingOption) {
            throw ValidationException::withMessages(['online_order' => 'Um item ficou indisponível. Recuse o pedido ou fale com o cliente.']);
        }
    }
}
