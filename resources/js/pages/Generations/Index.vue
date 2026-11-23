<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertCircle, ImageOff, Plus, RefreshCw } from '@lucide/vue';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index } from '@/routes/generations';

type GenerationSummary = {
    id: number;
    status: string;
    prompt: string;
    size: string;
    quality: string;
    reference_image_count: number;
    estimated_max_display: string;
    actual_user_cost_display: string | null;
    is_free: boolean;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    is_pending: boolean;
    created_at: string | null;
    created_at_human: string | null;
    show_url: string;
    refresh_url: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedGenerations = {
    data: GenerationSummary[];
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
};

type StatusCounts = {
    total: number;
    queued: number;
    processing: number;
    succeeded: number;
    failed: number;
};

const props = defineProps<{
    generations: PaginatedGenerations;
    counts: StatusCounts;
    filters: { status: string | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: index() }],
    },
});

const providerError = computed(
    () =>
        (usePage().props.errors as Record<string, string | undefined>).provider,
);

const tabs = computed(() => [
    { key: null, label: 'All', count: props.counts.total },
    { key: 'queued', label: 'Queued', count: props.counts.queued },
    {
        key: 'processing',
        label: 'Processing',
        count: props.counts.processing,
    },
    { key: 'succeeded', label: 'Succeeded', count: props.counts.succeeded },
    { key: 'failed', label: 'Failed', count: props.counts.failed },
]);

const hasPending = computed(() =>
    props.generations.data.some((generation) => generation.is_pending),
);

const activeTabLabel = computed(
    () =>
        tabs.value.find((tab) => tab.key === props.filters.status)?.label ??
        'All',
);

let poll:
    | { start: () => void; stop: () => void; destroy: () => void }
    | undefined;

// Poll registration is client-only: creating it inside onMounted keeps SSR
// renders from accumulating registrations on the render server.
onMounted(() => {
    poll = router.poll(
        4000,
        { only: ['generations', 'counts'] },
        { autoStart: false },
    );

    if (hasPending.value) {
        poll.start();
    }
});

watch(hasPending, (pending) => {
    if (pending) {
        poll?.start();
    } else {
        poll?.stop();
    }
});

onUnmounted(() => {
    poll?.destroy();
});

function filterHref(status: string | null): string {
    return status === null ? index.url() : index.url({ query: { status } });
}

function refreshGeneration(generation: GenerationSummary): void {
    router.post(generation.refresh_url, {}, { preserveScroll: true });
}

function paginationLabel(label: string): string {
    return label
        .replaceAll('&laquo;', '«')
        .replaceAll('&raquo;', '»')
        .replaceAll('&hellip;', '…');
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <Head title="Generation History" />

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Generation History
                </h1>
                <p class="text-sm text-muted-foreground">
                    Track status, costs, and results for every image you have
                    generated.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()">
                    <Plus class="mr-2 size-4" />
                    New Generation
                </Link>
            </Button>
        </div>

        <Alert v-if="providerError" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertTitle>Provider Error</AlertTitle>
            <AlertDescription>{{ providerError }}</AlertDescription>
        </Alert>

        <div class="flex flex-wrap items-center gap-2">
            <Link
                v-for="tab in tabs"
                :key="tab.label"
                :href="filterHref(tab.key)"
                preserve-scroll
                class="rounded-full border px-3 py-1.5 text-sm transition-colors"
                :class="
                    filters.status === tab.key
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-background text-muted-foreground hover:text-foreground'
                "
            >
                {{ tab.label }}
                <span
                    class="ml-1 text-xs"
                    :class="
                        filters.status === tab.key
                            ? 'text-primary-foreground/80'
                            : 'text-muted-foreground'
                    "
                    >{{ tab.count }}</span
                >
            </Link>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border p-12 text-center"
        >
            <ImageOff class="size-8 text-muted-foreground" />
            <div class="flex flex-col gap-1">
                <template v-if="counts.total === 0">
                    <p class="font-medium">No generations yet</p>
                    <p class="text-sm text-muted-foreground">
                        Images you generate will appear here with live status
                        updates.
                    </p>
                </template>
                <template v-else>
                    <p class="font-medium">
                        No {{ activeTabLabel.toLowerCase() }} generations
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Try a different status filter, or create a new image.
                    </p>
                </template>
            </div>
            <Button as-child variant="outline">
                <Link v-if="counts.total === 0" :href="create()"
                    >Create your first image</Link
                >
                <Link v-else :href="index()">View all generations</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="generation in generations.data"
                :key="generation.id"
                class="overflow-hidden pt-0"
            >
                <Link :href="generation.show_url" class="block">
                    <div
                        class="relative flex aspect-square items-center justify-center overflow-hidden bg-muted/40"
                    >
                        <img
                            v-if="
                                generation.image_url &&
                                !generation.image_expired
                            "
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            loading="lazy"
                            class="h-full w-full object-cover"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 text-muted-foreground"
                        >
                            <ImageOff class="size-6" />
                            <span class="text-xs">
                                {{
                                    generation.image_expired
                                        ? 'Preview expired'
                                        : generation.is_pending
                                          ? 'Generating…'
                                          : 'No preview'
                                }}
                            </span>
                        </div>
                        <div class="absolute top-2 left-2">
                            <GenerationStatusBadge
                                :status="generation.status"
                            />
                        </div>
                    </div>
                </Link>

                <CardContent class="flex flex-col gap-2 p-4">
                    <p class="line-clamp-2 min-h-10 text-sm font-medium">
                        {{ generation.prompt }}
                    </p>

                    <div class="flex flex-wrap gap-1.5">
                        <Badge variant="secondary">{{ generation.size }}</Badge>
                        <Badge variant="secondary">{{
                            generation.quality
                        }}</Badge>
                        <Badge
                            v-if="generation.reference_image_count > 0"
                            variant="secondary"
                            >{{ generation.reference_image_count }} ref{{
                                generation.reference_image_count > 1 ? 's' : ''
                            }}</Badge
                        >
                    </div>

                    <p
                        v-if="generation.error_message"
                        class="line-clamp-2 text-xs text-destructive"
                    >
                        {{ generation.error_message }}
                    </p>

                    <div
                        class="mt-auto flex items-center justify-between pt-1 text-xs"
                    >
                        <span class="text-muted-foreground">{{
                            generation.created_at_human
                        }}</span>
                        <span class="font-medium">
                            <template v-if="generation.is_free">Free</template>
                            <template
                                v-else-if="generation.actual_user_cost_display"
                                >{{
                                    generation.actual_user_cost_display
                                }}</template
                            >
                            <template v-else
                                >≤
                                {{ generation.estimated_max_display }}</template
                            >
                        </span>
                    </div>

                    <div v-if="generation.is_pending" class="flex justify-end">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="refreshGeneration(generation)"
                        >
                            <RefreshCw class="mr-1.5 size-3.5" />
                            Check status
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div
            v-if="
                generations.total > generations.data.length ||
                generations.links.length > 3
            "
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <p class="text-xs text-muted-foreground">
                Showing {{ generations.from ?? 0 }}–{{ generations.to ?? 0 }} of
                {{ generations.total }} generations
            </p>
            <div class="flex flex-wrap gap-1">
                <template v-for="link in generations.links" :key="link.label">
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
    </div>
</template>
