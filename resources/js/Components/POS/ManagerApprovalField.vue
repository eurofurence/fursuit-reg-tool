<script setup>
/*
 * The second signature on a money change.
 *
 * One field takes both credentials: a manager types their six digit PIN, or
 * holds their staff badge against the reader, which types the tag content and
 * an Enter. The server decides which of the two it received, so the desk never
 * has to pick a mode - important when the manager reaching over is not the
 * person the till is logged in as.
 *
 * Masked, because it is the same PIN that logs into the till.
 */
import { nextTick, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    error: {
        type: String,
        default: '',
    },
    // A manager working their own till approves by being signed in; the field
    // stays out of the way entirely.
    show: {
        type: Boolean,
        default: true,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'submit']);

const input = ref(null);

function focus() {
    nextTick(() => input.value?.focus());
}

watch(() => props.show, (visible) => {
    if (visible) {
        focus();
    }
}, { immediate: true });

defineExpose({ focus });
</script>

<template>
    <div v-if="show" class="pos-card">
        <label class="pos-label block mb-1" for="manager-approval">Manager approval</label>
        <input
            id="manager-approval"
            ref="input"
            type="password"
            inputmode="numeric"
            autocomplete="off"
            class="pos-field pos-field--sm"
            :class="error ? 'border-pos-bad' : ''"
            :disabled="disabled"
            :value="modelValue"
            placeholder="Manager PIN, or scan a manager badge"
            @input="emit('update:modelValue', $event.target.value)"
            @keyup.enter.prevent="emit('submit')"
        />
        <p v-if="error" class="text-pos-bad text-sm mt-1">{{ error }}</p>
        <p v-else class="text-pos-muted text-xs mt-1">
            A manager must approve a price change. Enter their PIN or scan their RFID badge.
        </p>
    </div>
</template>
