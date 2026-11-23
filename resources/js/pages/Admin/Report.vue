<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { report } from '@/routes/admin';

type DayRow = {
    date: string;
    generations: number;
    user_spend_usd: string;
    user_spend_display: string;
    collected_usd: string;
    collected_display: string;
    uncollected_usd: string;
    uncollected_display: string;
    provider_cost_usd: string;
    provider_cost_display: string;
    profit_usd: string;
    profit_display: string;
    reported_cost_usd: string;
    reported_cost_display: string;
    delta_usd: string;
    delta_display: string;
    delta: number;
};

type UserRow = {
    name: string;
    email: string;
    generations: number;
    user_spend_usd: string;
    user_spend_display: string;
    collected_usd: string;
    collected_display: string;
    uncollected_usd: string;
    uncollected_display: string;
    provider_cost_usd: string;
    provider_cost_display: string;
    profit_usd: string;
    profit_display: string;
};

type Totals = {
    generations: number;
    provider_generations: number;
    user_spend_usd: string;
    user_spend_display: string;
    collected_usd: string;
    collected_display: string;
    uncollected_usd: string;
    uncollected_display: string;
    provider_cost_usd: string;
    provider_cost_display: string;
    profit_usd: string;
    profit_display: string;
    reported_cost_usd: string;
    reported_cost_display: string;
    delta_usd: string;
    delta_display: string;
    delta: number;
};

const props = defineProps<{
    range: { start_date: string; end_date: string; max_days: number };
    days: DayRow[];
    users: UserRow[];
    totals: Totals;
}>();

const page = usePage();
const errors = page.props.errors as Record<string, string | undefined>;

const form = reactive({
    start_date: props.range.start_date,
    end_date: props.range.end_date,
});

function applyRange(): void {
    router.get(
        report().url,
        { start_date: form.start_date, end_date: form.end_date },
        {
            preserveState: true,
            // Sync inputs only after a successful apply — a 422 redirect
            // would otherwise overwrite valid edits with the echoed range.
            onSuccess: () => {
                form.start_date = props.range.start_date;
                form.end_date = props.range.end_date;
            },
        },
    );
}

function resetRange(): void {
    router.get(report().url);
}

const deltaClass = (delta: number): string =>
    delta === 0
        ? 'text-muted-foreground'
        : 'font-medium text-red-600 dark:text-red-400';

const uncollectedClass = (usd: string): string =>
    usd === '0.000000000'
        ? 'text-muted-foreground'
        : 'font-medium text-amber-600 dark:text-amber-400';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usage Report', href: report() }],
    },
});
</script>

<template>
    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <Head title="Usage Report" />

        <div class="flex flex-wrap items-start gap-3">
            <div class="flex flex-col gap-1">
                <label
                    for="start_date"
                    class="text-xs font-medium text-muted-foreground"
                    >Start date (UTC)</label
                >
                <Input
                    id="start_date"
                    v-model="form.start_date"
                    type="date"
                    class="w-40"
                />
                <InputError :message="errors.start_date" />
            </div>
            <div class="flex flex-col gap-1">
                <label
                    for="end_date"
                    class="text-xs font-medium text-muted-foreground"
                    >End date (UTC)</label
                >
                <Input
                    id="end_date"
                    v-model="form.end_date"
                    type="date"
                    class="w-40"
                />
                <InputError :message="errors.end_date" />
            </div>
            <Button size="sm" class="mt-5" @click="applyRange">Apply</Button>
            <Button
                size="sm"
                variant="outline"
                class="mt-5"
                @click="resetRange"
            >
                Last 30 days
            </Button>
            <p class="mt-5 text-xs text-muted-foreground">
                Showing {{ range.start_date }} → {{ range.end_date }} — UTC
                days, billed on completion (max {{ range.max_days }} days)
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>Generations</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ totals.generations }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    Provider billed: {{ totals.provider_generations }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>User Spend (Billed)</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ totals.user_spend_display }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    {{ totals.user_spend_usd }} USD
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>Collected</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ totals.collected_display }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    Net wallet debit; uncollected:
                    {{ totals.uncollected_display }}
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>Provider Cost</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ totals.provider_cost_display }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    {{ totals.provider_cost_usd }} USD
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>Profit</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ totals.profit_display }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    {{ totals.profit_usd }} USD (billed − provider cost)
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>Reconciliation Delta</CardDescription>
                    <CardTitle
                        class="text-2xl"
                        :class="deltaClass(totals.delta)"
                    >
                        {{ totals.delta_display }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    ours {{ totals.provider_cost_usd }} − billed
                    {{ totals.reported_cost_usd }}
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Daily Breakdown</CardTitle>
                <CardDescription>
                    Succeeded generations grouped by UTC
                    <code>completed_at</code> date, reconciled against
                    Pixelforge's per-key usage report (delta = ours − billed).
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <th class="py-2 pr-4 font-medium">Date</th>
                            <th class="py-2 pr-4 font-medium">Generations</th>
                            <th class="py-2 pr-4 font-medium">Billed Spend</th>
                            <th class="py-2 pr-4 font-medium">Collected</th>
                            <th class="py-2 pr-4 font-medium">Uncollected</th>
                            <th class="py-2 pr-4 font-medium">Provider Cost</th>
                            <th class="py-2 pr-4 font-medium">Profit</th>
                            <th class="py-2 pr-4 font-medium">
                                Pixelforge Billed
                            </th>
                            <th class="py-2 font-medium">Delta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in days"
                            :key="day.date"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4 font-mono text-xs">
                                {{ day.date }}
                            </td>
                            <td class="py-2 pr-4">{{ day.generations }}</td>
                            <td class="py-2 pr-4" :title="day.user_spend_usd">
                                {{ day.user_spend_display }}
                            </td>
                            <td class="py-2 pr-4" :title="day.collected_usd">
                                {{ day.collected_display }}
                            </td>
                            <td
                                class="py-2 pr-4"
                                :class="uncollectedClass(day.uncollected_usd)"
                                :title="day.uncollected_usd"
                            >
                                {{ day.uncollected_display }}
                            </td>
                            <td
                                class="py-2 pr-4"
                                :title="day.provider_cost_usd"
                            >
                                {{ day.provider_cost_display }}
                            </td>
                            <td class="py-2 pr-4" :title="day.profit_usd">
                                {{ day.profit_display }}
                            </td>
                            <td
                                class="py-2 pr-4"
                                :title="day.reported_cost_usd"
                            >
                                {{ day.reported_cost_display }}
                            </td>
                            <td
                                class="py-2"
                                :class="deltaClass(day.delta)"
                                :title="day.delta_usd"
                            >
                                {{ day.delta_display }}
                            </td>
                        </tr>
                        <tr v-if="days.length === 0">
                            <td
                                colspan="9"
                                class="py-6 text-center text-muted-foreground"
                            >
                                No usage in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Per-User Totals</CardTitle>
                <CardDescription>
                    Succeeded generations only, ordered by spend. Billing
                    records of deleted accounts are retained and reported as
                    "Deleted users".
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <th class="py-2 pr-4 font-medium">User</th>
                            <th class="py-2 pr-4 font-medium">Generations</th>
                            <th class="py-2 pr-4 font-medium">Billed Spend</th>
                            <th class="py-2 pr-4 font-medium">Collected</th>
                            <th class="py-2 pr-4 font-medium">Provider Cost</th>
                            <th class="py-2 font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.email"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4">
                                <div class="font-medium">{{ user.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ user.email }}
                                </div>
                            </td>
                            <td class="py-2 pr-4">{{ user.generations }}</td>
                            <td class="py-2 pr-4" :title="user.user_spend_usd">
                                {{ user.user_spend_display }}
                            </td>
                            <td class="py-2 pr-4" :title="user.collected_usd">
                                {{ user.collected_display }}
                            </td>
                            <td
                                class="py-2 pr-4"
                                :title="user.provider_cost_usd"
                            >
                                {{ user.provider_cost_display }}
                            </td>
                            <td class="py-2" :title="user.profit_usd">
                                {{ user.profit_display }}
                            </td>
                        </tr>
                        <tr v-if="users.length === 0">
                            <td
                                colspan="6"
                                class="py-6 text-center text-muted-foreground"
                            >
                                No succeeded generations in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
