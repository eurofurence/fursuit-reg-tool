<script setup>
import { reactiveOmit } from "@vueuse/core";
import { cn } from "@/lib/utils";
import { PinInputRoot, useForwardPropsEmits } from "reka-ui";

const props = defineProps({
  modelValue: { type: null, required: false },
  defaultValue: { type: null, required: false },
  placeholder: { type: String, required: false },
  mask: { type: Boolean, required: false },
  otp: { type: Boolean, required: false, default: true },
  requireComplete: { type: Boolean, required: false },
  type: { type: null, required: false },
  dir: { type: String, required: false },
  disabled: { type: Boolean, required: false },
  id: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: [String, Object, Function], required: false },
  name: { type: String, required: false },
  required: { type: Boolean, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
const emits = defineEmits(["update:modelValue", "complete"]);

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <PinInputRoot
    :otp="props.otp"
    data-slot="pin-input"
    v-bind="forwarded"
    :class="
      cn(
        'flex items-center gap-2 has-disabled:opacity-50 disabled:cursor-not-allowed',
        props.class,
      )
    "
  >
    <slot />
  </PinInputRoot>
</template>
