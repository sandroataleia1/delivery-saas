/** Centavos inteiros: somar reais em ponto flutuante acumula erro. */
export function toCents(value) {
    return Math.round(Number((Number(value || 0) * 100).toFixed(4)));
}

export function lineCents(quantity, price) {
    return Math.round(Number((Number(quantity || 0) * toCents(price)).toFixed(4)));
}

export const fromCents = (cents) => (cents / 100).toFixed(2);
