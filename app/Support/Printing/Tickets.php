<?php

namespace App\Support\Printing;

use App\Models\DeliveryOrder;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/** O conteúdo de cada papel impresso: comanda da cozinha, pedido completo, pré-conta da mesa e página de teste. */
class Tickets
{
    /** @param  Collection<int, ProductionWork>  $works  itens do mesmo pedido e do mesmo setor */
    public static function kitchen(string $sector, string $origin, Collection $works, int $columns): string
    {
        $paper = (new EscPos($columns))
            ->center()->bold()->line(mb_strtoupper($sector))
            ->large()->line($origin)->large(false)
            ->bold(false)->line(now()->format('d/m/Y H:i'))
            ->left()->rule();

        foreach ($works as $work) {
            $paper->bold()->large()->line(self::quantity($work->quantity, $work->unit).' '.$work->product_name)->large(false)->bold(false);

            if ($work->options_label) {
                $paper->line($work->options_label);
            }

            if ($work->notes) {
                $paper->bold()->line('>> '.$work->notes)->bold(false);
            }
        }

        return $paper->rule()->cut()->bytes();
    }

    public static function order(DeliveryOrder $order, string $storeName, int $columns): string
    {
        $order->loadMissing('items', 'onlineOrder:id,number');

        $paper = (new EscPos($columns))
            ->center()->bold()->line($storeName)
            ->large()->line('PEDIDO #'.$order->number)->large(false);

        if ($order->onlineOrder) {
            $paper->line('ONLINE #'.$order->onlineOrder->number);
        }

        $paper->bold(false)
            ->line(__("deliveries.types.{$order->type}").' - '.$order->created_at->format('d/m/Y H:i'))
            ->left()->rule()
            ->bold()->line($order->customer_name)->bold(false)
            ->line($order->customer_phone ?? '');

        if ($order->isDelivery()) {
            $paper->line($order->address() ?? '');

            if ($order->reference) {
                $paper->line('Ref.: '.$order->reference);
            }
        }

        $paper->rule();

        foreach ($order->items as $item) {
            $paper->bold()->pair(self::quantity($item->quantity, $item->unit).' '.$item->product_name, self::money($item->total))->bold(false);

            if ($item->options_label) {
                $paper->line('  '.$item->options_label);
            }

            if ($item->notes) {
                $paper->line('  >> '.$item->notes);
            }
        }

        $paper->rule()->pair('Subtotal', self::money($order->subtotal));

        if ((float) $order->delivery_fee > 0) {
            $paper->pair('Taxa de entrega', self::money($order->delivery_fee));
        }

        $paper->bold()->pair('TOTAL', self::money($order->total()))->bold(false)
            ->line('Pagamento: '.(config("sales.payment_methods.{$order->payment_method}") ?? $order->payment_method));

        if ($order->change_for) {
            $paper->line('Troco para '.self::money($order->change_for));
        }

        if ($order->notes) {
            $paper->rule()->line('Obs.: '.$order->notes);
        }

        return $paper->cut()->bytes();
    }

    /**
     * Pré-conta da mesa: o que o cliente confere antes de pagar. Não fecha a mesa e não é cupom fiscal.
     * O serviço segue a mesma conta do fechamento (percentual sobre o subtotal).
     */
    public static function preBill(TableOrder $order, string $storeName, ?string $servicePercent, int $columns): string
    {
        $order->loadMissing('diningTable', 'activeItems');

        $paper = (new EscPos($columns))
            ->center()->bold()->line($storeName)
            ->large()->line('CONTA - Mesa '.$order->diningTable->number)->large(false)
            ->bold(false)->line('Comanda #'.$order->number)
            ->line(now()->format('d/m/Y H:i'))
            ->left()->rule();

        foreach ($order->activeItems as $item) {
            $paper->pair(self::quantity($item->quantity, $item->unit).' '.$item->product_name, self::money($item->total));

            if ($item->options_label) {
                $paper->line('  '.$item->options_label);
            }
        }

        $subtotal = $order->subtotal();
        $paper->rule()->pair('Subtotal', self::money($subtotal));
        $total = $subtotal;

        if ($servicePercent !== null && bccomp($servicePercent, '0', 2) > 0) {
            $service = Decimal::money(bcdiv(bcmul($subtotal, $servicePercent, 6), '100', 6));
            $paper->pair('Serviço ('.self::percent($servicePercent).'%)', self::money($service));
            $total = bcadd($subtotal, $service, 2);
        }

        return $paper->bold()->pair('TOTAL', self::money($total))->bold(false)
            ->rule()->center()->line('Não é documento fiscal')
            ->cut()->bytes();
    }

    public static function test(string $printerName, int $columns): string
    {
        return (new EscPos($columns))
            ->center()->bold()->large()->line('TESTE')->large(false)->bold(false)
            ->line($printerName)
            ->line(now()->format('d/m/Y H:i'))
            ->left()->rule()
            ->line('Se esta folha saiu inteira e cortada, a impressora esta pronta.')
            ->rule()->cut()->bytes();
    }

    private static function quantity(string $quantity, ?string $unit): string
    {
        $number = rtrim(rtrim($quantity, '0'), '.');

        return str_replace('.', ',', $number).($unit && strtoupper($unit) !== 'UN' ? ' '.strtolower($unit) : 'x');
    }

    /** "10.00" vira "10"; "12.50" vira "12,5". */
    private static function percent(string $percent): string
    {
        return str_replace('.', ',', rtrim(rtrim($percent, '0'), '.'));
    }

    private static function money(string $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}
