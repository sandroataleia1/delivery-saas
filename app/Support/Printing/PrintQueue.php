<?php

namespace App\Support\Printing;

use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coloca papéis na fila do agente de impressão.
 *
 * Os itens que vão para a produção numa mesma gravação são juntados por pedido e setor:
 * a cozinha recebe uma comanda com todos os seus itens, não uma folha por item.
 * Tudo só entra na fila depois que o pedido foi gravado, e uma falha aqui nunca desfaz o pedido.
 */
class PrintQueue
{
    /** @var array<int, ProductionWork> Itens já gravados esperando a comanda sair. */
    private array $committed = [];

    private int $lastRegistered = 0;

    /**
     * Cada item só entra na comanda se a gravação dele for confirmada; itens de uma gravação desfeita
     * nunca são impressos. A comanda sai quando o último item registrado é confirmado.
     */
    public function kitchen(ProductionWork $work): void
    {
        $sequence = ++$this->lastRegistered;

        DB::afterCommit(function () use ($work, $sequence) {
            $this->committed[] = $work;

            if ($sequence === $this->lastRegistered) {
                $this->flushKitchen();
            }
        });
    }

    /** Imprime o pedido completo na impressora de pedidos, se houver uma e a impressão for automática. */
    public function order(DeliveryOrder $order): void
    {
        DB::afterCommit(fn () => $this->safely(function () use ($order) {
            if (Company::findOrFail($order->company_id)->auto_print_orders) {
                $this->queueOrder($order);
            }
        }));
    }

    /**
     * Impressão pedida na tela: uma comanda por pedido e setor com os itens informados.
     * Retorna quantas comandas entraram na fila; setores sem impressora ativa ficam de fora.
     *
     * @param  Collection<int, ProductionWork>  $works
     */
    public function queueKitchen(Collection $works): int
    {
        $queued = 0;

        foreach ($this->kitchenGroups($works) as $group) {
            $first = $group->first();
            $sector = ProductionSector::withoutGlobalScopes()->where('company_id', $first->company_id)->find($first->production_sector_id);
            $printer = $this->activePrinter($first->company_id, $sector?->printer_id);

            if ($printer) {
                $this->push($printer, $first->sector_name.' · '.$first->origin_label, Tickets::kitchen($first->sector_name, $first->origin_label, $group, $printer->columns));
                $queued++;
            }
        }

        return $queued;
    }

    /** Reimpressão pedida na tela. Retorna falso quando a empresa não tem impressora de pedidos ativa. */
    public function queueOrder(DeliveryOrder $order): bool
    {
        $company = Company::findOrFail($order->company_id);
        $printer = $this->activePrinter($order->company_id, $company->order_printer_id);

        if (! $printer) {
            return false;
        }

        $this->push($printer, 'Pedido #'.$order->number, Tickets::order($order, $company->trade_name, $printer->columns));

        return true;
    }

    public function test(Printer $printer): void
    {
        $this->push($printer, 'Teste de impressão', Tickets::test($printer->name, $printer->columns));
    }

    private function flushKitchen(): void
    {
        $works = collect($this->committed);
        $this->committed = [];

        foreach ($this->kitchenGroups($works) as $group) {
            $this->safely(function () use ($group) {
                if (Company::findOrFail($group->first()->company_id)->auto_print_kitchen) {
                    $this->queueKitchen($group);
                }
            });
        }
    }

    /**
     * @param  Collection<int, ProductionWork>  $works
     * @return Collection<string, Collection<int, ProductionWork>>
     */
    private function kitchenGroups(Collection $works): Collection
    {
        return $works->groupBy(fn (ProductionWork $work) => $work->company_id.'|'.$work->production_sector_id.'|'.$work->origin_label);
    }

    private function activePrinter(int $companyId, ?int $printerId): ?Printer
    {
        return $printerId === null ? null
            : Printer::withoutGlobalScopes()->where('company_id', $companyId)->where('active', true)->find($printerId);
    }

    private function push(Printer $printer, string $title, string $bytes): void
    {
        $job = new PrintJob;
        $job->forceFill([
            'company_id' => $printer->company_id,
            'printer_id' => $printer->id,
            'title' => mb_substr($title, 0, 120),
            'payload' => base64_encode($bytes),
            'status' => PrintJob::PENDING,
        ])->save();
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
