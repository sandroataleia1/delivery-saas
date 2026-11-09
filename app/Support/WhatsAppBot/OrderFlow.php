<?php

namespace App\Support\WhatsAppBot;

use App\Models\WhatsAppConversation;
use App\Support\Decimal;
use App\Support\ItemComposer;
use Illuminate\Validation\ValidationException;

/**
 * Roteiro do pedido pela conversa: categoria → produto → tamanho → sabores → adicionais → quantidade →
 * observação → carrinho. Grava estado e contexto na conversa; o preço do item fechado vem sempre do ItemComposer.
 */
class OrderFlow
{
    public const CATEGORY = 'category';

    public const PRODUCT = 'product';

    public const SIZE = 'size';

    public const FLAVORS = 'flavors';

    public const GROUP = 'group';

    public const QUANTITY = 'quantity';

    public const ITEM_NOTES = 'item_notes';

    public const CART = 'cart';

    public const REMOVE = 'remove';

    public const RESUME = 'resume';

    public const STATES = [self::CATEGORY, self::PRODUCT, self::SIZE, self::FLAVORS, self::GROUP, self::QUANTITY, self::ITEM_NOTES, self::CART, self::REMOVE, self::RESUME];

    public const NOTES_LIMIT = 120;

    public const FOOTER = "\n\n9 – voltar · 0 – atendente";

    private WhatsAppConversation $conversation;

    private BotCatalog $catalog;

    /** @var array<string, mixed> */
    private array $context;

    private string $state;

    public function __construct(private ItemComposer $composer, private CheckoutFlow $checkout) {}

    public static function isOrderState(?string $state): bool
    {
        return in_array($state, self::STATES, true) || in_array($state, CheckoutFlow::STATES, true);
    }

    /**
     * Começa o pedido pela lista de categorias.
     *
     * @return list<string>
     */
    public function start(WhatsAppConversation $conversation, BotCatalog $catalog): array
    {
        $this->load($conversation, $catalog);
        $this->context = ['cart' => [], 'history' => []];

        if ($catalog->categories() === []) {
            return ['No momento não há itens disponíveis por aqui. Peça pelo link do cardápio ou digite 0 para falar com um atendente.'];
        }

        return $this->save([$this->moveTo(self::CATEGORY)]);
    }

    /**
     * Cliente voltou depois da pausa do atendente com o pedido pela metade.
     *
     * @return list<string>
     */
    public function offerResume(WhatsAppConversation $conversation, BotCatalog $catalog): array
    {
        $this->load($conversation, $catalog);
        $this->context['resume_state'] = $this->state;
        $this->state = self::RESUME;

        return $this->save([$this->prompt()]);
    }

    /**
     * Responde ao cliente no passo atual. Null quando a resposta não foi entendida; lista vazia quando
     * o cliente voltou da primeira lista (quem chama manda a saudação de novo).
     *
     * @return list<string>|null
     */
    public function handle(WhatsAppConversation $conversation, string $text, BotCatalog $catalog): ?array
    {
        $this->load($conversation, $catalog);
        $answer = WhatsAppBot::normalize($text);

        if ($answer === '9' && ! in_array($this->state, [self::CART, self::RESUME], true)) {
            return $this->save($this->back());
        }

        $texts = match ($this->state) {
            self::CATEGORY => $this->chooseCategory($answer),
            self::PRODUCT => $this->chooseProduct($answer),
            self::SIZE => $this->chooseSize($answer),
            self::FLAVORS => $this->chooseFlavors($answer),
            self::GROUP => $this->chooseOptions($answer),
            self::QUANTITY => $this->chooseQuantity($answer),
            self::ITEM_NOTES => $this->writeNotes($answer, trim($text)),
            self::CART => $this->cartAction($answer),
            self::REMOVE => $this->removeItem($answer),
            self::RESUME => $this->resume($answer),
            default => in_array($this->state, CheckoutFlow::STATES, true) ? $this->checkoutAnswer($answer, trim($text)) : null,
        };

        return $texts === null ? null : $this->save($texts);
    }

    private function load(WhatsAppConversation $conversation, BotCatalog $catalog): void
    {
        $this->conversation = $conversation;
        $this->catalog = $catalog;
        $this->context = $conversation->context ?? [];
        $this->context['cart'] ??= [];
        $this->context['history'] ??= [];
        $this->state = $conversation->state;
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function save(array $texts): array
    {
        $this->conversation->forceFill([
            'state' => $this->state,
            'context' => $this->context,
            'misunderstood' => 0,
            'cart_touched_at' => now(),
        ])->save();

        return $texts;
    }

    /** @return list<string>|null */
    private function chooseCategory(string $answer): ?array
    {
        $category = $this->pick($answer, $this->catalog->categories());

        if ($category === null) {
            return null;
        }

        $this->remember();
        $this->context['category_id'] = $category['id'];

        return [$this->moveTo(self::PRODUCT)];
    }

    /** @return list<string>|null */
    private function chooseProduct(string $answer): ?array
    {
        $product = $this->pick($answer, $this->catalog->products($this->context['category_id'] ?? -1));

        if ($product === null) {
            return null;
        }

        $this->remember();
        $this->context['draft'] = ['product_id' => $product['id'], 'options' => []];

        return [$this->firstProductStep()];
    }

    /** @return list<string>|null */
    private function chooseSize(string $answer): ?array
    {
        $size = $this->pick($answer, $this->composition()['pizza']['sizes'] ?? []);

        if ($size === null) {
            return null;
        }

        $this->remember();
        $this->context['draft']['size_id'] = $size['id'];
        $this->context['draft']['flavor_ids'] = [];

        return [$this->moveTo(self::FLAVORS)];
    }

    /** @return list<string>|null */
    private function chooseFlavors(string $answer): ?array
    {
        $flavors = $this->flavorsForSize();
        $max = $this->size()['max_flavors'] ?? 1;
        $numbers = $this->numbers($answer);

        if ($numbers === null || $numbers === []) {
            return null;
        }

        if (count($numbers) > $max) {
            return [$max === 1 ? 'Esse tamanho aceita só 1 sabor. Escolha de novo.' : "Esse tamanho aceita no máximo {$max} sabores. Escolha de novo."];
        }

        if (count(array_unique($numbers)) !== count($numbers) || max($numbers) > count($flavors)) {
            return null;
        }

        $this->remember();
        $this->context['draft']['flavor_ids'] = array_map(fn (int $number) => $flavors[$number - 1]['id'], $numbers);

        return [$this->groups() === [] ? $this->moveTo(self::QUANTITY) : $this->enterGroup(0)];
    }

    /** @return list<string>|null */
    private function chooseOptions(string $answer): ?array
    {
        $index = $this->context['draft']['group'] ?? 0;
        $group = $this->groups()[$index] ?? null;

        if ($group === null) {
            return null;
        }

        $chosen = $answer === 'pular' ? [] : $this->numbers($answer);

        if ($chosen === null || ($answer !== 'pular' && $chosen === []) || ($chosen !== [] && max($chosen) > count($group['options']))) {
            return null;
        }

        $counts = array_count_values($chosen);
        ksort($counts);

        if (! $group['allows_quantity'] && count($counts) !== count($chosen)) {
            return null;
        }

        if (count($chosen) > $group['max']) {
            return ["Escolha no máximo {$group['max']} em {$group['name']}."];
        }

        if (count($chosen) < $group['min']) {
            return ["Escolha pelo menos {$group['min']} em {$group['name']}."];
        }

        $groupOptionIds = array_column($group['options'], 'id');
        $kept = array_values(array_filter($this->context['draft']['options'], fn (array $row) => ! in_array($row['option_id'], $groupOptionIds, true)));

        foreach ($counts as $number => $quantity) {
            $kept[] = ['option_id' => $group['options'][$number - 1]['id'], 'quantity' => $quantity];
        }

        $this->remember();
        $this->context['draft']['options'] = $kept;

        return [isset($this->groups()[$index + 1]) ? $this->enterGroup($index + 1) : $this->moveTo(self::QUANTITY)];
    }

    /** @return list<string>|null */
    private function chooseQuantity(string $answer): ?array
    {
        $quantity = ctype_digit($answer) ? (int) $answer : 0;

        if ($quantity < 1 || $quantity > config('online.max_line_quantity')) {
            return null;
        }

        $this->remember();
        $this->context['draft']['quantity'] = $quantity;

        return [$this->moveTo(self::ITEM_NOTES)];
    }

    /** @return list<string> */
    private function writeNotes(string $answer, string $raw): array
    {
        if ($answer !== 'pular' && mb_strlen($raw) > self::NOTES_LIMIT) {
            return ['A observação pode ter até '.self::NOTES_LIMIT.' caracteres. Escreva de novo ou digite pular.'];
        }

        return $this->closeItem($answer === 'pular' || $raw === '' ? null : $raw);
    }

    /** @return list<string> */
    private function closeItem(?string $notes): array
    {
        $draft = $this->context['draft'];
        $line = ['product_id' => $draft['product_id'], 'quantity' => $draft['quantity']];

        if ($notes !== null) {
            $line['notes'] = $notes;
        }

        if (isset($draft['size_id'])) {
            $line['size_id'] = $draft['size_id'];
            $line['flavor_ids'] = $draft['flavor_ids'];
        }

        if ($draft['options'] !== []) {
            $line['options'] = $draft['options'];
        }

        try {
            $composed = $this->composer->composeMany($this->conversation->company_id, [$line])[0];
        } catch (ValidationException) {
            while ($this->context['history'] !== [] && end($this->context['history'])['state'] !== self::PRODUCT) {
                array_pop($this->context['history']);
            }
            $this->context['draft'] = ['product_id' => $draft['product_id'], 'options' => []];

            return ['Essa combinação não está disponível. Vamos escolher de novo.', $this->firstProductStep()];
        }

        $name = $composed['product']->name;
        $line['label'] = $composed['options_label'] ? "{$name} ({$composed['options_label']})" : $name;
        $line['unit_price'] = $composed['unit_price'];
        $this->context['cart'][] = $line;
        unset($this->context['draft']);
        $this->context['history'] = [];

        return [$this->moveTo(self::CART)];
    }

    /** @return list<string>|null */
    private function cartAction(string $answer): ?array
    {
        if ($answer === '1') {
            $max = config('online.max_lines');

            if (count($this->context['cart']) >= $max) {
                return ["Seu pedido já tem o máximo de {$max} itens. Digite 2 para remover um item ou 3 para finalizar."];
            }

            $this->remember();

            return [$this->moveTo(self::CATEGORY)];
        }

        if ($answer === '2') {
            $this->remember();

            return [$this->moveTo(self::REMOVE)];
        }

        if ($answer === '3' && $this->context['cart'] !== []) {
            $first = $this->checkout->bind($this->conversation)->start($this->context);
            $this->remember();

            return [$this->moveTo($first)];
        }

        return null;
    }

    /** @return list<string>|null */
    private function checkoutAnswer(string $answer, string $raw): ?array
    {
        $result = $this->checkout->bind($this->conversation)->answer($this->state, $answer, $raw, $this->context);

        if ($result === null || $result['next'] === null) {
            return $result['texts'] ?? null;
        }

        $next = $result['next'];

        if ($result['remember']) {
            $this->remember();
        } else {
            $this->context['history'] = in_array($next, [self::CART, WhatsAppBot::GREETED, CheckoutFlow::DONE], true) ? [] : [['state' => self::CART]];
        }

        if ($next === WhatsAppBot::GREETED || $next === CheckoutFlow::DONE) {
            $this->state = $next;

            return $result['texts'];
        }

        if ($next === self::CART && $this->context['cart'] === []) {
            $next = self::CATEGORY;
        }

        return [...$result['texts'], $this->moveTo($next)];
    }

    /** @return list<string>|null */
    private function removeItem(string $answer): ?array
    {
        $number = ctype_digit($answer) ? (int) $answer : 0;

        if ($number < 1 || $number > count($this->context['cart'])) {
            return null;
        }

        array_splice($this->context['cart'], $number - 1, 1);
        $this->context['history'] = [];

        return ["Item removido.\n\n".$this->moveTo($this->context['cart'] === [] ? self::CATEGORY : self::CART)];
    }

    /** @return list<string>|null */
    private function resume(string $answer): ?array
    {
        if ($answer === '1') {
            $state = $this->context['resume_state'] ?? self::CART;
            unset($this->context['resume_state']);

            return [$this->moveTo($state)];
        }

        if ($answer === '2') {
            $this->context = ['cart' => [], 'history' => []];

            return [$this->moveTo(self::CATEGORY)];
        }

        return null;
    }

    /** @return list<string> */
    private function back(): array
    {
        $previous = array_pop($this->context['history']);

        if ($previous === null) {
            $this->state = WhatsAppBot::GREETED;
            $this->context = ['cart' => [], 'history' => []];

            return [];
        }

        if ($previous['state'] === self::GROUP) {
            $this->context['draft']['group'] = $previous['group'];
        }

        return [$this->moveTo($previous['state'])];
    }

    /** Guarda o passo atual para o "9 – voltar". */
    private function remember(): void
    {
        $entry = ['state' => $this->state];

        if ($this->state === self::GROUP) {
            $entry['group'] = $this->context['draft']['group'] ?? 0;
        }

        $this->context['history'][] = $entry;
    }

    private function moveTo(string $state): string
    {
        $this->state = $state;

        return $this->prompt();
    }

    private function firstProductStep(): string
    {
        $composition = $this->composition();

        if ($composition !== null && $composition['pizza'] !== null) {
            return $this->moveTo(self::SIZE);
        }

        return $composition !== null && $composition['groups'] !== [] ? $this->enterGroup(0) : $this->moveTo(self::QUANTITY);
    }

    private function enterGroup(int $index): string
    {
        $this->context['draft']['group'] = $index;

        return $this->moveTo(self::GROUP);
    }

    private function prompt(): string
    {
        return match ($this->state) {
            self::CATEGORY => $this->categoryPrompt(),
            self::PRODUCT => $this->productPrompt(),
            self::SIZE => $this->sizePrompt(),
            self::FLAVORS => $this->flavorsPrompt(),
            self::GROUP => $this->groupPrompt(),
            self::QUANTITY => 'Quantas unidades de '.$this->catalog->productName($this->context['draft']['product_id'] ?? 0).'? Digite de 1 a '.config('online.max_line_quantity').'.',
            self::ITEM_NOTES => 'Alguma observação para este item? Escreva aqui ou digite pular.',
            self::CART => $this->cartSummary(),
            self::REMOVE => 'Qual item quer remover? Digite o número.',
            self::RESUME => "Você tinha um pedido em andamento.\n1 – continuar o pedido\n2 – começar de novo",
            default => $this->checkout->bind($this->conversation)->prompt($this->state, $this->context),
        };
    }

    private function categoryPrompt(): string
    {
        return "Escolha uma categoria:\n".self::numbered(array_column($this->catalog->categories(), 'name')).self::FOOTER;
    }

    private function productPrompt(): string
    {
        $categoryId = $this->context['category_id'] ?? -1;
        $lines = array_map(
            fn (array $product) => $product['name'].' – '.($product['from'] ? 'a partir de ' : '').'R$ '.Decimal::currency($product['price']),
            $this->catalog->products($categoryId),
        );

        return $this->catalog->categoryName($categoryId).":\n".self::numbered($lines).self::FOOTER;
    }

    private function sizePrompt(): string
    {
        $lines = array_map(
            fn (array $size) => $size['name'].' ('.($size['max_flavors'] === 1 ? '1 sabor' : "até {$size['max_flavors']} sabores").')',
            $this->composition()['pizza']['sizes'] ?? [],
        );

        return "Escolha o tamanho:\n".self::numbered($lines).self::FOOTER;
    }

    private function flavorsPrompt(): string
    {
        $max = $this->size()['max_flavors'] ?? 1;
        $sizeId = $this->context['draft']['size_id'] ?? 0;
        $lines = array_map(fn (array $flavor) => $flavor['name'].' – R$ '.Decimal::currency($flavor['prices'][$sizeId]), $this->flavorsForSize());
        $header = $max === 1 ? 'Escolha o sabor:' : "Escolha até {$max} sabores, separados por vírgula (ex.: 1,2):";

        return $header."\n".self::numbered($lines).self::FOOTER;
    }

    private function groupPrompt(): string
    {
        $group = $this->groups()[$this->context['draft']['group'] ?? 0];
        ['min' => $min, 'max' => $max] = $group;

        $rule = match (true) {
            $min === 0 && $max === 1 => 'escolha 1 ou digite pular',
            $min === 0 => "escolha até {$max}, separados por vírgula, ou digite pular",
            $min === $max && $max === 1 => 'escolha 1',
            $min === $max => "escolha {$max}, separados por vírgula",
            default => "escolha de {$min} a {$max}, separados por vírgula",
        };

        if ($group['allows_quantity'] && $max > 1) {
            $rule .= ' (repita o número para mais de uma unidade)';
        }

        $sizeId = $this->context['draft']['size_id'] ?? null;
        $lines = array_map(function (array $option) use ($sizeId) {
            $price = ($sizeId !== null ? ($option['prices'][$sizeId] ?? null) : null) ?? $option['price'];

            return bccomp((string) $price, '0', 2) > 0 ? $option['name'].' – + R$ '.Decimal::currency($price) : $option['name'];
        }, $group['options']);

        return "{$group['name']} – {$rule}:\n".self::numbered($lines).self::FOOTER;
    }

    private function cartSummary(): string
    {
        [$lines, $subtotal] = self::cartLines($this->context['cart']);

        return "Seu pedido:\n{$lines}\nSubtotal: R$ ".Decimal::currency($subtotal)
            ."\n\n1 – adicionar mais itens\n2 – remover um item\n3 – finalizar\n0 – atendente";
    }

    /**
     * Linhas numeradas do carrinho (com observação) e subtotal, a partir do preço que o ItemComposer deu ao item.
     *
     * @param  list<array<string, mixed>>  $cart
     * @return array{0: string, 1: string}
     */
    public static function cartLines(array $cart): array
    {
        $subtotal = '0';
        $lines = [];

        foreach ($cart as $index => $line) {
            $total = bcmul($line['unit_price'], (string) $line['quantity'], 2);
            $subtotal = bcadd($subtotal, $total, 2);
            $text = ($index + 1).". {$line['quantity']}x {$line['label']} – R$ ".Decimal::currency($total);
            $lines[] = isset($line['notes']) ? $text."\n   Obs.: {$line['notes']}" : $text;
        }

        return [implode("\n", $lines), $subtotal];
    }

    /** @return array<string, mixed>|null */
    private function composition(): ?array
    {
        return $this->catalog->composition($this->context['draft']['product_id'] ?? 0);
    }

    /** @return list<array<string, mixed>> */
    private function groups(): array
    {
        return $this->composition()['groups'] ?? [];
    }

    /** @return array<string, mixed>|null */
    private function size(): ?array
    {
        $sizeId = $this->context['draft']['size_id'] ?? null;

        foreach ($this->composition()['pizza']['sizes'] ?? [] as $size) {
            if ($size['id'] === $sizeId) {
                return $size;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> sabores com preço no tamanho escolhido */
    private function flavorsForSize(): array
    {
        $sizeId = $this->context['draft']['size_id'] ?? 0;

        return array_values(array_filter($this->composition()['pizza']['flavors'] ?? [], fn (array $flavor) => isset($flavor['prices'][$sizeId])));
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T|null
     */
    private function pick(string $answer, array $items): mixed
    {
        return ctype_digit($answer) ? ($items[(int) $answer - 1] ?? null) : null;
    }

    /** @return list<int>|null "1,3" ou "1, 3" ou "1 3"; null se houver algo que não é número */
    private function numbers(string $answer): ?array
    {
        $parts = preg_split('/[\s,]+/', $answer, flags: PREG_SPLIT_NO_EMPTY);
        $numbers = [];

        foreach ($parts as $part) {
            if (! ctype_digit($part) || (int) $part < 1) {
                return null;
            }
            $numbers[] = (int) $part;
        }

        return $numbers;
    }

    /** @param  list<string>  $lines */
    public static function numbered(array $lines): string
    {
        return implode("\n", array_map(fn (string $line, int $index) => ($index + 1).' – '.$line, $lines, array_keys($lines)));
    }
}
