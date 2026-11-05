/** Centavos inteiros: somar reais em ponto flutuante acumula erro. */
export function toCents(value) {
    return Math.round(Number((Number(value || 0) * 100).toFixed(4)));
}

export function lineCents(quantity, price) {
    return Math.round(Number((Number(quantity || 0) * toCents(price)).toFixed(4)));
}

export const fromCents = (cents) => (cents / 100).toFixed(2);

/** crypto.randomUUID só existe em HTTPS ou localhost; a rede local da loja pode não ter. */
export const newUuid = () => (crypto.randomUUID
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => Math.floor(Math.random() * 16).toString(16)));
