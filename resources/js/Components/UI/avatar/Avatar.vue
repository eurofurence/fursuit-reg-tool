<script setup>
import { cn } from "@/lib/utils";
import { AvatarRoot } from "reka-ui";

const props = defineProps({
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
</script>

<template>
  <AvatarRoot
    data-slot="avatar"
    :class="
      cn(
        'relative flex size-8 shrink-0 overflow-hidden rounded-full',
        props.class,
      )
    "
  >
    <slot />
  </AvatarRoot>
</template>
