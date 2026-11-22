<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Images, Plus, Wallet } from '@lucide/vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/generations';

type GenerationSummary = {
    id: number;
    status: string;
    prompt: string;
    actual_user_cost_display: string | null;
    estimated_max_display: string;
    is_free: boolean;
    is_pending: boolean;
    created_at_human: string | null;
    show_url: string;
};

type StatusCounts = {
    total: number;
    queued: number;
    processing: number;
    succeeded: number;
    failed: number;
};

defineProps<{
    walletBalance: string;
    counts: StatusCounts;
    recentGenerations: GenerationSummary[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <Head title="Dashboard" />

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription class="flex items-center gap-1.5">
                        <Wallet class="size-3.5" />
                        Wallet Balance
                    </CardDescription>
                    <CardTitle class="text-3xl">{{ walletBalance }}</CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription class="flex items-center gap-1.5">
                        <Images class="size-3.5" />
                        Generations
                    </CardDescription>
                    <CardTitle class="text-3xl">{{ counts.total }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    {{ counts.queued + counts.processing }} pending ·
                    {{ counts.succeeded }} succeeded ·
                    {{ counts.failed }} failed
                </CardContent>
            </Card>
            <Card
                class="flex flex-col justify-between bg-gradient-to-br from-primary/10 to-transparent"
            >
                <CardHeader class="pb-2">
                    <CardDescription>Ready to create?</CardDescription>
                    <CardTitle class="text-lg">Generate a new image</CardTitle>
                </CardHeader>
                <CardContent>
                    <Button as-child>
                        <Link :href="create()">
                            <Plus class="mr-2 size-4" />
                            Open Studio
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        </div>

        <Card class="flex-1">
            <CardHeader class="flex-row items-center justify-between">
                <div class="flex flex-col gap-1">
                    <CardTitle>Recent Generations</CardTitle>
                    <CardDescription
                        >Your latest image requests and their
                        status</CardDescription
                    >
                </div>
                <Button as-child variant="ghost" size="sm">
                    <Link :href="index()">
                        View all
                        <ArrowRight class="ml-1.5 size-4" />
                    </Link>
                </Button>
            </CardHeader>
            <CardContent>
                <div
                    v-if="recentGenerations.length === 0"
                    class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border p-8 text-center"
                >
                    <Images class="size-6 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">
                        No generations yet — create your first image in the
                        Studio.
                    </p>
                </div>
                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="generation in recentGenerations"
                        :key="generation.id"
                    >
                        <Link
                            :href="generation.show_url"
                            class="flex items-center justify-between gap-4 py-3 transition-colors hover:bg-muted/40"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <p class="line-clamp-1 text-sm font-medium">
                                    {{ generation.prompt }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ generation.created_at_human }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-xs font-medium">
                                    <template v-if="generation.is_free"
                                        >Free</template
                                    >
                                    <template
                                        v-else-if="
                                            generation.actual_user_cost_display
                                        "
                                        >{{
                                            generation.actual_user_cost_display
                                        }}</template
                                    >
                                    <template v-else
                                        >≤
                                        {{
                                            generation.estimated_max_display
                                        }}</template
                                    >
                                </span>
                                <GenerationStatusBadge
                                    :status="generation.status"
                                />
                            </div>
                        </Link>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
