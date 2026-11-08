<?php

namespace App\Actions;

use App\Http\Requests\StoreMenuOrderRequest;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\MenuOrderReceiptItem;
use App\Models\Product;
use App\Models\TableOrder;
use App\Support\ItemComposer;
use App\Support\RestaurantOperationPolicy;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReceiveMenuOrder
{
    public function __construct(private OpenTable $openTable, private RestaurantOperationPolicy $policy, private ItemComposer $composer) {}

    public function handle(DiningTable $table, array $data): MenuOrderReceipt
    {
        $data = Validator::make($data, StoreMenuOrderRequest::payloadRules())->validate();
        $lines = array_map(fn ($line) => [
            'product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'],
            'notes' => trim($line['notes'] ?? '') ?: null, ...$this->normalizedSelection($line),
        ], array_values($data['items']));
        $hash = hash('sha256', json_encode([$table->id, $data['service_context'], $lines], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($table, $data, $lines, $hash) {
            $locked = TableOperationLock::table($table);
            $company = Company::whereKey($locked->company_id)->firstOrFail();
            abort_unless($company->active && $company->qr_menu_enabled && $locked->active, 404);
            $existing = MenuOrderReceipt::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->tracking_key_hash, hash('sha256', $data['tracking_key'])), 404);
                abort_unless(hash_equals($existing->content_hash, $hash), 409, 'Identificador já utilizado com outro conteúdo.');

                return $existing;
            }
            if (! hash_equals($locked->service_context ?? '', $data['service_context'])) {
                throw ValidationException::withMessages(['service_context' => 'Este atendimento mudou. Recarregue o cardápio antes de enviar um novo pedido.']);
            }
            $policy = $this->policy->resolve($company);
            $units = array_sum(array_column($lines, 'quantity'));
            if (count($lines) > $policy['max_lines'] || $units > $policy['max_order_units'] || max(array_column($lines, 'quantity')) > $policy['max_line_units']) {
                throw ValidationException::withMessages(['items' => 'O pedido ultrapassa o limite de quantidades da mesa.']);
            }
            $products = Product::withoutGlobalScopes()->where('company_id', $company->id)->where('active', true)->where('sellable', true)->where('sold_out', false)
                ->whereIn('id', array_column($lines, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $index => $line) {
                if (! $products->has($line['product_id'])) {
                    throw ValidationException::withMessages(["items.$index.product_id" => 'Produto indisponível neste cardápio.']);
                }
            }
            $composed = $this->composer->composeMany($company->id, $lines);
            $snapshots = [];
            $total = '0.00';
            foreach ($lines as $index => $line) {
                $product = $products->get($line['product_id']);
                $unitPrice = $composed[$index]['unit_price'];
                $lineTotal = bcmul((string) $line['quantity'], $unitPrice, 2);
                $total = bcadd($total, $lineTotal, 2);
                $snapshots[] = ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'notes' => $line['notes'],
                    'product_name' => $product->name, 'unit' => $product->unit, 'unit_price' => $unitPrice,
                    'options' => $composed[$index]['options'], 'options_label' => $composed[$index]['options_label'], 'total' => $lineTotal];
            }
            $order = TableOrder::withoutGlobalScopes()->where('company_id', $company->id)->where('open_table_id', $locked->id)->lockForUpdate()->first();
            $pending = $order ? MenuOrderReceipt::withoutGlobalScopes()->where('company_id', $company->id)->where('table_order_id', $order->id)->where('status', 'pending')->get(['units', 'total']) : collect();
            $pendingValue = $pending->reduce(fn ($sum, $receipt) => bcadd($sum, $receipt->total, 2), '0.00');
            if (bccomp($total, (string) $policy['max_order_value'], 2) > 0 || $pending->count() + 1 > $policy['max_pending_orders']
                || $pending->sum('units') + $units > $policy['max_pending_units']
                || bccomp(bcadd($pendingValue, $total, 2), (string) $policy['max_pending_value'], 2) > 0) {
                throw ValidationException::withMessages(['items' => 'Limite de pedidos pendentes atingido. Aguarde a confirmação da equipe.']);
            }
            $order ??= $this->openTable->handle($locked, 1, null, PlaceMenuOrder::GUEST);
            $receipt = new MenuOrderReceipt;
            $receipt->forceFill(['company_id' => $company->id, 'dining_table_id' => $locked->id, 'table_order_id' => $order->id,
                'service_context' => $data['service_context'], 'client_uuid' => $data['client_uuid'], 'content_hash' => $hash,
                'tracking_key_hash' => hash('sha256', $data['tracking_key']), 'status' => 'pending', 'units' => $units, 'total' => $total, 'policy_snapshot' => $policy])->save();
            foreach ($snapshots as $snapshot) {
                $item = new MenuOrderReceiptItem;
                $item->forceFill(['company_id' => $company->id, 'menu_order_receipt_id' => $receipt->id, ...$snapshot])->save();
            }

            if ($policy['effective_mode'] === 'automatic') {
                $confirmed = (new ConfirmMenuOrder)->handleAutomatic($receipt);
                $confirmed->wasRecentlyCreated = true;

                return $confirmed;
            }

            return $receipt;
        });
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
            $options = array_map(fn ($option) => ['option_id' => (int) $option['option_id'], 'quantity' => (int) ($option['quantity'] ?? 1)], $line['options']);
            usort($options, fn ($a, $b) => [$a['option_id'], $a['quantity']] <=> [$b['option_id'], $b['quantity']]);
            $selection['options'] = $options;
        }

        return $selection;
    }
}
