/**
 * Sacola da loja online: funções puras, sem Vue, testadas em tests/Frontend/ShopCart.test.mjs.
 *
 * Linha: { uid, product_id, name, unit_cents, quantity, notes, key, label, fields }
 * - `key` é a selectionKey da composição (ou o id do produto simples);
 * - `fields` são os campos de composição do payload (payloadFields);
 * - preço e nome só servem para a tela: o servidor recalcula tudo a partir de ids e quantidades.
 */

const MAX_ORDERS = 20;

const customerKey = (slug) => `shop:${slug}:customer`;
const ordersKey = (slug) => `shop:${slug}:orders`;

/** Soma a linha a uma igual (mesma composição e mesma observação) ou acrescenta uma nova, com uid próprio. */
export function addLine(cart, line) {
    const notes = line.notes ?? '';
    const quantity = line.quantity ?? 1;
    const index = cart.findIndex((item) => item.key === line.key && (item.notes ?? '') === notes);

    if (index !== -1) {
        return cart.map((item, position) => (position === index ? { ...item, quantity: item.quantity + quantity } : item));
    }

    const uid = cart.reduce((max, item) => Math.max(max, item.uid ?? 0), 0) + 1;

    return [...cart, { ...line, notes, quantity, uid }];
}

export function subtotalCents(cart) {
    return cart.reduce((sum, item) => sum + item.unit_cents * item.quantity, 0);
}

export function totalCents(cart, zoneFeeCents) {
    return subtotalCents(cart) + (zoneFeeCents ?? 0);
}

/** Itens do envio: só ids, quantidades, observação e campos de composição. */
export function toPayload(cart) {
    return cart.map((item) => ({
        product_id: item.product_id,
        quantity: item.quantity,
        notes: item.notes ? item.notes : null,
        ...(item.fields ?? {}),
    }));
}

function read(storage, key) {
    try {
        const raw = storage?.getItem(key);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function write(storage, key, value) {
    try {
        storage.setItem(key, JSON.stringify(value));

        return true;
    } catch {
        return false;
    }
}

const text = (value) => (typeof value === 'string' ? value : '');

/** Guarda neste aparelho só nome, telefone e último endereço; falha em silêncio (modo privado). */
export function rememberCustomer(storage, slug, data) {
    const address = data.address
        ? {
            street: text(data.address.street),
            number: text(data.address.number),
            complement: text(data.address.complement),
            reference: text(data.address.reference),
            delivery_zone_id: data.address.delivery_zone_id ?? null,
        }
        : null;

    return write(storage, customerKey(slug), { name: text(data.name), phone: text(data.phone), address });
}

export function recallCustomer(storage, slug) {
    const saved = read(storage, customerKey(slug));

    if (!saved || typeof saved !== 'object') {
        return null;
    }

    return { name: text(saved.name), phone: text(saved.phone), address: saved.address && typeof saved.address === 'object' ? saved.address : null };
}

/** Lista "meus pedidos" deste aparelho: número e link de acompanhamento, o mais novo primeiro. */
export function rememberOrder(storage, slug, order) {
    const entry = { number: order.number, tracking_url: order.tracking_url };
    const others = recallOrders(storage, slug).filter((item) => item.tracking_url !== entry.tracking_url);

    return write(storage, ordersKey(slug), [entry, ...others].slice(0, MAX_ORDERS));
}

export function recallOrders(storage, slug) {
    const saved = read(storage, ordersKey(slug));

    return Array.isArray(saved)
        ? saved.filter((item) => item && typeof item.tracking_url === 'string').map((item) => ({ number: item.number, tracking_url: item.tracking_url }))
        : [];
}
