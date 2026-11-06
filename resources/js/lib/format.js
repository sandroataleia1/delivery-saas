const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const quantity = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 });

export function formatMoney(value) {
    return money.format(Number(value ?? 0));
}

export function formatQuantity(value, unit = '') {
    return `${quantity.format(Number(value ?? 0))} ${unit}`.trim();
}

export function formatCnpj(value) {
    const cnpj = String(value ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 14);

    return cnpj
        .replace(/^(.{2})(.)/, '$1.$2')
        .replace(/^(.{2}\..{3})(.)/, '$1.$2')
        .replace(/^(.{2}\..{3}\..{3})(.)/, '$1/$2')
        .replace(/^(.{2}\..{3}\..{3}\/.{4})(.)/, '$1-$2');
}

export function formatCep(value) {
    const digits = String(value ?? '').replace(/\D/g, '').slice(0, 8);

    return digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
}

/** Busca o endereço na ViaCEP. Retorna null se falhar: o formulário segue manual. */
export async function fetchAddress(cep) {
    try {
        const response = await fetch(`https://viacep.com.br/ws/${cep.replace(/\D/g, '')}/json/`);
        const data = await response.json();

        if (!response.ok || data.erro) {
            return null;
        }

        return { street: data.logradouro, district: data.bairro, city: data.localidade, state: data.uf };
    } catch {
        return null;
    }
}

export function formatPhone(value) {
    const digits = String(value ?? '').replace(/\D/g, '').slice(0, 11);

    if (digits.length <= 2) {
        return digits;
    }

    const middle = digits.length > 10 ? 7 : 6;

    return `(${digits.slice(0, 2)}) ${digits.slice(2, middle)}${digits.length > middle ? '-' : ''}${digits.slice(middle)}`;
}
