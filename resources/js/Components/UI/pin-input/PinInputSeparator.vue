<script setup>
import { Minus } from "@lucide/vue";
import { Primitive, useForwardProps } from "reka-ui";

const props = defineProps({
  asChild: { type: Boolean, required: false },
  as: { type: [String, Object, Function], required: false },
});
const forwardedProps = useForwardProps(props);
</script>

<template>
  <Primitive data-slot="pin-input-separator" v-bind="forwardedProps">
    <slot>
      <Minus />
    </slot>
  </Primitive>
</template>
