<?php

namespace App\Support\WhatsAppBot;

use App\Actions\PlaceOnlineOrder;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\Product;
use App\Models\WhatsAppConversation;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fechamento do pedido pela conversa: entrega ou retirada, endereço, nome, pagamento e resumo.
 * Confirmar cria um pedido online pelo mesmo PlaceOnlineOrder da loja; nada de preço ou regra própria.
 */
class CheckoutFlow
{
    public const TYPE = 'type';

    public const ADDRESS_REUSE = 'address_reuse';

    public const ZONE = 'zone';

    public const STREET = 'street';

    public const NUMBER = 'number';

    public const COMPLEMENT = 'complement';

    public const REFERENCE = 'reference';

    public const NAME = 'name';

    public const PAYMENT = 'payment';

    public const CHANGE = 'change';

    public const SUMMARY = 'summary';

    /** Pedido enviado (ou recusado pelo limite de pendentes): o bot fica quieto até a próxima conversa. */
    public const DONE = 'done';

    public const STATES = [self::TYPE, self::ADDRESS_REUSE, self::ZONE, self::STREET, self::NUMBER, self::COMPLEMENT, self::REFERENCE, self::NAME, self::PAYMENT, self::CHANGE, self::SUMMARY];

    private const NO_CHANGE = ['nao preciso', 'nao', 'sem troco', 'nao precisa'];

    private WhatsAppConversation $conversation;

    private OnlineStore $store;

    public function __construct(private PlaceOnlineOrder $placeOrder) {}

    public function bind(WhatsAppConversation $conversation): self
    {
        $this->conversation = $conversation;
        $this->store = OnlineStore::withoutGlobalScopes()->where('company_id', $conversation->company_id)->firstOrFail();

        return $this;
    }

    /**
     * Primeiro passo do fechamento; pula a pergunta de entrega/retirada quando só há uma opção.
     *
     * @param  array<string, mixed>  $context
     */
    public function start(array &$context): string
    {
        $context['checkout'] = [];
        $types = $this->types();

        if (count($types) === 1) {
            $context['checkout']['type'] = $types[0];

            return $this->afterType($context);
        }

        return self::TYPE;
    }

    /** @param  array<string, mixed>  $context */
    public function prompt(string $state, array $context): string
    {
        $checkout = $context['checkout'] ?? [];

        return match ($state) {
            self::TYPE => "Como prefere receber?\n1 – entrega\n2 – retirar na loja".OrderFlow::FOOTER,
            self::ADDRESS_REUSE => "Entregar no mesmo endereço do último pedido?\n".$this->addressLine($this->lastAddress() ?? [])."\n1 – sim\n2 – outro endereço".OrderFlow::FOOTER,
            self::ZONE => "Qual o seu bairro?\n".OrderFlow::numbered($this->zones()->map(
                fn (DeliveryZone $zone) => $zone->name.' – '.(bccomp((string) $zone->fee, '0', 2) > 0 ? 'taxa R$ '.Decimal::currency($zone->fee) : 'entrega grátis'),
            )->all()).OrderFlow::FOOTER,
            self::STREET => 'Qual a rua?',
            self::NUMBER => 'Qual o número?',
            self::COMPLEMENT => 'Tem complemento (apto, bloco)? Escreva aqui ou digite pular.',
            self::REFERENCE => 'Algum ponto de referência? Escreva aqui ou digite pular.',
            self::NAME => $this->contactName() === null
                ? 'Em nome de quem é o pedido? Escreva seu nome.'
                : "Em nome de quem é o pedido?\n1 – usar {$this->contactName()}\nOu escreva outro nome.",
            self::PAYMENT => "Como vai pagar?\n".OrderFlow::numbered(array_map(fn (string $method) => $this->paymentLabel($method), $this->paymentMethods())).OrderFlow::FOOTER,
            self::CHANGE => 'Precisa de troco? O total é R$ '.Decimal::currency($this->total($context)).'. Digite para quanto (ex.: 50) ou digite não preciso.',
            self::SUMMARY => $this->summary($context, $checkout),
        };
    }

    /**
     * Resposta do cliente num passo do fechamento. Null quando não foi entendida. `next` null mantém o passo
     * (só manda `texts`); `remember` guarda o passo atual para o "9 – voltar".
     *
     * @param  array<string, mixed>  $context
     * @return array{next: ?string, texts: list<string>, remember: bool}|null
     */
    public function answer(string $state, string $answer, string $raw, array &$context): ?array
    {
        $checkout = &$context['checkout'];

        switch ($state) {
            case self::TYPE:
                $type = ['1' => DeliveryOrder::DELIVERY, '2' => DeliveryOrder::PICKUP][$answer] ?? null;

                if ($type === null || ! in_array($type, $this->types(), true)) {
                    return null;
                }
                $checkout = ['type' => $type];

                return $this->go($this->afterType($context));

            case self::ADDRESS_REUSE:
                if ($answer === '1') {
                    $checkout['address'] = array_diff_key($this->lastAddress() ?? [], ['zone_name' => true]);

                    return $this->go(self::NAME);
                }

                return $answer === '2' ? $this->go(self::ZONE) : null;

            case self::ZONE:
                $zone = ctype_digit($answer) ? $this->zones()->values()->get((int) $answer - 1) : null;

                if ($zone === null) {
                    return null;
                }
                $checkout['address'] = ['delivery_zone_id' => $zone->id];

                return $this->go(self::STREET);

            case self::STREET:
            case self::NUMBER:
                $limit = $state === self::STREET ? 120 : 20;

                if ($raw === '' || mb_strlen($raw) > $limit) {
                    return $this->stay($state === self::STREET ? 'Escreva o nome da rua (até 120 caracteres).' : 'Escreva o número (até 20 caracteres).');
                }
                $checkout['address'][$state] = $raw;

                return $this->go($state === self::STREET ? self::NUMBER : self::COMPLEMENT);

            case self::COMPLEMENT:
            case self::REFERENCE:
                $limit = $state === self::COMPLEMENT ? 100 : 150;

                if ($answer !== 'pular' && mb_strlen($raw) > $limit) {
                    return $this->stay("Escreva até {$limit} caracteres ou digite pular.");
                }
                $checkout['address'][$state] = $answer === 'pular' || $raw === '' ? null : $raw;

                return $this->go($state === self::COMPLEMENT ? self::REFERENCE : self::NAME);

            case self::NAME:
                $name = $answer === '1' && $this->contactName() !== null ? $this->contactName() : $raw;

                if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                    return $this->stay('O nome precisa ter de 2 a 80 letras.');
                }
                $checkout['name'] = $name;

                return $this->go(self::PAYMENT);

            case self::PAYMENT:
                $method = ctype_digit($answer) ? ($this->paymentMethods()[(int) $answer - 1] ?? null) : null;

                if ($method === null) {
                    return null;
                }
                $checkout['payment_method'] = $method;
                unset($checkout['change_for']);

                return $this->go($method === 'cash' ? self::CHANGE : self::SUMMARY);

            case self::CHANGE:
                return $this->change($answer, $context);

            case self::SUMMARY:
                if ($answer === '1') {
                    return $this->place($context);
                }

                return $answer === '2' ? ['next' => OrderFlow::CART, 'texts' => [], 'remember' => false] : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{next: ?string, texts: list<string>, remember: bool}|null
     */
    private function change(string $answer, array &$context): ?array
    {
        if (in_array($answer, self::NO_CHANGE, true)) {
            $context['checkout']['change_for'] = null;

            return $this->go(self::SUMMARY);
        }

        $amount = preg_replace('/^r\$\s*/', '', $answer);

        if (! preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $amount)) {
            return null;
        }

        $amount = bcadd(str_replace(',', '.', $amount), '0', 2);

        if (bccomp($amount, $this->total($context), 2) <= 0) {
            return $this->stay('O troco precisa ser maior que o total.');
        }

        $context['checkout']['change_for'] = $amount;

        return $this->go(self::SUMMARY);
    }

    /**
     * Cria o pedido online. O identificador só muda quando o conteúdo muda: confirmar de novo o mesmo pedido
     * devolve o pedido já criado, e um carrinho alterado depois de uma recusa ganha identificador novo.
     *
     * @param  array<string, mixed>  $context
     * @return array{next: ?string, texts: list<string>, remember: bool}
     */
    private function place(array &$context): array
    {
        $payload = $this->payload($context);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        if ($this->conversation->cart_uuid === null || $this->conversation->cart_hash !== $hash) {
            $this->conversation->forceFill(['cart_uuid' => (string) Str::uuid(), 'cart_hash' => $hash])->save();
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $order = Context::scope(
                    fn () => $this->placeOrder->handle($this->store, ['client_uuid' => $this->conversation->cart_uuid, ...$payload]),
                    hidden: ['audit_actor' => PlaceOnlineOrder::GUEST],
                )['order'];

                break;
            } catch (ValidationException $exception) {
                return $this->refused($exception, $context);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() === 409 && $attempt === 1) {
                    $this->conversation->forceFill(['cart_uuid' => (string) Str::uuid()])->save();

                    continue;
                }

                if ($exception->getStatusCode() === 404) {
                    $context = ['cart' => [], 'history' => []];

                    return ['next' => WhatsAppBot::GREETED, 'texts' => ['A loja não está recebendo pedidos agora.'], 'remember' => false];
                }

                report($exception);

                return $this->stay('Não consegui enviar seu pedido. Digite 0 para falar com um atendente.');
            }
        }

        $context = ['cart' => [], 'history' => []];
        $this->conversation->forceFill(['last_online_order_id' => $order->id, 'cart_uuid' => null, 'cart_hash' => null]);

        return ['next' => self::DONE, 'texts' => ["Pedido #{$order->number} enviado! Você vai receber as atualizações por aqui."], 'remember' => false];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{next: ?string, texts: list<string>, remember: bool}
     */
    private function refused(ValidationException $exception, array &$context): array
    {
        $errors = $exception->errors();
        $key = (string) array_key_first($errors);
        $back = fn (string $state, string $text) => ['next' => $state, 'texts' => [$text], 'remember' => false];

        if (preg_match('/^items\.(\d+)/', $key, $match) && isset($context['cart'][(int) $match[1]])) {
            [$line] = array_splice($context['cart'], (int) $match[1], 1);
            $name = Product::withoutGlobalScopes()->where('company_id', $this->conversation->company_id)->whereKey($line['product_id'])->value('name');

            return $back(OrderFlow::CART, "O item {$name} ficou indisponível. Tirei do carrinho.");
        }

        if ($key === 'store' || $key === 'customer.phone') {
            $context = ['cart' => [], 'history' => []];

            return $key === 'store'
                ? $back(WhatsAppBot::GREETED, 'A loja acabou de fechar.')
                : $back(self::DONE, 'Você já tem pedidos aguardando a loja. Assim que ela responder, você pode pedir de novo.');
        }

        return match ($key) {
            'items' => $back(OrderFlow::CART, $errors['items'][0]),
            'address.delivery_zone_id' => $back(self::ZONE, 'Não entregamos mais nesse bairro. Escolha outro.'),
            'change_for' => $back(self::CHANGE, 'O troco precisa ser maior que o total.'),
            'type' => $back(self::TYPE, 'A loja não aceita mais esse tipo de pedido agora.'),
            default => $this->stay('Não consegui enviar seu pedido. Digite 0 para falar com um atendente.'),
        };
    }

    /**
     * Payload nas mesmas chaves de StoreOnlineOrderRequest::payloadRules(), sem client_uuid.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function payload(array $context): array
    {
        $checkout = $context['checkout'];
        $payload = [
            'type' => $checkout['type'],
            'customer' => ['name' => $checkout['name'], 'phone' => (string) $this->conversation->phone],
        ];

        if ($checkout['type'] === DeliveryOrder::DELIVERY) {
            $address = $checkout['address'];
            $payload['address'] = [
                'street' => $address['street'], 'number' => $address['number'], 'complement' => $address['complement'] ?? null,
                'reference' => $address['reference'] ?? null, 'delivery_zone_id' => $address['delivery_zone_id'],
            ];
        }

        return $payload + [
            'items' => array_map(fn (array $line) => array_diff_key($line, ['label' => true, 'unit_price' => true]), $context['cart']),
            'payment_method' => $checkout['payment_method'],
            'change_for' => $checkout['change_for'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $checkout
     */
    private function summary(array $context, array $checkout): string
    {
        [$lines, $subtotal] = OrderFlow::cartLines($context['cart']);
        $text = "Confira seu pedido:\n{$lines}\nSubtotal: R$ ".Decimal::currency($subtotal);
        $zone = $this->zone($context);

        if ($zone !== null) {
            $text .= "\nEntrega ({$zone->name}): R$ ".Decimal::currency($zone->fee);
        }

        $text .= "\nTotal: R$ ".Decimal::currency($this->total($context))."\n\n";
        $text .= $zone !== null
            ? 'Entrega em: '.$this->addressLine([...$checkout['address'], 'zone_name' => $zone->name], withReference: true)
            : 'Retirada na loja';

        $payment = $this->paymentLabel($checkout['payment_method']);

        if ($checkout['payment_method'] === 'cash') {
            $payment .= isset($checkout['change_for']) ? ' (troco para R$ '.Decimal::currency($checkout['change_for']).')' : ' (sem troco)';
        }

        return $text."\nNome: {$checkout['name']}\nPagamento: {$payment}\n\n1 – confirmar\n2 – alterar\n0 – atendente";
    }

    /** @param  array<string, mixed>  $address */
    private function addressLine(array $address, bool $withReference = false): string
    {
        $line = ($address['street'] ?? '').', '.($address['number'] ?? '');

        if (! empty($address['complement'])) {
            $line .= " – {$address['complement']}";
        }

        $line .= ' ('.($address['zone_name'] ?? '').')';

        return $withReference && ! empty($address['reference']) ? $line." – {$address['reference']}" : $line;
    }

    /** @param  array<string, mixed>  $context */
    private function total(array $context): string
    {
        [, $subtotal] = OrderFlow::cartLines($context['cart']);

        return bcadd($subtotal, (string) ($this->zone($context)?->fee ?? '0'), 2);
    }

    /** @param  array<string, mixed>  $context */
    private function zone(array $context): ?DeliveryZone
    {
        if (($context['checkout']['type'] ?? null) !== DeliveryOrder::DELIVERY) {
            return null;
        }

        return $this->zones()->firstWhere('id', $context['checkout']['address']['delivery_zone_id'] ?? 0);
    }

    /** @param  array<string, mixed>  $context */
    private function afterType(array $context): string
    {
        if ($context['checkout']['type'] === DeliveryOrder::PICKUP) {
            return self::NAME;
        }

        return $this->lastAddress() === null ? self::ZONE : self::ADDRESS_REUSE;
    }

    /** @return list<string> */
    private function types(): array
    {
        $types = [];

        if ($this->store->accepts_delivery && $this->zones()->isNotEmpty()) {
            $types[] = DeliveryOrder::DELIVERY;
        }

        if ($this->store->accepts_pickup) {
            $types[] = DeliveryOrder::PICKUP;
        }

        return $types === [] ? [DeliveryOrder::PICKUP] : $types;
    }

    /** @return Collection<int, DeliveryZone> */
    private function zones(): Collection
    {
        return DeliveryZone::withoutGlobalScopes()->where('company_id', $this->conversation->company_id)->orderBy('name')->get();
    }

    /**
     * Endereço da última entrega deste telefone na empresa, se o bairro ainda existe.
     *
     * @return array{street: string, number: string, complement: ?string, reference: ?string, delivery_zone_id: int, zone_name: string}|null
     */
    private function lastAddress(): ?array
    {
        if ($this->conversation->phone === null) {
            return null;
        }

        $order = OnlineOrder::withoutGlobalScopes()
            ->where('company_id', $this->conversation->company_id)
            ->where('customer_phone', $this->conversation->phone)
            ->where('type', DeliveryOrder::DELIVERY)
            ->whereNotNull('street')
            ->latest('id')
            ->first();
        $zone = $order ? $this->zones()->firstWhere('id', $order->delivery_zone_id) : null;

        return $zone === null ? null : [
            'street' => $order->street, 'number' => $order->number_address, 'complement' => $order->complement,
            'reference' => $order->reference, 'delivery_zone_id' => $zone->id, 'zone_name' => $zone->name,
        ];
    }

    private function contactName(): ?string
    {
        $name = trim((string) $this->conversation->contact_name);

        return mb_strlen($name) >= 2 && mb_strlen($name) <= 80 ? $name : null;
    }

    /** @return list<string> */
    private function paymentMethods(): array
    {
        return array_values(config('online.payment_methods'));
    }

    private function paymentLabel(string $method): string
    {
        return config('sales.payment_methods')[$method] ?? $method;
    }

    /** @return array{next: string, texts: list<string>, remember: bool} */
    private function go(string $state): array
    {
        return ['next' => $state, 'texts' => [], 'remember' => true];
    }

    /** @return array{next: null, texts: list<string>, remember: bool} */
    private function stay(string $text): array
    {
        return ['next' => null, 'texts' => [$text], 'remember' => false];
    }
}
