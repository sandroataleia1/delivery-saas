import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { computed, ref } from 'vue';

const script = readFileSync('resources/js/pages/menu/Show.vue', 'utf8').split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\r?$/gm, '');
function environment() {
    const values = new Map();
    const storage = { setItem: (key, value) => values.set(key, value), getItem: key => values.get(key) ?? null, removeItem: key => values.delete(key), key: index => [...values.keys()][index] ?? null, get length() { return values.size; } };
    let uuid = 0;
    return { values, tab({ post, get = async () => null } = {}) {
        let calls = 0;
        let mounted;
        const form = { processing: false, errors: {}, payload: null, transform(callback) { this.payload = callback(); return this; }, post(...args) { return post(this.payload, ...args); }, cancel() {} };
        const tracking = { processing: false, get, cancel() {} };
        // Run the actual component script, replacing only transport, storage and lifecycle boundaries.
        const factory = new Function('computed', 'ref', 'defineProps', 'onMounted', 'onUnmounted', 'useHttp', 'route', 'localStorage', 'newUuid', 'crypto', 'window', 'setInterval', 'clearInterval', script + ';return {send,poll,journal,cart,change,error};');
        const page = factory(computed, ref, () => ({ token: 'table', serviceContext: 'context', categories: [], products: [], limits: { max_order_units: 50, max_line_units: 10, max_lines: 20 } }), callback => mounted = callback, () => {}, () => calls++ === 0 ? form : tracking, (name, ...args) => '/' + name + '/' + args.flat().join('/'), storage, () => `uuid-${++uuid}`, globalThis.crypto, { addEventListener() {}, removeEventListener() {} }, () => 0, () => {});
        return { ...page, start: () => mounted(), form };
    } };
}
const cart = product => [{ product_id: product, quantity: 1, notes: '', sale_price: '10.00' }];
const record = () => ({ payload: { client_uuid: 'existing', service_context: 'context', tracking_key: 'a'.repeat(64), items: [{ product_id: 1, quantity: 1 }] }, cart: cart(1), uncertain: true, receipt: null });

test('delayed tracking cannot regress status or erase a newer draft after resend', async () => {
    const env = environment();
    let release;
    const page = env.tab({ post: async () => ({ receipt: { status: 'confirmed' } }), get: () => new Promise(resolve => release = resolve) });
    const original = record();
    page.journal.value = [original]; page.cart.value = cart(1);
    env.values.set('menu-receipts:table', JSON.stringify([original]));
    env.values.set('menu-receipts:table:existing', JSON.stringify(original));
    const pending = page.poll();
    await page.send(); page.cart.value = cart(2);
    release({ receipt: { status: 'pending' } }); await pending;
    assert.equal(page.journal.value.find(item => item.payload.client_uuid === 'existing').receipt.status, 'confirmed');
    assert.equal(page.cart.value[0]?.product_id, 2);
});

test('another tab resolves the original ambiguous submission and preserves its own draft', async () => {
    const env = environment();
    let firstPayload, secondPayload;
    const first = env.tab({ post: async payload => { firstPayload = payload; throw new Error('response lost after commit'); } });
    const second = env.tab({ post: async payload => { secondPayload = payload; return { receipt: { status: 'pending' } }; } });
    first.cart.value = cart(1); second.cart.value = cart(2);
    await first.send(); await second.send();
    assert.equal(secondPayload, undefined, 'a newly discovered unresolved submission requires explicit recovery');
    await second.send();
    assert.deepEqual(secondPayload, firstPayload, 'original UUID, key and content must be recovered');
    assert.ok([...env.values.values()].some(value => value.includes(firstPayload.tracking_key)));
    assert.equal(second.cart.value[0]?.product_id, 2);
    const reload = env.tab(); reload.start();
    assert.ok(reload.journal.value.some(item => item.payload.client_uuid === firstPayload.client_uuid));
});

test('network retry keeps UUID, key, context and content across reload', async () => {
    const env = environment();
    let firstPayload, recovered;
    const first = env.tab({ post: async payload => { firstPayload = JSON.parse(JSON.stringify(payload)); throw new Error('offline'); } });
    first.cart.value = cart(1); await first.send();
    assert.ok(first.journal.value[0].uncertain);
    const reload = env.tab({ post: async payload => { recovered = JSON.parse(JSON.stringify(payload)); return { receipt: { status: 'pending' } }; } });
    reload.start(); assert.equal(reload.error.value.includes('Não foi possível recuperar'), false, reload.error.value); assert.ok(reload.journal.value.length, reload.error.value); await reload.send();
    assert.deepEqual(recovered, firstPayload);
    assert.equal(reload.journal.value[0].uncertain, false);
});
