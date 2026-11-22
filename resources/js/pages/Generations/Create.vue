<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, Plus, Sparkles, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, estimate, store } from '@/routes/generations';

type Option = {
    value: string;
    label: string;
};

type CostEstimate = {
    tokens: {
        input_text_min: number;
        input_text_max: number;
        input_image: number;
        output: number;
        reasoning_min: number;
        reasoning_max: number;
    };
    user_min_usd: string;
    user_max_usd: string;
    user_min_display: string;
    user_max_display: string;
};

const props = defineProps<{
    sizes: Option[];
    qualities: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generate',
                href: create(),
            },
        ],
    },
});

const form = useForm({
    prompt: '',
    size: props.sizes[0]?.value ?? '1024x1024',
    quality: props.qualities[1]?.value ?? 'medium',
    reference_images: [] as string[],
});

const referenceImages = ref<string[]>([]);

const costEstimate = ref<CostEstimate | null>(null);
const estimateError = ref<string | null>(null);
const estimating = ref(false);
let estimateTimer: ReturnType<typeof setTimeout> | null = null;
let estimateAbortController: AbortController | null = null;

const unicodeCharCount = computed(() => Array.from(form.prompt.trim()).length);
const canAddReferenceImage = computed(() => referenceImages.value.length < 4);
const providerError = computed(
    () => (form.errors as Record<string, string | undefined>).provider,
);

function refImageError(index: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
}

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function refreshEstimate(): Promise<void> {
    estimating.value = true;
    estimateError.value = null;

    const cleanRefs = referenceImages.value
        .map((url) => url.trim())
        .filter((url) => url !== '');

    try {
        const response = await fetch(estimate.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: cleanRefs,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            estimateError.value =
                data.message ?? 'Unable to calculate estimate.';
            costEstimate.value = null;

            return;
        }

        costEstimate.value = data as CostEstimate;
    } catch {
        estimateError.value = 'Network error while fetching estimate.';
    } finally {
        estimating.value = false;
    }
}

function scheduleEstimate(): void {
    if (estimateTimer !== null) {
        clearTimeout(estimateTimer);
    }

    estimateTimer = setTimeout(() => {
        void refreshEstimate();
    }, 250);
}

watch(
    () => [form.prompt, form.size, form.quality, referenceImages.value],
    scheduleEstimate,
    { deep: true },
);

onMounted(() => {
    void refreshEstimate();
});

function addReferenceImage(): void {
    if (referenceImages.value.length < 4) {
        referenceImages.value.push('');
    }
}

function removeReferenceImage(index: number): void {
    referenceImages.value.splice(index, 1);
}

function submit(): void {
    referenceImages.value = referenceImages.value
        .map((url) => url.trim())
        .filter((url) => url !== '');

    form.transform((data) => ({
        ...data,
        reference_images: referenceImages.value,
    })).post(store.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
        <Head title="Generate Image" />

        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                Image Generation Studio
            </h1>
            <p class="text-sm text-muted-foreground">
                Describe the image you want to generate. Review the live cost
                estimate before submitting.
            </p>
        </div>

        <Alert
            v-if="form.recentlySuccessful"
            class="border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
        >
            <CheckCircle2 class="size-4" />
            <AlertTitle>Generation Queued</AlertTitle>
            <AlertDescription>
                Your generation request was accepted by Pixelforge and is queued
                for processing.
            </AlertDescription>
        </Alert>

        <Alert v-if="providerError" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertTitle>Provider Error</AlertTitle>
            <AlertDescription>
                {{ providerError }}
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <form
                class="flex flex-col gap-5 lg:col-span-2"
                @submit.prevent="submit"
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Prompt &amp; Parameters</CardTitle>
                        <CardDescription
                            >Supports up to 4,000 Unicode characters and up to 4
                            reference images.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="flex flex-col gap-5">
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center justify-between">
                                <Label for="prompt">Prompt</Label>
                                <span
                                    class="text-xs"
                                    :class="
                                        unicodeCharCount > 4000
                                            ? 'font-medium text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{ unicodeCharCount }} / 4000</span
                                >
                            </div>
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                rows="4"
                                placeholder="A serene neon-lit ramen shop in Kyoto during light rain..."
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            ></textarea>
                            <InputError :message="form.errors.prompt" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-2">
                                <Label for="size">Size</Label>
                                <select
                                    id="size"
                                    v-model="form.size"
                                    class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <option
                                        v-for="option in sizes"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.size" />
                            </div>

                            <div class="flex flex-col gap-2">
                                <Label for="quality">Quality</Label>
                                <select
                                    id="quality"
                                    v-model="form.quality"
                                    class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <option
                                        v-for="option in qualities"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.quality" />
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div class="flex items-center justify-between">
                                <Label
                                    >Reference Images (Optional, max 4)</Label
                                >
                                <Button
                                    v-if="canAddReferenceImage"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="addReferenceImage"
                                >
                                    <Plus class="mr-1 size-3.5" />
                                    Add URL
                                </Button>
                            </div>

                            <div
                                v-for="(_url, index) in referenceImages"
                                :key="index"
                                class="flex flex-col gap-1"
                            >
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="referenceImages[index]"
                                        type="url"
                                        placeholder="https://example.com/reference.png"
                                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        @click="removeReferenceImage(index)"
                                    >
                                        <Trash2
                                            class="size-4 text-muted-foreground"
                                        />
                                    </Button>
                                </div>
                                <InputError :message="refImageError(index)" />
                            </div>
                            <InputError
                                :message="form.errors.reference_images"
                            />
                        </div>

                        <div class="flex justify-end">
                            <Button
                                type="submit"
                                :disabled="
                                    form.processing ||
                                    unicodeCharCount === 0 ||
                                    unicodeCharCount > 4000
                                "
                            >
                                <Sparkles class="mr-2 size-4" />
                                {{
                                    form.processing
                                        ? 'Submitting...'
                                        : 'Generate Image'
                                }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </form>

            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-base"
                                >Live Cost Estimate</CardTitle
                            >
                            <Badge variant="secondary">+25% markup</Badge>
                        </div>
                        <CardDescription>
                            Calculated from live Pixelforge rates. Failed
                            generations cost $0.00.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div
                            v-if="estimateError"
                            class="text-sm text-destructive"
                        >
                            {{ estimateError }}
                        </div>

                        <div
                            v-else-if="costEstimate"
                            class="flex flex-col gap-3 text-sm"
                        >
                            <div class="rounded-lg bg-muted/60 p-3">
                                <div class="text-xs text-muted-foreground">
                                    Estimated Charge
                                </div>
                                <div class="mt-0.5 text-xl font-semibold">
                                    {{ costEstimate.user_min_display }} –
                                    {{ costEstimate.user_max_display }}
                                </div>
                                <div
                                    class="mt-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    ${{ costEstimate.user_min_usd }} – ${{
                                        costEstimate.user_max_usd
                                    }}
                                </div>
                            </div>

                            <div class="flex flex-col gap-1.5 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Text tokens (±15%)</span
                                    >
                                    <span class="font-mono"
                                        >{{
                                            costEstimate.tokens.input_text_min
                                        }}
                                        –
                                        {{
                                            costEstimate.tokens.input_text_max
                                        }}</span
                                    >
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Reference image tokens</span
                                    >
                                    <span class="font-mono">{{
                                        costEstimate.tokens.input_image
                                    }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Output tokens</span
                                    >
                                    <span class="font-mono">{{
                                        costEstimate.tokens.output
                                    }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-muted-foreground"
                                        >Reasoning tokens</span
                                    >
                                    <span class="font-mono"
                                        >{{ costEstimate.tokens.reasoning_min }}
                                        –
                                        {{
                                            costEstimate.tokens.reasoning_max
                                        }}</span
                                    >
                                </div>
                            </div>
                        </div>

                        <div v-else class="text-sm text-muted-foreground">
                            {{
                                estimating
                                    ? 'Calculating...'
                                    : 'Enter a prompt to preview cost.'
                            }}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
