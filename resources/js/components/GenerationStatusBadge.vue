<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{
    status: string;
}>();

const config = computed<{ label: string; classes: string }>(() => {
    switch (props.status) {
        case 'succeeded':
            return {
                label: 'Succeeded',
                classes:
                    'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            };
        case 'failed':
            return {
                label: 'Failed',
                classes:
                    'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
            };
        case 'processing':
            return {
                label: 'Processing',
                classes:
                    'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-300',
            };
        default:
            return {
                label: 'Queued',
                classes:
                    'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
            };
    }
});
</script>

<template>
    <Badge variant="outline" :class="config.classes">{{ config.label }}</Badge>
</template>
