/**
 * Adapta uma lista de insumos de um formulário maior ao formato que o RecipeEditor espera
 * (form.recipe e form.errors['recipe.N.campo']). Os erros vêm do caminho `prefix.N.campo`.
 */
export function recipeFormFor(form, rows, prefix) {
    return {
        recipe: rows,
        sale_price: 0,
        errors: new Proxy({}, {
            get: (_, key) => {
                if (key === 'recipe') {
                    return form.errors[prefix];
                }

                return typeof key === 'string' && key.startsWith('recipe.') ? form.errors[`${prefix}.${key.slice(7)}`] : undefined;
            },
        }),
    };
}

/** Só o que o servidor aceita de uma ficha: ids e quantidades. */
export const recipePayload = (rows) => rows.map(({ ingredient_id, quantity }) => ({ ingredient_id, quantity }));

/** Linhas vindas do servidor, com a quantidade sem zeros à direita. */
export const recipeRows = (rows) => (rows ?? []).map((item) => ({ ...item, quantity: String(Number(item.quantity)) }));
