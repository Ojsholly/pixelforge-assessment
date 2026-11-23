<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Aperture,
    ArrowRight,
    Calculator,
    ShieldCheck,
    Wallet,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const page = usePage();

const features = [
    {
        icon: Calculator,
        title: 'Estimate before you spend',
        description:
            'Every prompt returns a live token-cost preview from real provider rates — you see the exact ceiling before a cent is reserved.',
    },
    {
        icon: ShieldCheck,
        title: 'Signed, idempotent webhooks',
        description:
            'HMAC-verified events processed out-of-order safe. Duplicates, retries, and delayed deliveries settle exactly once.',
    },
    {
        icon: Wallet,
        title: 'Billing that reconciles',
        description:
            'Wallet holds capture the actual cost and release the rest — to the nanodollar, reconciled against provider usage daily.',
    },
];

const steps = [
    {
        title: 'Describe the image',
        description:
            'Pick a size and quality, attach reference images if you want a style.',
    },
    {
        title: 'Preview the cost',
        description:
            'A live estimate shows what the generation can cost before you commit.',
    },
    {
        title: 'Watch it settle',
        description:
            'The image arrives asynchronously; your wallet settles the exact usage.',
    },
];
</script>

<template>
    <Head title="PixelForge Studio" />
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6"
        >
            <div class="flex items-center gap-2">
                <div
                    class="flex aspect-square size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <Aperture class="size-5" />
                </div>
                <span class="text-sm font-semibold tracking-tight"
                    >PixelForge Studio</span
                >
            </div>
            <nav class="flex items-center gap-2">
                <Button
                    v-if="page.props.auth.user"
                    as-child
                    variant="default"
                    size="sm"
                >
                    <Link :href="dashboard()">Dashboard</Link>
                </Button>
                <template v-else>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="login()">Sign in</Link>
                    </Button>
                    <Button as-child variant="outline" size="sm">
                        <Link :href="register()">Create account</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main class="flex flex-1 flex-col">
            <section
                class="relative isolate overflow-hidden border-y border-border/60"
            >
                <div
                    aria-hidden="true"
                    class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,var(--muted)_0%,transparent_60%)] opacity-60"
                />
                <div
                    aria-hidden="true"
                    class="absolute inset-0 -z-10 [background-image:linear-gradient(var(--border)_1px,transparent_1px),linear-gradient(90deg,var(--border)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_75%)] [background-size:72px_72px] opacity-30"
                />
                <div
                    class="mx-auto flex max-w-3xl flex-col items-center px-6 py-24 text-center sm:py-32"
                >
                    <Badge variant="secondary" class="mb-6"
                        >Asynchronous image generation</Badge
                    >
                    <h1
                        class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        Prompt to pixels, billed to the
                        <span
                            class="bg-gradient-to-r from-chart-1 via-chart-4 to-chart-2 bg-clip-text text-transparent"
                            >nanodollar</span
                        >
                    </h1>
                    <p
                        class="mt-6 max-w-xl text-lg leading-relaxed text-pretty text-muted-foreground"
                    >
                        Submit a prompt, see the exact cost ceiling before you
                        spend, and let signed webhooks settle the rest. Your
                        wallet can never overdraft.
                    </p>
                    <div
                        class="mt-10 flex flex-wrap items-center justify-center gap-3"
                    >
                        <Button v-if="page.props.auth.user" as-child size="lg">
                            <Link :href="dashboard()">
                                Open dashboard
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <template v-else>
                            <Button as-child size="lg">
                                <Link :href="register()">
                                    Start generating
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>
                            <Button as-child variant="outline" size="lg">
                                <Link :href="login()">Sign in</Link>
                            </Button>
                        </template>
                    </div>
                </div>
            </section>

            <section class="mx-auto w-full max-w-5xl px-6 py-16 sm:py-20">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-xl border border-border/60 bg-card p-6 shadow-sm transition-colors hover:border-border"
                    >
                        <div
                            class="mb-4 flex size-10 items-center justify-center rounded-lg bg-secondary"
                        >
                            <component
                                :is="feature.icon"
                                class="size-5 text-foreground"
                            />
                        </div>
                        <h3 class="text-sm font-semibold">
                            {{ feature.title }}
                        </h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ feature.description }}
                        </p>
                    </div>
                </div>
            </section>

            <section
                class="mx-auto w-full max-w-5xl border-t border-border/60 px-6 py-16 sm:py-20"
            >
                <h2
                    class="text-center text-2xl font-semibold tracking-tight text-balance"
                >
                    How it works
                </h2>
                <div class="mt-10 grid gap-8 sm:grid-cols-3">
                    <div
                        v-for="(step, index) in steps"
                        :key="step.title"
                        class="flex flex-col items-center text-center"
                    >
                        <div
                            class="flex size-9 items-center justify-center rounded-full border border-border bg-muted text-sm font-semibold"
                        >
                            {{ index + 1 }}
                        </div>
                        <h3 class="mt-4 text-sm font-semibold">
                            {{ step.title }}
                        </h3>
                        <p
                            class="mt-2 max-w-[26ch] text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ step.description }}
                        </p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-border/60">
            <div
                class="mx-auto flex w-full max-w-5xl flex-col items-center justify-between gap-3 px-6 py-8 text-sm text-muted-foreground sm:flex-row"
            >
                <div class="flex items-center gap-2">
                    <Aperture class="size-4" />
                    <span>PixelForge Studio</span>
                </div>
                <p>Exact billing, every pixel accounted for.</p>
            </div>
        </footer>
    </div>
</template>
