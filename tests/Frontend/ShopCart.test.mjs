import assert from 'node:assert/strict';
import test from 'node:test';
import { addLine, recallCustomer, recallOrders, rememberCustomer, rememberOrder, subtotalCents, toPayload, totalCents } from '../../resources/js/lib/shopCart.js';

const burger = { product_id: 1, name: 'X-Bacon', unit_cents: 2800, quantity: 1, notes: '', key: '1', fields: {} };
const coke = { product_id: 2, name: 'Coca', unit_cents: 1000, quantity: 1, notes: '', key: '2', fields: {} };
const pizza = { product_id: 3, name: 'Pizza', unit_cents: 5990, quantity: 1, notes: '', key: '3|2|10,11|', label: 'Grande · ½ Calabresa ½ Quatro queijos', fields: { size_id: 2, flavor_ids: [10, 11] } };

function memoryStorage() {
    const data = new Map();

    return {
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
        data,
    };
}

test('addLine keeps separate lines for the same composition with different notes', () => {
    let cart = addLine([], pizza);
    cart = addLine(cart, { ...pizza, notes: 'Sem cebola' });
    cart = addLine(cart, pizza);

    assert.equal(cart.length, 2);
    assert.equal(cart[0].quantity, 2);
    assert.equal(cart[1].notes, 'Sem cebola');
    assert.notEqual(cart[0].uid, cart[1].uid);
});

test('addLine does not mutate the given cart', () => {
    const cart = addLine([], burger);
    const next = addLine(cart, burger);

    assert.equal(cart[0].quantity, 1);
    assert.equal(next[0].quantity, 2);
});

test('subtotalCents of 2 x 2800 + 1000 is 6600, total adds the zone fee', () => {
    const cart = addLine(addLine(addLine([], burger), burger), coke);

    assert.equal(subtotalCents(cart), 6600);
    assert.equal(totalCents(cart, 600), 7200);
    assert.equal(totalCents(cart, 0), 6600);
});

test('toPayload carries ids, quantities, notes and composition fields, never prices or names', () => {
    const cart = addLine(addLine([], burger), { ...pizza, notes: 'Bem assada' });
    const payload = toPayload(cart);

    assert.deepEqual(payload, [
        { product_id: 1, quantity: 1, notes: null },
        { product_id: 3, quantity: 1, notes: 'Bem assada', size_id: 2, flavor_ids: [10, 11] },
    ]);
    assert.doesNotMatch(JSON.stringify(payload), /price|cents|2800|5990|name|label/);
});

test('rememberCustomer and recallCustomer keep only name, phone and last address per slug', () => {
    const storage = memoryStorage();
    const address = { street: 'Rua A', number: '10', complement: '', reference: 'Portão azul', delivery_zone_id: 4 };

    rememberCustomer(storage, 'lanchonete', { name: 'Ana', phone: '11 99999-0000', address, secret: 'x' });

    assert.deepEqual(recallCustomer(storage, 'lanchonete'), { name: 'Ana', phone: '11 99999-0000', address });
    assert.equal(recallCustomer(storage, 'outra-loja'), null);
    assert.doesNotMatch([...storage.data.values()].join(), /secret/);
});

test('storage failures are swallowed (private mode)', () => {
    const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };

    assert.equal(rememberCustomer(broken, 'lanchonete', { name: 'Ana', phone: '1', address: null }), false);
    assert.equal(recallCustomer(broken, 'lanchonete'), null);
    assert.equal(recallCustomer(null, 'lanchonete'), null);
});

test('rememberOrder keeps only number and tracking_url, newest first, per slug', () => {
    const storage = memoryStorage();

    rememberOrder(storage, 'lanchonete', { number: 7, tracking_url: 'https://x/loja/lanchonete/pedido/abc', total: '50.00' });
    rememberOrder(storage, 'lanchonete', { number: 8, tracking_url: 'https://x/loja/lanchonete/pedido/def' });
    rememberOrder(storage, 'lanchonete', { number: 8, tracking_url: 'https://x/loja/lanchonete/pedido/def' });

    assert.deepEqual(recallOrders(storage, 'lanchonete'), [
        { number: 8, tracking_url: 'https://x/loja/lanchonete/pedido/def' },
        { number: 7, tracking_url: 'https://x/loja/lanchonete/pedido/abc' },
    ]);
    assert.deepEqual(recallOrders(storage, 'outra-loja'), []);
});
