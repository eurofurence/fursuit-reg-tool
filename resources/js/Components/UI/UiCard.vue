<script setup>
/*
 * Drop-in for primevue/card.
 *
 * Same five slots, same classes as the vendored Lara preset. Each optional
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

defineProps({
    bodyClass: { type: [String, Array, Object], default: 'p-5' },
    contentClass: { type: [String, Array, Object], default: null },
});

const slots = useSlots();
</script>

<template>
    <div class="rounded-md shadow-md bg-surface-0 dark:bg-surface-900 text-surface-700 dark:text-surface-0">
        <!-- #header sits outside the padded body: it is where call sites put a
             full-bleed image, which must not be inset by the body padding. -->
        <slot name="header" />

        <div :class="bodyClass">
            <div v-if="slots.title" class="text-xl font-bold mb-2">
                <slot name="title" />
            </div>
            <div v-if="slots.subtitle" class="font-normal mb-2 text-surface-600 dark:text-surface-0/60">
                <slot name="subtitle" />
            </div>

            <div :class="contentClass">
                <slot name="content" />
            </div>

            <div v-if="slots.footer" class="pt-5">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>
