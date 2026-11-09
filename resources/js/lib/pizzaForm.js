// No formulário cada tamanho tem uma "key" só de tela; no envio ela vira o id do tamanho ou "n" + índice do novo.
let counter = 0;

export const newSizeKey = () => `new${counter++}`;

export function pizzaRows(pizza) {
    const sizes = (pizza?.sizes ?? []).map((size) => ({
        key: String(size.id),
        id: size.id,
        name: size.name,
        slices: size.slices ?? '',
        max_flavors: size.max_flavors,
        active: size.active,
    }));

    return {
        pizza_pricing: pizza?.pizza_pricing ?? 'highest',
        sizes,
        flavors: (pizza?.flavors ?? []).map((flavor) => ({
            id: flavor.id,
            name: flavor.name,
            active: flavor.active,
            prices: Object.fromEntries(sizes.map((size) => [size.key, flavor.prices[size.id] ?? ''])),
        })),
    };
}

export function pizzaPayload(pizza) {
    const keyOf = Object.fromEntries(pizza.sizes.map((size, index) => [size.key, size.id ? String(size.id) : `n${index}`]));

    return {
        pizza_pricing: pizza.pizza_pricing,
        sizes: pizza.sizes.map((size) => ({
            ...(size.id ? { id: size.id } : {}),
            name: size.name,
            slices: size.slices === '' ? null : size.slices,
            max_flavors: size.max_flavors,
            active: size.active,
        })),
        flavors: pizza.flavors.map((flavor) => ({
            ...(flavor.id ? { id: flavor.id } : {}),
            name: flavor.name,
            active: flavor.active,
            prices: Object.fromEntries(
                Object.entries(flavor.prices)
                    .filter(([key, value]) => keyOf[key] !== undefined && value !== '' && value !== null)
                    .map(([key, value]) => [keyOf[key], value]),
            ),
        })),
    };
}
