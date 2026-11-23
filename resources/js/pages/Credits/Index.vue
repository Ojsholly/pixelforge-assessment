<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    CheckCircle2,
    Coins,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { index, purchase } from '@/routes/credits';
import { index as generationsIndex } from '@/routes/generations';

type LedgerRow = {
    id: number;
    direction: 'credit' | 'debit';
    type: string;
    signed_amount_display: string;
    balance_after_display: string;
    description: string;
    generation_label: string | null;
    generation_url: string | null;
    created_at: string | null;
    created_at_human: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedTransactions = {
    data: LedgerRow[];
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
};

type Filters = {
    type: string | null;
    direction: string | null;
    sort: string;
};

const props = defineProps<{
    transactions: PaginatedTransactions;
    filters: Filters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const page = usePage();
const wallet = computed(() => page.props.wallet);
const flashStatus = computed(() => page.props.flash?.status);

const packages = [
    { label: '+$5.00', amount: '5.00' },
    { label: '+$10.00', amount: '10.00' },
    { label: '+$25.00', amount: '25.00' },
];

const form = useForm({
    amount_usd: '',
});

const typeLabels: Record<string, string> = {
    purchase: 'Purchase',
    hold: 'Hold',
    capture: 'Capture',
    release: 'Refund',
};

const typeTabs = [
    { key: null, label: 'All types' },
    { key: 'purchase', label: 'Purchases' },
    { key: 'hold', label: 'Holds' },
    { key: 'capture', label: 'Captures' },
    { key: 'release', label: 'Refunds' },
];

const directionTabs = [
    { key: null, label: 'All' },
    { key: 'credit', label: 'Credits' },
    { key: 'debit', label: 'Debits' },
];

function ledgerQuery(overrides: {
    type?: string | null;
    direction?: string | null;
    sort?: string;
}): { type?: string; direction?: string; sort?: string } {
    const type =
        overrides.type !== undefined ? overrides.type : props.filters.type;
    const direction =
        overrides.direction !== undefined
            ? overrides.direction
            : props.filters.direction;
    const sort =
        overrides.sort !== undefined ? overrides.sort : props.filters.sort;

    const query: { type?: string; direction?: string; sort?: string } = {};

    if (type !== null) {
        query.type = type;
    }
    if (direction !== null) {
        query.direction = direction;
    }
    if (sort !== '-created_at') {
        query.sort = sort;
    }

    return query;
}

function filterHref(type: string | null, direction: string | null): string {
    return index.url({ query: ledgerQuery({ type, direction }) });
}

function sortHref(column: string): string {
    const isCurrent =
        props.filters.sort === column || props.filters.sort === `-${column}`;
    const descending = isCurrent ? !props.filters.sort.startsWith('-') : true;

    return index.url({
        query: ledgerQuery({
            sort: descending ? `-${column}` : column,
        }),
    });
}

function sortIcon(column: string): typeof ArrowUpDown {
    if (props.filters.sort === `-${column}`) {
        return ArrowDown;
    }
    if (props.filters.sort === column) {
        return ArrowUp;
    }
    return ArrowUpDown;
}

function buyPackage(amountUsd: string): void {
    form.amount_usd = amountUsd;
    form.post(purchase.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function paginationLabel(label: string): string {
    return label
        .replaceAll('&laquo;', '«')
        .replaceAll('&raquo;', '»')
        .replaceAll('&hellip;', '…');
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <Head title="Credits" />

        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Credits</h1>
            <p class="text-sm text-muted-foreground">
                Manage your wallet balance and review every ledger entry.
            </p>
        </div>

        <div
            v-if="flashStatus"
            class="flex items-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400"
        >
            <CheckCircle2 class="size-4" />
            {{ flashStatus }}
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription class="flex items-center gap-1.5">
                        <Wallet class="size-3.5" />
                        Current Balance
                    </CardDescription>
                    <CardTitle class="text-3xl">{{
                        wallet?.balance_display
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    Generation holds debit this balance and settle against
                    actual usage.
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardDescription class="flex items-center gap-1.5">
                        <Coins class="size-3.5" />
                        Buy Credits
                    </CardDescription>
                    <CardTitle class="text-base">Add funds</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="pkg in packages"
                            :key="pkg.amount"
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="buyPackage(pkg.amount)"
                        >
                            {{ pkg.label }}
                        </Button>
                    </div>
                    <form
                        class="flex items-start gap-2"
                        @submit.prevent="
                            form.post(purchase.url(), {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            })
                        "
                    >
                        <div class="flex flex-1 flex-col gap-1">
                            <Input
                                v-model="form.amount_usd"
                                type="text"
                                inputmode="decimal"
                                placeholder="Custom amount (USD)"
                                :disabled="form.processing"
                            />
                            <InputError :message="form.errors.amount_usd" />
                        </div>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Adding…' : 'Add' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Transaction History</CardTitle>
                <CardDescription>
                    Every credit and debit applied to your wallet.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4 p-0">
                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-border px-4 pt-4"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            v-for="tab in typeTabs"
                            :key="tab.label"
                            :href="filterHref(tab.key, filters.direction)"
                            preserve-scroll
                            class="rounded-full border px-3 py-1 text-xs transition-colors"
                            :class="
                                filters.type === tab.key
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-background text-muted-foreground hover:text-foreground'
                            "
                        >
                            {{ tab.label }}
                        </Link>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-muted-foreground"
                            >Direction:</span
                        >
                        <Link
                            v-for="tab in directionTabs"
                            :key="tab.label"
                            :href="filterHref(filters.type, tab.key)"
                            preserve-scroll
                            class="rounded-full border px-3 py-1 text-xs transition-colors"
                            :class="
                                filters.direction === tab.key
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-background text-muted-foreground hover:text-foreground'
                            "
                        >
                            {{ tab.label }}
                        </Link>
                    </div>
                </div>

                <div
                    v-if="transactions.data.length === 0"
                    class="flex flex-col items-center gap-2 border-t border-dashed border-border p-8 text-center"
                >
                    <Coins class="size-6 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">
                        <template v-if="filters.type || filters.direction">
                            No transactions match the current filters.
                        </template>
                        <template v-else>
                            No transactions yet — buy credits or generate an
                            image to see ledger entries.
                        </template>
                    </p>
                    <Button
                        v-if="filters.type || filters.direction"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="index()">Clear filters</Link>
                    </Button>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-y border-border text-left text-xs text-muted-foreground"
                            >
                                <th class="px-4 py-2 font-medium">
                                    <Link
                                        :href="sortHref('created_at')"
                                        preserve-scroll
                                        class="inline-flex items-center gap-1 hover:text-foreground"
                                    >
                                        Date
                                        <component
                                            :is="sortIcon('created_at')"
                                            class="size-3"
                                        />
                                    </Link>
                                </th>
                                <th class="px-4 py-2 font-medium">Type</th>
                                <th class="px-4 py-2 font-medium">
                                    Description
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    <Link
                                        :href="sortHref('amount')"
                                        preserve-scroll
                                        class="inline-flex items-center justify-end gap-1 hover:text-foreground"
                                    >
                                        Amount
                                        <component
                                            :is="sortIcon('amount')"
                                            class="size-3"
                                        />
                                    </Link>
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    <Link
                                        :href="sortHref('balance_after')"
                                        preserve-scroll
                                        class="inline-flex items-center justify-end gap-1 hover:text-foreground"
                                    >
                                        Balance After
                                        <component
                                            :is="sortIcon('balance_after')"
                                            class="size-3"
                                        />
                                    </Link>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tx in transactions.data"
                                :key="tx.id"
                                class="border-b border-border last:border-0"
                            >
                                <td
                                    class="px-4 py-2.5 whitespace-nowrap text-muted-foreground"
                                >
                                    <span :title="tx.created_at ?? undefined">{{
                                        tx.created_at_human
                                    }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <Badge variant="secondary">{{
                                        typeLabels[tx.type] ?? tx.type
                                    }}</Badge>
                                </td>
                                <td class="px-4 py-2.5">
                                    <Link
                                        v-if="tx.generation_url"
                                        :href="tx.generation_url"
                                        class="text-primary underline-offset-4 hover:underline"
                                        >{{ tx.description }}</Link
                                    >
                                    <span v-else>{{ tx.description }}</span>
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-mono whitespace-nowrap"
                                    :class="
                                        tx.direction === 'credit'
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-foreground'
                                    "
                                >
                                    {{ tx.signed_amount_display }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-mono whitespace-nowrap text-muted-foreground"
                                >
                                    {{ tx.balance_after_display }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="transactions.links.length > 3"
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <p class="text-xs text-muted-foreground">
                Showing {{ transactions.from ?? 0 }}–{{
                    transactions.to ?? 0
                }}
                of {{ transactions.total }} transactions
            </p>
            <div class="flex flex-wrap gap-1">
                <template v-for="link in transactions.links" :key="link.label">
                    <Button
                        v-if="link.url"
                        as-child
                        variant="outline"
                        size="sm"
                        :class="{ 'border-primary text-primary': link.active }"
                    >
                        <Link :href="link.url" preserve-scroll>{{
                            paginationLabel(link.label)
                        }}</Link>
                    </Button>
                    <Button v-else variant="outline" size="sm" disabled>{{
                        paginationLabel(link.label)
                    }}</Button>
                </template>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            Generation-related entries link to their
            <Link
                :href="generationsIndex()"
                class="underline underline-offset-4"
                >generation</Link
            >.
        </p>
    </div>
</template>
