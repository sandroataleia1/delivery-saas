<?php

namespace App\Support;

use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\ProductionWork;
use App\Models\RestaurantOperationSettings;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use App\Models\WaiterNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Grava os avisos do app do garçom: pedido feito pelo QR da mesa e item da produção pronto para levar.
 *
 * Só avisa o que foi de fato gravado (depois do commit). Itens que ficam prontos numa mesma gravação
 * viram um aviso por comanda e setor ("Mesa 5: 2 itens prontos — Bar"). Uma falha aqui nunca desfaz a operação.
 */
class WaiterNotifier
{
    /** @var array<int, ProductionWork> Itens prontos já gravados esperando o aviso sair. */
    private array $committedReady = [];

    private int $lastRegistered = 0;

    public function menuOrder(MenuOrderReceipt $receipt): void
    {
        DB::afterCommit(fn () => $this->safely(fn () => $this->sendMenuOrder($receipt)));
    }

    /** Só itens de mesa avisam o garçom; o delivery segue pelo quadro de entregas. */
    public function ready(ProductionWork $work): void
    {
        if (! $work->table_order_item_id) {
            return;
        }

        $sequence = ++$this->lastRegistered;

        DB::afterCommit(function () use ($work, $sequence) {
            $this->committedReady[] = $work;

            if ($sequence === $this->lastRegistered) {
                $works = $this->committedReady;
                $this->committedReady = [];
                $this->safely(fn () => $this->sendReady(collect($works)));
            }
        });
    }

    private function sendMenuOrder(MenuOrderReceipt $receipt): void
    {
        $order = TableOrder::withoutGlobalScopes()->find($receipt->table_order_id);
        $number = DiningTable::withoutGlobalScopes()->whereKey($receipt->dining_table_id)->value('number');
        $units = $receipt->units === 1 ? '1 item' : $receipt->units.' itens';
        $body = $receipt->status === MenuOrderReceipt::CONFIRMED
            ? $units.' · confirmado automaticamente'
            : $units.' · R$ '.number_format((float) $receipt->total, 2, ',', '.').' · aguarda confirmação';

        $this->store($receipt->company_id, $this->recipients($receipt->company_id, 'tables.confirm', $order?->user_id), [
            'kind' => WaiterNotice::MENU_ORDER, 'dining_table_id' => $receipt->dining_table_id, 'table_order_id' => $receipt->table_order_id,
            'title' => "Mesa {$number}: pedido pelo QR", 'body' => $body,
        ]);
    }

    /** @param  Collection<int, ProductionWork>  $works */
    private function sendReady(Collection $works): void
    {
        $orderIds = TableOrderItem::withoutGlobalScopes()->whereIn('id', $works->pluck('table_order_item_id'))->pluck('table_order_id', 'id');
        $orders = TableOrder::withoutGlobalScopes()->whereIn('id', $orderIds->unique())->get()->keyBy('id');
        $numbers = DiningTable::withoutGlobalScopes()->whereIn('id', $orders->pluck('dining_table_id'))->pluck('number', 'id');

        $groups = $works->groupBy(fn (ProductionWork $work) => $orderIds[$work->table_order_item_id].'-'.$work->production_sector_id);
        foreach ($groups as $group) {
            $order = $orders[$orderIds[$group->first()->table_order_item_id]];
            $count = $group->count() === 1 ? '1 item pronto' : $group->count().' itens prontos';
            $body = $group->map(fn (ProductionWork $work) => $this->quantity($work->quantity).'× '.$work->product_name)->implode(', ');

            $this->store($order->company_id, $this->recipients($order->company_id, 'production.deliver', $order->user_id), [
                'kind' => WaiterNotice::READY, 'dining_table_id' => $order->dining_table_id, 'table_order_id' => $order->id,
                'title' => mb_substr("Mesa {$numbers[$order->dining_table_id]}: {$count} — {$group->first()->sector_name}", 0, 160),
                'body' => mb_substr($body, 0, 255),
            ]);
        }
    }

    /**
     * Quem tem o app (um token de acesso), está ativo e tem a permissão. No modo "só o garçom da mesa",
     * só o responsável — se ele não puder receber (sem app, inativo, sem permissão), avisa todos.
     *
     * @return Collection<int, int>
     */
    private function recipients(int $companyId, string $permission, ?int $responsibleId): Collection
    {
        $ids = User::withoutGlobalScopes()->where('company_id', $companyId)->where('active', true)->whereHas('tokens')->with('role')->get()
            ->filter(fn (User $user) => $user->hasPermission($permission))->pluck('id');
        $mode = RestaurantOperationSettings::withoutGlobalScopes()->where('company_id', $companyId)->value('waiter_notices') ?? 'all';

        return $mode === 'table_waiter' && $ids->contains($responsibleId) ? collect([$responsibleId]) : $ids;
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @param  array<string, mixed>  $notice
     */
    private function store(int $companyId, Collection $userIds, array $notice): void
    {
        $now = now();
        WaiterNotice::withoutGlobalScopes()->insert($userIds->map(fn (int $userId) => [
            'company_id' => $companyId, 'user_id' => $userId, ...$notice, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    /** "2.000" vira "2"; "0.500" vira "0,5". */
    private function quantity(string $quantity): string
    {
        return str_replace('.', ',', rtrim(rtrim($quantity, '0'), '.'));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $error) {
            report($error);
        }
    }
}
