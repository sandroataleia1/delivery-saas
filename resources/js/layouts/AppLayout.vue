<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Bike,
    Boxes,
    Building2,
    CalendarDays,
    History,
    House,
    Landmark,
    LogOut,
    Menu,
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

const user = computed(() => page.props.auth.user);
const company = computed(() => page.props.auth.company);

const nav = computed(() => {
    if (user.value.is_super_admin) {
        return [
            { label: 'Empresas', href: route('admin.companies.index'), match: '/admin/empresas', icon: Building2 },
            { label: 'Auditoria', href: route('admin.audit.index'), match: '/admin/auditoria', icon: History },
        ];
    }

    return [
        { label: 'Início', href: route('dashboard'), match: '/', exact: true, icon: House },
        can('sales.create') && { label: 'PDV', href: route('pos'), match: '/pdv', icon: ShoppingCart },
        can('sales.view') && { label: 'Vendas', href: route('sales.index'), match: '/vendas', icon: Receipt },
        can('cash.operate') && { label: 'Caixa', href: route('cash.index'), match: '/caixa', exclude: '/caixa/fluxo', icon: Landmark },
        can('cash.manage') && { label: 'Fluxo de caixa', href: route('cash.flow'), match: '/caixa/fluxo', icon: CalendarDays },
        can('products.view') && { label: 'Produtos', href: route('products.index'), match: '/produtos', icon: Package },
        can('stock.view') && { label: 'Estoque', href: route('stock.index'), match: '/estoque', icon: Boxes },
        can('stock.view') && { label: 'Entradas', href: route('entries.index'), match: '/entradas', icon: PackagePlus },
        can('suppliers.manage') && { label: 'Fornecedores', href: route('suppliers.index'), match: '/fornecedores', icon: Truck },
        can('users.view') && { label: 'Usuários', href: route('users.index'), match: '/usuarios', icon: Users },
        can('roles.view') && { label: 'Perfis de acesso', href: route('roles.index'), match: '/perfis', icon: ShieldCheck },
        can('audit.view') && { label: 'Auditoria', href: route('audit.index'), match: '/auditoria', icon: History },
        can('company.manage') && { label: 'Empresa', href: route('company.edit'), match: '/empresa', icon: Store },
    ].filter(Boolean);
});

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
    <div class="min-h-dvh lg:pl-64">
        <Head :title="title" />
        <FlashMessages />

        <div v-if="menuOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="menuOpen = false" />

        <aside
            class="bg-sidebar text-sidebar-text fixed inset-y-0 left-0 z-40 flex w-64 flex-col transition-transform lg:translate-x-0"
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

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3" aria-label="Menu principal">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="hover:bg-sidebar-hover flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:text-white"
                    :class="{ 'bg-sidebar-hover text-white': isActive(item) }"
                    :aria-current="isActive(item) ? 'page' : undefined"
                >
                    <component :is="item.icon" :size="18" />
                    {{ item.label }}
                </Link>
            </nav>

            <div class="border-t border-white/10 p-3">
                <Link
                    :href="route('account.edit')"
                    class="hover:bg-sidebar-hover flex items-center gap-3 rounded-lg px-3 py-2 transition-colors"
                >
                    <UserCog :size="18" class="shrink-0" />
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-white">{{ user.name }}</span>
                        <span class="block truncate text-xs">{{ user.is_super_admin ? 'Super administrador' : user.role }}</span>
                    </span>
                </Link>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="hover:bg-sidebar-hover mt-1 flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors hover:text-white"
                >
                    <LogOut :size="18" />
                    Sair
                </Link>
            </div>
        </aside>

        <header class="border-line bg-surface sticky top-0 z-20 flex h-14 items-center gap-3 border-b px-4 lg:hidden">
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
