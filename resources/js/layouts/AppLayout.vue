<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Bike,
    Boxes,
    Bike as Motorcycle,
    Building2,
    CalendarDays,
    ChartColumn,
    ChevronDown,
    EllipsisVertical,
    CircleArrowDown,
    CircleArrowUp,
    Contact,
    Globe,
    MessageCircle,
    ShoppingBag,
    History,
    House,
    Landmark,
    LogOut,
    Menu,
    Armchair,
    Package,
    PackagePlus,
    Receipt,
    ShieldCheck,
    ShoppingCart,
    Store,
    Truck,
    UserCog,
    Users,
    X,
} from '@lucide/vue';
import FlashMessages from '@/components/FlashMessages.vue';
import { usePermissions } from '@/lib/can';

defineProps({
    title: { type: String, required: true },
});

const page = usePage();
const { can } = usePermissions();
const menuOpen = ref(false);
const userMenuOpen = ref(false);
const userMenu = ref(null);

function closeUserMenu(event) {
    if (event.type === 'keydown' ? event.key === 'Escape' : !userMenu.value?.contains(event.target)) {
        userMenuOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', closeUserMenu);
    document.addEventListener('keydown', closeUserMenu);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeUserMenu);
    document.removeEventListener('keydown', closeUserMenu);
});

const user = computed(() => page.props.auth.user);
const company = computed(() => page.props.auth.company);

// Cada item fica onde quem trabalha procuraria: o dia a dia primeiro, a gestão por último.
const groups = computed(() => (user.value.is_super_admin ? [] : [
    {
        name: 'Vendas',
        items: [
            can('sales.create') && { label: 'PDV', href: route('pos'), match: '/pdv', icon: ShoppingCart },
            can('production.view') && { label: 'Produção', href: route('production.index'), match: '/producao', icon: Store },
            can('production.configure') && !can('production.view') && { label: 'Setores', href: route('production.sectors'), match: '/producao/setores', icon: Store },
            can('tables.operate') && { label: 'Mesas', href: route('tables.index'), match: '/mesas', icon: Armchair },
            can('deliveries.operate') && { label: 'Entregas', href: route('deliveries.board'), match: '/entregas', icon: Motorcycle },
            can('deliveries.courier') && !can('deliveries.operate') && { label: 'Minhas entregas', href: route('deliveries.mine'), match: '/entregas/minhas', icon: Motorcycle },
            can('online_orders.accept') && { label: 'Pedidos online', href: route('online.orders'), match: '/loja-online/pedidos', icon: ShoppingBag, badge: page.props.onlinePendingCount },
            can('sales.view') && { label: 'Vendas', href: route('sales.index'), match: '/vendas', icon: Receipt },
            can('customers.view') && { label: 'Clientes', href: route('customers.index'), match: '/clientes', icon: Contact },
        ],
    },
    {
        name: 'Financeiro',
        items: [
            can('cash.operate') && { label: 'Caixa', href: route('cash.index'), match: '/caixa', exclude: '/caixa/fluxo', icon: Landmark },
            can('cash.manage') && { label: 'Fluxo de caixa', href: route('cash.flow'), match: '/caixa/fluxo', icon: CalendarDays },
            can('finance.view') && { label: 'Contas a pagar', href: route('bills.index', 'pagar'), match: '/financeiro/pagar', icon: CircleArrowUp },
            can('finance.view') && { label: 'Contas a receber', href: route('bills.index', 'receber'), match: '/financeiro/receber', icon: CircleArrowDown },
        ],
    },
    {
        name: 'Estoque',
        items: [
            can('products.view') && { label: 'Produtos', href: route('products.index'), match: '/produtos', icon: Package },
            can('stock.view') && { label: 'Estoque', href: route('stock.index'), match: '/estoque', icon: Boxes },
            can('stock.view') && { label: 'Entradas', href: route('entries.index'), match: '/entradas', icon: PackagePlus },
            can('suppliers.manage') && { label: 'Fornecedores', href: route('suppliers.index'), match: '/fornecedores', icon: Truck },
        ],
    },
    {
        name: 'Gestão',
        items: [
            (can('reports.view') || can('reports.finance')) && { label: 'Relatórios', href: route('reports.index'), match: '/relatorios', icon: ChartColumn },
            can('users.view') && { label: 'Usuários', href: route('users.index'), match: '/usuarios', icon: Users },
            can('roles.view') && { label: 'Perfis de acesso', href: route('roles.index'), match: '/perfis', icon: ShieldCheck },
            can('audit.view') && { label: 'Auditoria', href: route('audit.index'), match: '/auditoria', icon: History },
            can('company.manage') && { label: 'Loja online', href: route('online.settings'), match: '/loja-online/configuracao', icon: Globe },
            can('company.manage') && { label: 'WhatsApp', href: route('online.whatsapp'), match: '/loja-online/whatsapp', icon: MessageCircle },
            can('company.manage') && { label: 'Empresa', href: route('company.edit'), match: '/empresa', icon: Store },
        ],
    },
].map((group) => ({ ...group, items: group.items.filter(Boolean) })).filter((group) => group.items.length > 0)));

const topItems = computed(() => (user.value.is_super_admin
    ? [
        { label: 'Empresas', href: route('admin.companies.index'), match: '/admin/empresas', icon: Building2 },
        { label: 'Auditoria', href: route('admin.audit.index'), match: '/admin/auditoria', icon: History },
    ]
    : [{ label: 'Início', href: route('dashboard'), match: '/', exact: true, icon: House }]));

// Quem tem poucos itens, como garçom e entregador, vê tudo aberto.
const isCollapsible = computed(() => groups.value.reduce((sum, group) => sum + group.items.length, 0) > 10);

const STORAGE_KEY = 'menu-sections';

function loadOpenSections() {
    try {
        return new Set(JSON.parse(localStorage.getItem(STORAGE_KEY)) ?? ['Vendas']);
    } catch {
        return new Set(['Vendas']);
    }
}

const openSections = ref(loadOpenSections());

function isOpen(group) {
    return !isCollapsible.value || openSections.value.has(group.name) || group.items.some(isActive);
}

function toggle(group) {
    const next = new Set(openSections.value);

    if (next.has(group.name)) {
        next.delete(group.name);
    } else {
        next.add(group.name);
    }

    openSections.value = next;

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify([...next]));
    } catch {
        // Sem armazenamento, o menu só não lembra das seções abertas.
    }
}

function isActive(item) {
    const path = page.url.split('?')[0];

    if (item.exclude && path.startsWith(item.exclude)) {
        return false;
    }

    return item.exact ? path === item.match : path.startsWith(item.match);
}

watch(() => page.url, () => (menuOpen.value = false));
</script>

<template>
    <div class="min-h-dvh lg:pl-64 print:pl-0">
        <Head :title="title" />
        <FlashMessages />

        <div v-if="menuOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="menuOpen = false" />

        <aside
            class="bg-sidebar text-sidebar-text fixed inset-y-0 left-0 z-40 flex w-64 flex-col transition-transform lg:translate-x-0 print:hidden"
            :class="menuOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center justify-between px-5">
                <span class="flex min-w-0 items-center gap-2.5 text-white">
                    <span class="bg-brand flex size-9 shrink-0 items-center justify-center rounded-lg">
                        <Bike :size="20" />
                    </span>
                    <span class="truncate font-bold tracking-tight">{{ company?.trade_name ?? 'Delivery SaaS' }}</span>
                </span>
                <button type="button" class="cursor-pointer lg:hidden" aria-label="Fechar menu" @click="menuOpen = false">
                    <X :size="20" />
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-2" aria-label="Menu principal">
                <Link
                    v-for="item in topItems"
                    :key="item.href"
                    :href="item.href"
                    class="hover:bg-sidebar-hover flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:text-white"
                    :class="{ 'bg-sidebar-hover text-white': isActive(item) }"
                    :aria-current="isActive(item) ? 'page' : undefined"
                >
                    <component :is="item.icon" :size="18" />
                    {{ item.label }}
                </Link>

                <section v-for="group in groups" :key="group.name" class="mt-2">
                    <button
                        v-if="isCollapsible"
                        type="button"
                        class="flex w-full cursor-pointer items-center justify-between rounded-lg px-3 py-1.5 text-xs font-semibold tracking-wide text-white/50 uppercase hover:text-white"
                        :aria-expanded="isOpen(group)"
                        @click="toggle(group)"
                    >
                        {{ group.name }}
                        <ChevronDown :size="14" class="transition-transform" :class="{ '-rotate-90': !isOpen(group) }" />
                    </button>
                    <p v-else class="px-3 py-1.5 text-xs font-semibold tracking-wide text-white/50 uppercase">{{ group.name }}</p>

                    <div v-if="isOpen(group)" class="space-y-0.5">
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="hover:bg-sidebar-hover flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:text-white"
                            :class="{ 'bg-sidebar-hover text-white': isActive(item) }"
                            :aria-current="isActive(item) ? 'page' : undefined"
                        >
                            <component :is="item.icon" :size="18" />
                            {{ item.label }}
                            <span v-if="item.badge" class="bg-danger ml-auto rounded-full px-2 text-xs font-bold text-white" :aria-label="`${item.badge} pendente(s)`">{{ item.badge }}</span>
                        </Link>
                    </div>
                </section>
            </nav>

            <div ref="userMenu" class="relative flex items-center gap-1 border-t border-white/10 p-3">
                <Link
                    :href="route('account.edit')"
                    class="hover:bg-sidebar-hover flex min-w-0 flex-1 items-center gap-3 rounded-lg px-3 py-2 transition-colors"
                >
                    <UserCog :size="18" class="shrink-0" />
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-white">{{ user.name }}</span>
                        <span class="block truncate text-xs">{{ user.is_super_admin ? 'Super administrador' : user.role }}</span>
                    </span>
                </Link>
                <button
                    type="button"
                    class="hover:bg-sidebar-hover shrink-0 cursor-pointer rounded-lg p-2 transition-colors hover:text-white"
                    :class="{ 'bg-sidebar-hover text-white': userMenuOpen }"
                    aria-label="Opções do usuário"
                    aria-haspopup="menu"
                    :aria-expanded="userMenuOpen"
                    @click="userMenuOpen = !userMenuOpen"
                >
                    <EllipsisVertical :size="18" />
                </button>

                <div
                    v-if="userMenuOpen"
                    role="menu"
                    class="bg-sidebar absolute right-3 bottom-full z-50 mb-1 w-44 rounded-lg border border-white/10 p-1 shadow-lg"
                >
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        role="menuitem"
                        class="hover:bg-sidebar-hover flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors hover:text-white"
                    >
                        <LogOut :size="18" />
                        Sair
                    </Link>
                </div>
            </div>
        </aside>

        <header class="border-line bg-surface sticky top-0 z-20 flex h-14 items-center gap-3 border-b px-4 lg:hidden print:hidden">
            <button type="button" class="cursor-pointer" aria-label="Abrir menu" @click="menuOpen = true">
                <Menu :size="22" />
            </button>
            <span class="truncate font-bold">{{ company?.trade_name ?? 'Delivery SaaS' }}</span>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <slot />
        </main>
    </div>
</template>
