/**
 * Espelho de app/Support/ItemComposer.php para a tela: mostra preço ao vivo, rótulo e pendências.
 * O servidor recalcula tudo só com ids e quantidades; aqui é apenas conferência visual.
 *
 * Seleção: { size_id: number|null, flavor_ids: number[], options: [{ option_id, quantity }] }
 */

const FRACTIONS = { 2: '½', 3: '⅓', 4: '¼' };
const LABEL_LIMIT = 255;
const SCALE = 1_000_000n; // centavos × 10⁶

/** "49.90" vira centavos × 10⁶ como BigInt, sem passar por float. */
function toScaled(value) {
    const [whole, decimals = ''] = String(value ?? '0').trim().split('.');
    const cents = BigInt(`${whole || '0'}${decimals.padEnd(2, '0').slice(0, 2)}`);
    const rest = BigInt(decimals.slice(2).padEnd(6, '0').slice(0, 6));

    return cents * SCALE + (whole.startsWith('-') ? -rest : rest);
}

/** Arredonda half-up para centavos inteiros, como Decimal::money. */
function roundCents(scaled) {
    return Number((scaled + SCALE / 2n) / SCALE);
}

const sizeOf = (composition, selection) => composition.pizza?.sizes.find((size) => size.id === selection.size_id) ?? null;

const flavorsOf = (composition, selection) => (selection.flavor_ids ?? [])
    .map((id) => composition.pizza?.flavors.find((flavor) => flavor.id === id))
    .filter(Boolean);

/** Extras na ordem dos grupos e, dentro do grupo, das opções. */
function extrasOf(composition, selection) {
    const extras = [];

    composition.groups.forEach((group, groupIndex) => {
        group.options.forEach((option, optionIndex) => {
            const chosen = (selection.options ?? []).find((row) => row.option_id === option.id);

            if (chosen) {
                extras.push({ group, option, quantity: Number(chosen.quantity ?? 1), order: [groupIndex, optionIndex] });
            }
        });
    });

    return extras;
}

/** Preço unitário em centavos inteiros. */
export function priceCents(product, composition, selection) {
    const size = sizeOf(composition, selection);
    const flavors = flavorsOf(composition, selection);
    let value;

    if (composition.pizza) {
        const prices = flavors.map((flavor) => toScaled(flavor.prices?.[size?.id] ?? '0'));

        if (prices.length === 0) {
            value = 0n;
        } else if (composition.pizza.pricing === 'average') {
            value = prices.reduce((sum, price) => sum + price, 0n) / BigInt(prices.length);
        } else {
            value = prices.reduce((max, price) => (price > max ? price : max), 0n);
        }
    } else {
        value = toScaled(product.sale_price);
    }

    for (const { option, quantity } of extrasOf(composition, selection)) {
        value += toScaled((size ? option.prices?.[size.id] : null) ?? option.price) * BigInt(quantity);
    }

    return roundCents(value);
}

/** Texto igual ao do servidor, ou null quando não há tamanho nem extras. */
export function label(composition, selection) {
    const size = sizeOf(composition, selection);
    const flavors = flavorsOf(composition, selection);
    const extras = extrasOf(composition, selection);

    if (!size && extras.length === 0) {
        return null;
    }

    const parts = [];

    if (size) {
        parts.push(size.name);
    }

    if (flavors.length === 1) {
        parts.push(flavors[0].name);
    } else if (flavors.length > 1) {
        const mark = FRACTIONS[flavors.length] ?? `1/${flavors.length}`;
        parts.push(flavors.map((flavor) => `${mark} ${flavor.name}`).join(' '));
    }

    for (const { group, option, quantity } of extras) {
        parts.push(group.max === 1 ? `${group.name}: ${option.name}` : `+${quantity} ${option.name}`);
    }

    const text = parts.join(' · ');
    const letters = [...text];

    return letters.length > LABEL_LIMIT ? `${letters.slice(0, LABEL_LIMIT - 1).join('')}…` : text;
}

/** Mensagens de tamanho, sabores e limites dos grupos; vazio quando já dá para confirmar. */
export function missing(composition, selection) {
    const messages = [];

    if (composition.pizza) {
        const size = sizeOf(composition, selection);
        const count = (selection.flavor_ids ?? []).length;

        if (!size) {
            messages.push('Escolha o tamanho.');
        }

        if (count === 0) {
            messages.push('Escolha pelo menos um sabor.');
        } else if (size && count > size.max_flavors) {
            messages.push(`O tamanho ${size.name} aceita no máximo ${size.max_flavors} sabor(es).`);
        }
    }

    for (const group of composition.groups) {
        const total = (selection.options ?? [])
            .filter((row) => group.options.some((option) => option.id === row.option_id))
            .reduce((sum, row) => sum + Number(row.quantity ?? 1), 0);

        if (total > group.max) {
            messages.push(`Escolha no máximo ${group.max} em ${group.name}.`);
        } else if (total === 0 && group.min >= 1) {
            messages.push(`Escolha ${group.name}.`);
        } else if (total < group.min) {
            messages.push(`Escolha pelo menos ${group.min} em ${group.name}.`);
        }
    }

    return messages;
}

/** Chave da linha do carrinho: sabores na ordem escolhida, opções ordenadas por id. */
export function selectionKey(productId, selection) {
    if (!selection) {
        return String(productId);
    }

    const options = [...(selection.options ?? [])]
        .sort((a, b) => a.option_id - b.option_id)
        .map((row) => `${row.option_id}x${row.quantity ?? 1}`);

    return [productId, selection.size_id ?? '', (selection.flavor_ids ?? []).join(','), options.join(',')].join('|');
}

/** Campos de composição do payload de um item; vazio para produto simples. */
export function payloadFields(selection) {
    if (!selection) {
        return {};
    }

    return {
        ...(selection.size_id ? { size_id: selection.size_id } : {}),
        ...((selection.flavor_ids ?? []).length ? { flavor_ids: selection.flavor_ids } : {}),
        ...((selection.options ?? []).length ? { options: selection.options.map((row) => ({ option_id: row.option_id, quantity: row.quantity ?? 1 })) } : {}),
    };
}
