import assert from 'node:assert/strict';
import test from 'node:test';
import { label, missing, priceCents, selectionKey } from '../../resources/js/lib/composition.js';

const BROTO = 1;
const GRANDE = 2;

function composition(pricing = 'highest', flavorPrices = {}) {
    const calabresa = { id: 10, name: 'Calabresa', price: '40.00', paused: false, prices: { [BROTO]: '39.90', [GRANDE]: flavorPrices.calabresa ?? '49.90' } };
    const quatro = { id: 11, name: 'Quatro queijos', price: '40.00', paused: false, prices: { [BROTO]: '44.90', [GRANDE]: flavorPrices.quatro ?? '59.90' } };
    const product = {
        pizza: {
            pricing,
            sizes: [
                { id: BROTO, name: 'Broto', slices: 4, max_flavors: 1 },
                { id: GRANDE, name: 'Grande', slices: 8, max_flavors: 2 },
            ],
            flavors: [calabresa, quatro],
        },
        groups: [
            { id: 1, name: 'Borda', min: 0, max: 1, allows_quantity: false, options: [{ id: 20, name: 'Catupiry', price: '8.00', paused: false, prices: { [GRANDE]: '10.00' } }] },
            { id: 2, name: 'Adicionais', min: 0, max: 3, allows_quantity: true, options: [{ id: 21, name: 'Bacon', price: '5.00', paused: false, prices: {} }] },
        ],
    };

    return product;
}

const half = { size_id: GRANDE, flavor_ids: [10, 11], options: [{ option_id: 20, quantity: 1 }, { option_id: 21, quantity: 2 }] };

test('highest rule prices half and half by the most expensive flavor, same label as the server', () => {
    assert.equal(priceCents({ sale_price: '40.00' }, composition(), half), 7990);
    assert.equal(label(composition(), half), 'Grande · ½ Calabresa ½ Quatro queijos · Borda: Catupiry · +2 Bacon');
});

test('average rule rounds half up only at the end', () => {
    const selection = { size_id: GRANDE, flavor_ids: [10, 11], options: [] };

    assert.equal(priceCents({ sale_price: '40.00' }, composition('average', { calabresa: '49.90', quatro: '59.95' }), selection), 5493);
});

test('missing points at the absent size and at too many flavors for Broto', () => {
    assert.ok(missing(composition(), { size_id: null, flavor_ids: [], options: [] }).includes('Escolha o tamanho.'));
    assert.deepEqual(missing(composition(), { size_id: BROTO, flavor_ids: [10, 11], options: [] }), ['O tamanho Broto aceita no máximo 1 sabor(es).']);
    assert.deepEqual(missing(composition(), half), []);
});

test('missing counts the sum of quantities against group limits', () => {
    const selection = { size_id: GRANDE, flavor_ids: [10], options: [{ option_id: 21, quantity: 4 }] };

    assert.deepEqual(missing(composition(), selection), ['Escolha no máximo 3 em Adicionais.']);
});

test('product without pizza keeps its own price and has no label without extras', () => {
    const plain = { pizza: null, groups: composition().groups };

    assert.equal(priceCents({ sale_price: '12.50' }, plain, { options: [] }), 1250);
    assert.equal(label(plain, { options: [] }), null);
    assert.equal(priceCents({ sale_price: '12.50' }, plain, { options: [{ option_id: 21, quantity: 1 }] }), 1750);
    assert.equal(label(plain, { options: [{ option_id: 21, quantity: 1 }] }), '+1 Bacon');
});

test('line key keeps flavor order and ignores option order', () => {
    const a = selectionKey(5, { size_id: 2, flavor_ids: [10, 11], options: [{ option_id: 21, quantity: 1 }, { option_id: 20, quantity: 1 }] });
    const b = selectionKey(5, { size_id: 2, flavor_ids: [10, 11], options: [{ option_id: 20, quantity: 1 }, { option_id: 21, quantity: 1 }] });
    const c = selectionKey(5, { size_id: 2, flavor_ids: [11, 10], options: [] });

    assert.equal(a, b);
    assert.notEqual(a, c);
    assert.equal(selectionKey(5, null), '5');
});
