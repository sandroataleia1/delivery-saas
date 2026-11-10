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
    ChefHat,
    ChevronDown,
    EllipsisVertical,
    CircleArrowDown,
    CircleArrowUp,
    Download,
    Contact,
    Globe,
    MessageCircle,
    ShoppingBag,
    History,
    House,
    Landmark,
    LayoutGrid,
    ListPlus,
    LogOut,
    MapPin,
    Menu,
    Armchair,
    Package,
    PackagePlus,
    Printer,
    ShieldCheck,
    ShoppingCart,
    Store,
    Tags,
    Truck,
    UserCog,
    Users,
    X,
} from '@lucide/vue';
import FlashMessages from '@/components/FlashMessages.vue';
import QrOrderAlert from '@/components/QrOrderAlert.vue';
import UpdateBanner from '@/components/UpdateBanner.vue';
import { usePermissions } from '@/lib/can';
import { useInstallApp } from '@/lib/installApp';

defineProps({
    title: { type: String, required: true },
});

const page = usePage();
const { can } = usePermissions();
const menuOpen = ref(false);
const userMenuOpen = ref(false);
const userMenu = ref(null);
const sidebarNav = ref(null);
const { canInstall, needsIosSteps, install } = useInstallApp();
const showIosSteps = ref(false);

function closeUserMenu(event) {
    if (event.type === 'keydown' ? event.key === 'Escape' : !userMenu.value?.contains(event.target)) {
        userMenuOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', closeUserMenu);
    document.addEventListener('keydown', closeUserMenu);
    // A cada página o menu volta ao topo: traz o item atual para a vista.
    sidebarNav.value?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
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
        name: 'Cadastros',
        items: [
            can('products.view') && { label: 'Produtos', href: route('products.index'), match: '/produtos', exclude: '/produtos/opcoes', icon: Package },
            can('products.view') && { label: 'Categorias', href: route('categories.index'), match: '/categorias', icon: Tags },
            can('products.manage') && { label: 'Opções', href: route('options.index'), match: '/produtos/opcoes', icon: ListPlus },
            can('customers.view') && { label: 'Clientes', href: route('customers.index'), match: '/clientes', icon: Contact },
            can('suppliers.manage') && { label: 'Fornecedores', href: route('suppliers.index'), match: '/fornecedores', icon: Truck },
            can('deliveries.manage') && { label: 'Bairros de entrega', href: route('deliveries.zones'), match: '/entregas/bairros', icon: MapPin },
            can('deliveries.manage') && { label: 'Entregadores', href: route('couriers.index'), match: '/entregas/entregadores', icon: Motorcycle },
            can('tables.manage') && { label: 'Mesas do salão', href: route('tables.manage'), match: '/mesas/gerenciar', icon: LayoutGrid },
            can('production.configure') && { label: 'Setores', href: route('production.sectors'), match: '/setores', icon: ChefHat },
            can('users.view') && { label: 'Usuários', href: route('users.index'), match: '/usuarios', icon: Users },
            can('roles.view') && { label: 'Perfis de acesso', href: route('roles.index'), match: '/perfis', icon: ShieldCheck },
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
            can('stock.view') && { label: 'Estoque', href: route('stock.index'), match: '/estoque', icon: Boxes },
            can('stock.view') && { label: 'Entradas', href: route('entries.index'), match: '/entradas', icon: PackagePlus },
        ],
    },
    {
        name: 'Gestão',
        items: [
            // Quem só consulta vendas cai direto na lista venda a venda, que fica junto dos relatórios.
            (can('reports.view') || can('reports.finance') || can('sales.view')) && {
                label: 'Relatórios',
                href: can('reports.view') || can('reports.finance') ? route('reports.index') : route('sales.index'),
                match: ['/relatorios', '/vendas'],
                icon: ChartColumn,
            },
            can('audit.view') && { label: 'Auditoria', href: route('audit.index'), match: '/auditoria', icon: History },
            can('company.manage') && { label: 'Loja online', href: route('online.settings'), match: '/loja-online/configuracao', icon: Globe },
            can('company.manage') && { label: 'WhatsApp', href: route('online.whatsapp'), match: '/loja-online/whatsapp', icon: MessageCircle },
            can('company.manage') && { label: 'Impressoras', href: route('printers.index'), match: '/impressoras', icon: Printer },
            can('company.manage') && { label: 'Empresa', href: route('company.edit'), match: '/empresa', icon: Store },
        ],
    },
].map((group) => ({ ...group, items: group.items.filter(Boolean) })).filter((group) => group.items.length > 0)));

const topItems = computed(() => (user.value.is_super_admin
    ? [
        { label: 'Empresas', href: route('admin.companies.index'), match: '/admin/empresas', icon: Building2 },
        { label: 'Auditoria', href: route('admin.audit.index'), match: '/admin/auditoria', icon: History },
    ]
    // Acesso rápido: o que se usa o dia todo fica sempre à mostra, fora dos grupos.
    : [
        { label: 'Início', href: route('dashboard'), match: '/', exact: true, icon: House },
        can('tables.operate') && { label: 'Mesas', href: route('tables.index'), match: '/mesas', exclude: '/mesas/gerenciar', icon: Armchair },
        can('online_orders.accept') && { label: 'Pedidos online', href: route('online.orders'), match: '/loja-online/pedidos', icon: ShoppingBag, badge: page.props.onlinePendingCount },
        can('sales.create') && { label: 'PDV', href: route('pos'), match: '/pdv', icon: ShoppingCart },
        can('production.view') && { label: 'Produção', href: route('production.index'), match: '/producao', icon: Store },
        can('deliveries.operate') && { label: 'Entregas', href: route('deliveries.board'), match: '/entregas', exclude: ['/entregas/bairros', '/entregas/minhas'], icon: Motorcycle },
        can('deliveries.courier') && !can('deliveries.operate') && { label: 'Minhas entregas', href: route('deliveries.mine'), match: '/entregas/minhas', icon: Motorcycle },
    ].filter(Boolean)));

// Quem tem poucos itens, como garçom e entregador, vê tudo aberto (o acesso rápido conta, menos o Início).
const isCollapsible = computed(() => groups.value.reduce((sum, group) => sum + group.items.length, topItems.value.length - 1) > 10);

// O menu não lembra grupos abertos entre visitas: só abre o grupo da página atual ou o que a pessoa abrir agora.
const openSections = ref(new Set());

try {
    localStorage.removeItem('menu-sections');
    localStorage.removeItem('menu-sections-v2');
} catch {
    // Sem armazenamento não há o que limpar.
}

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
}

function isActive(item) {
    const path = page.url.split('?')[0];

    if ([item.exclude ?? []].flat().some((prefix) => path.startsWith(prefix))) {
        return false;
    }

    return [item.match].flat().some((prefix) => (item.exact ? path === prefix : path.startsWith(prefix)));
}

watch(() => page.url, () => (menuOpen.value = false));
</script>

<template>
    <div class="min-h-dvh lg:pl-64 print:pl-0">
        <Head :title="title" />
        <FlashMessages />
        <UpdateBanner />
        <QrOrderAlert />

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

            <nav ref="sidebarNav" class="scrollbar-dark min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-2" aria-label="Menu principal">
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
                    <span v-if="item.badge" class="bg-danger ml-auto rounded-full px-2 text-xs font-bold text-white" :aria-label="`${item.badge} pendente(s)`">{{ item.badge }}</span>
                </Link>

                <section v-for="group in groups" :key="group.name" class="mt-3">
                    <button
                        v-if="isCollapsible"
                        type="button"
                        class="hover:bg-sidebar-hover flex w-full cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-[13px] font-bold tracking-wide text-white/90 uppercase transition-colors hover:text-white"
                        :aria-expanded="isOpen(group)"
                        @click="toggle(group)"
                    >
                        {{ group.name }}
                        <ChevronDown :size="16" class="transition-transform" :class="{ '-rotate-90': !isOpen(group) }" />
                    </button>
                    <p v-else class="px-3 py-2 text-[13px] font-bold tracking-wide text-white/90 uppercase">{{ group.name }}</p>

                    <div v-if="isOpen(group)" class="mt-0.5 ml-4 space-y-0.5 border-l border-white/10 pl-2">
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

            <div ref="userMenu" class="relative flex items-center gap-1 border-t border-white/10 px-2 py-1.5">
                <Link
                    :href="route('account.edit')"
                    class="hover:bg-sidebar-hover flex min-w-0 flex-1 items-center gap-2.5 rounded-lg px-2 py-1 transition-colors"
                >
                    <UserCog :size="16" class="shrink-0" />
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-[13px] font-semibold text-white">{{ user.name }}</span>
                        <span class="block truncate text-[11px]">{{ user.is_super_admin ? 'Super administrador' : user.role }}</span>
                    </span>
                </Link>
                <button
                    type="button"
                    class="hover:bg-sidebar-hover shrink-0 cursor-pointer rounded-lg p-1.5 transition-colors hover:text-white"
                    :class="{ 'bg-sidebar-hover text-white': userMenuOpen }"
                    aria-label="Opções do usuário"
                    aria-haspopup="menu"
                    :aria-expanded="userMenuOpen"
                    @click="userMenuOpen = !userMenuOpen"
                >
                    <EllipsisVertical :size="16" />
                </button>

                <div
                    v-if="userMenuOpen"
                    role="menu"
                    class="bg-sidebar absolute right-2 bottom-full z-50 mb-1 w-56 rounded-lg border border-white/10 p-1 shadow-lg"
                >
                    <button
                        v-if="canInstall"
                        type="button"
                        role="menuitem"
                        class="hover:bg-sidebar-hover flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors hover:text-white"
                        @click="userMenuOpen = false; install()"
                    >
                        <Download :size="18" />
                        Instalar app
                    </button>
                    <template v-else-if="needsIosSteps">
                        <button
                            type="button"
                            role="menuitem"
                            class="hover:bg-sidebar-hover flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors hover:text-white"
                            :aria-expanded="showIosSteps"
                            @click="showIosSteps = !showIosSteps"
                        >
                            <Download :size="18" />
                            Instalar app
                        </button>
                        <p v-if="showIosSteps" class="px-3 pb-2 text-xs">No Safari, toque em Compartilhar e depois em "Adicionar à Tela de Início".</p>
                    </template>
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
