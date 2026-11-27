<script setup>
import { ChevronLeftIcon } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { PaginationPrev, useForwardProps } from "reka-ui";
import { buttonVariants } from '@/Components/UI/button';

const props = defineProps({
  asChild: { type: Boolean, required: false },
  as: { type: [String, Object, Function], required: false },
  size: { type: null, required: false, default: "default" },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});

const delegatedProps = reactiveOmit(props, "class", "size");
const forwarded = useForwardProps(delegatedProps);
</script>

<template>
  <PaginationPrev
    data-slot="pagination-previous"
    :class="
      cn(
        buttonVariants({ variant: 'ghost', size }),
        'gap-1 px-2.5 sm:pr-2.5',
        props.class,
      )
    "
    v-bind="forwarded"
  >
    <slot>
      <ChevronLeftIcon />
      <span class="hidden sm:block">Previous</span>
    </slot>
  </PaginationPrev>
</template>
