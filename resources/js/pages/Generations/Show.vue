<script setup lang="ts">
import {
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { AlertCircle, ArrowLeft, ImageOff, RefreshCw } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/generations';

type TokenBreakdown = Record<string, number>;
type RateSnapshot = Record<string, string>;

type GenerationDetail = {
    id: number;
    status: string;
    prompt: string;
    size: string;
    quality: string;
    reference_image_count: number;
    reference_images: string[];
    estimated_min_display: string;
    estimated_max_display: string;
    actual_user_cost_display: string | null;
    actual_provider_cost_display: string | null;
    is_free: boolean;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    error_code: string | null;
    is_pending: boolean;
    provider_id: string | null;
    estimated_tokens: TokenBreakdown | null;
    actual_usage: TokenBreakdown | null;
    rates_snapshot: RateSnapshot | null;
    provider_created_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    created_at_human: string | null;
    show_url: string;
    refresh_url: string;
};

const props = defineProps<{
    generation: GenerationDetail;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Generations', href: index() },
        {
            title: `Generation #${props.generation.id}`,
            href: props.generation.show_url,
        },
    ],
});

const providerError = computed(
    () =>
        (usePage().props.errors as Record<string, string | undefined>).provider,
);

const hasPending = computed(() => props.generation.is_pending);

const { start, stop } = usePoll(
    4000,
    { only: ['generation'] },
    { autoStart: false },
);

watch(
    hasPending,
    (pending) => {
        if (pending) {
            start();
        } else {
            stop();
        }
    },
    { immediate: true },
);

function refreshGeneration(): void {
    router.post(props.generation.refresh_url, {}, { preserveScroll: true });
}

function formatTimestamp(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleString();
}

const tokenRows = computed(() => {
    const estimated = props.generation.estimated_tokens ?? {};
    const actual = props.generation.actual_usage ?? {};

    return [
        {
            label: 'Input text tokens',
            estimated:
                estimated.input_text_min !== undefined
                    ? `${estimated.input_text_min} – ${estimated.input_text_max}`
                    : '—',
            actual: actual.input_text_tokens ?? '—',
        },
        {
            label: 'Input image tokens',
            estimated:
                estimated.input_image !== undefined
                    ? `${estimated.input_image}`
                    : '—',
            actual: actual.input_image_tokens ?? '—',
        },
        {
            label: 'Output tokens',
            estimated:
                estimated.output !== undefined ? `${estimated.output}` : '—',
            actual: actual.output_tokens ?? '—',
        },
        {
            label: 'Reasoning tokens',
            estimated:
                estimated.reasoning_min !== undefined
                    ? `${estimated.reasoning_min} – ${estimated.reasoning_max}`
                    : '—',
            actual: actual.reasoning_tokens ?? '—',
        },
        {
            label: 'Total tokens',
            estimated: '—',
            actual: actual.total_tokens ?? '—',
        },
    ];
});

const rateRows = computed(() => {
    const rates = props.generation.rates_snapshot ?? {};

    return [
        { label: 'Input text', value: rates.input_text_tokens },
        { label: 'Input image', value: rates.input_image_tokens },
        { label: 'Output', value: rates.output_tokens },
        { label: 'Reasoning', value: rates.reasoning_tokens },
    ].filter((row) => row.value !== undefined);
});
</script>

<template>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
        <Head :title="`Generation #${generation.id}`" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Button as-child variant="ghost" size="sm">
                    <Link :href="index()">
                        <ArrowLeft class="mr-1.5 size-4" />
                        Back
                    </Link>
                </Button>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Generation #{{ generation.id }}
                </h1>
                <GenerationStatusBadge :status="generation.status" />
            </div>
            <Button
                v-if="generation.is_pending"
                variant="outline"
                size="sm"
                @click="refreshGeneration"
            >
                <RefreshCw class="mr-1.5 size-4" />
                Refresh status
            </Button>
        </div>

        <Alert
            v-if="providerError || generation.error_message"
            variant="destructive"
        >
            <AlertCircle class="size-4" />
            <AlertTitle>
                {{ providerError ? 'Provider Error' : 'Generation Failed' }}
            </AlertTitle>
            <AlertDescription>
                {{ providerError ?? generation.error_message }}
                <span v-if="generation.error_code" class="block text-xs">
                    Code: {{ generation.error_code }}
                </span>
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-5">
            <Card class="overflow-hidden pt-0 lg:col-span-3">
                <div
                    class="flex aspect-square items-center justify-center overflow-hidden bg-muted/40"
                >
                    <img
                        v-if="generation.image_url && !generation.image_expired"
                        :src="generation.image_url"
                        :alt="generation.prompt"
                        class="h-full w-full object-contain"
                    />
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 text-muted-foreground"
                    >
                        <ImageOff class="size-8" />
                        <span class="text-sm">
                            {{
                                generation.image_expired
                                    ? 'Preview expired — provider image URLs expire after 1 hour'
                                    : generation.is_pending
                                      ? 'Generating…'
                                      : 'No image available'
                            }}
                        </span>
                    </div>
                </div>
                <CardContent class="flex flex-col gap-3 p-4">
                    <div class="flex flex-col gap-1">
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Prompt
                        </p>
                        <p class="text-sm whitespace-pre-wrap">
                            {{ generation.prompt }}
                        </p>
                    </div>
                    <div
                        v-if="generation.reference_images.length > 0"
                        class="flex flex-col gap-1"
                    >
                        <p
                            class="text-xs font-medium text-muted-foreground uppercase"
                        >
                            Reference images
                        </p>
                        <a
                            v-for="reference in generation.reference_images"
                            :key="reference"
                            :href="reference"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="line-clamp-1 text-xs text-primary underline-offset-2 hover:underline"
                            >{{ reference }}</a
                        >
                    </div>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="flex flex-col gap-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Size</dt>
                                <dd>
                                    <Badge variant="secondary">{{
                                        generation.size
                                    }}</Badge>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Quality</dt>
                                <dd>
                                    <Badge variant="secondary">{{
                                        generation.quality
                                    }}</Badge>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">
                                    Provider ID
                                </dt>
                                <dd class="truncate font-mono text-xs">
                                    {{ generation.provider_id ?? '—' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Created</dt>
                                <dd class="text-right">
                                    {{ formatTimestamp(generation.created_at) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Started</dt>
                                <dd class="text-right">
                                    {{ formatTimestamp(generation.started_at) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">Completed</dt>
                                <dd class="text-right">
                                    {{
                                        formatTimestamp(generation.completed_at)
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Cost</CardTitle>
                        <CardDescription
                            >Estimated vs. actual charge (user price includes
                            25% markup)</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <dl class="flex flex-col gap-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">
                                    Estimated range
                                </dt>
                                <dd class="font-medium">
                                    {{ generation.estimated_min_display }} –
                                    {{ generation.estimated_max_display }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-muted-foreground">
                                    Charged to you
                                </dt>
                                <dd class="font-medium">
                                    <template v-if="generation.is_free"
                                        >Free ($0.00)</template
                                    >
                                    <template
                                        v-else-if="
                                            generation.actual_user_cost_display
                                        "
                                        >{{
                                            generation.actual_user_cost_display
                                        }}</template
                                    >
                                    <template v-else>Pending</template>
                                </dd>
                            </div>
                            <div
                                v-if="generation.actual_provider_cost_display"
                                class="flex justify-between gap-4"
                            >
                                <dt class="text-muted-foreground">
                                    Provider cost
                                </dt>
                                <dd>
                                    {{
                                        generation.actual_provider_cost_display
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Token Usage</CardTitle>
                    <CardDescription
                        >Estimated at submission vs. actual usage reported by
                        the provider</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-muted-foreground"
                            >
                                <th class="py-2 pr-2 font-medium">Metric</th>
                                <th class="py-2 pr-2 font-medium">Estimated</th>
                                <th class="py-2 font-medium">Actual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in tokenRows"
                                :key="row.label"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-2">{{ row.label }}</td>
                                <td class="py-2 pr-2 text-muted-foreground">
                                    {{ row.estimated }}
                                </td>
                                <td class="py-2 font-mono text-xs">
                                    {{ row.actual }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Rates Snapshot</CardTitle>
                    <CardDescription
                        >Provider rates captured at submission (USD per 1M
                        tokens)</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <p
                        v-if="rateRows.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No rate snapshot recorded.
                    </p>
                    <dl v-else class="flex flex-col gap-2 text-sm">
                        <div
                            v-for="row in rateRows"
                            :key="row.label"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd class="font-mono text-xs">{{ row.value }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
