<script setup>
/*
 * Drop-in for primevue/card, rendered by the kit's Card.
 *
 * Same five slots as PrimeVue's. Each optional
 * section is omitted entirely when its slot is unused, so a card with only
 * #content carries no empty padded divs.
 *
 * `body-class` and `content-class` replace the defaults for those two sections,
 * which is what PrimeVue's `:pt` did here (mergeProps is off, so a `:pt` class
 * overwrote the preset's rather than adding to it). The POS checkout panels
 * depend on this: without their `flex-1 flex flex-col h-full` the Cash Register
 * and Transaction cards collapse to content height instead of filling the row.
 */
import { useSlots } from 'vue';
import { Card, CardDescription, CardTitle } from '@/Components/UI/card';

defineProps({
    bodyClass: { type: [String, Array, Object], default: 'p-5' },
    contentClass: { type: [String, Array, Object], default: null },
});

const slots = useSlots();
</script>

<template>
    <Card class="gap-0 py-0 overflow-hidden">
        <!-- #header sits outside the padded body: it is where call sites put a
             full-bleed image, which must not be inset by the body padding. -->
        <slot name="header" />

        <div :class="bodyClass">
            <CardTitle v-if="slots.title" class="text-xl font-bold leading-tight mb-2">
                <slot name="title" />
            </CardTitle>
            <CardDescription v-if="slots.subtitle" class="text-base mb-2">
                <slot name="subtitle" />
            </CardDescription>

            <div :class="contentClass">
                <slot name="content" />
            </div>

            <div v-if="slots.footer" class="pt-5">
                <slot name="footer" />
            </div>
        </div>
    </Card>
</template>
