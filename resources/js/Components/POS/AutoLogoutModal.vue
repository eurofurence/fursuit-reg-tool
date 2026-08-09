<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import RadioButton from 'primevue/radiobutton';
import { posDialogPt } from '@/Components/POS/posDialog.js';

const props = defineProps({
    visible: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:visible']);

const page = usePage();
const machine = computed(() => page.props.auth.machine);

// Timeout options (in seconds). null means no auto logout.
const timeoutOptions = [
    { label: '30 seconds', value: 30 },
    { label: '1 minute', value: 60 },
    { label: '2 minutes', value: 120 },
    { label: '3 minutes', value: 180 },
    { label: '5 minutes', value: 300 },
    { label: '15 minutes', value: 900 },
    { label: '30 minutes', value: 1800 },
    { label: 'Off', value: null, note: 'stays logged in until someone switches user' },
];

// null is the "Off" setting, so only an absent value falls back to 5 minutes.
const getCurrentTimeout = () => {
    const timeout = machine.value?.auto_logout_timeout;

    return timeout === undefined ? 300 : timeout;
};

// Initialize form with current timeout
const form = useForm({
    auto_logout_timeout: getCurrentTimeout()
});

// Watch for changes to machine prop and update form
watch(() => machine.value?.auto_logout_timeout, (newTimeout) => {
    if (newTimeout !== undefined) {
        form.auto_logout_timeout = newTimeout;
    }
}, { immediate: true });

// Handle dialog visibility
const localVisible = ref(false);

watch(() => props.visible, (newVisible) => {
    localVisible.value = newVisible;
    if (newVisible) {
        // Reset form to current machine timeout when opening
        form.auto_logout_timeout = getCurrentTimeout();
        form.clearErrors();
    }
});

watch(localVisible, (newVisible) => {
    emit('update:visible', newVisible);
});

// Submit form
const saveTimeout = () => {
    const machineId = machine.value?.id;
    if (!machineId) {
        console.error('[AutoLogoutModal] No machine ID available');
        return;
    }

    form.put(route('pos.machine.timeout', { machine: machineId }), {
        preserveScroll: true,
        onSuccess: () => {
            console.log('[AutoLogoutModal] Timeout updated successfully');
            localVisible.value = false;
        },
        onError: (errors) => {
            console.error('[AutoLogoutModal] Failed to update timeout:', errors);
        }
    });
};

// What is saved right now — marks the "Current" row and gates the Save button.
const currentTimeout = computed(() => {
    const timeout = machine.value?.auto_logout_timeout;

    return timeout === undefined ? 300 : timeout;
});

const hasChanges = computed(() => form.auto_logout_timeout !== currentTimeout.value);
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :closable="true"
        class="mx-4"
        :style="{ width: '28rem' }"
        header="Auto Logout Settings"
        :draggable="false"
        :pt="posDialogPt"
    >
        <div class="flex flex-col gap-3">
            <p class="text-sm text-pos-muted">
                Log this terminal out automatically after a period with no activity.
            </p>

            <!--
                The list is the whole control: the selected radio is the pending
                choice and the "current" pill marks what is saved. A separate
                "current setting" panel above it only restated the same thing.
            -->
            <div class="pos-block pos-block--rows">
                <div
                    v-for="option in timeoutOptions"
                    :key="String(option.value)"
                    class="flex items-center gap-3 px-3 min-h-pos-touch"
                >
                    <RadioButton
                        v-model="form.auto_logout_timeout"
                        :inputId="`timeout_${option.value}`"
                        :value="option.value"
                    />
                    <label
                        :for="`timeout_${option.value}`"
                        class="flex-1 cursor-pointer font-medium text-pos-text py-2"
                    >
                        {{ option.label }}
                        <span v-if="option.note" class="block text-xs font-normal text-pos-muted">
                            {{ option.note }}
                        </span>
                    </label>
                    <span v-if="option.value === currentTimeout" class="pos-pill">Current</span>
                </div>
            </div>

            <!-- Error Display -->
            <div v-if="form.errors.auto_logout_timeout" class="px-3 py-2 rounded-pos border border-pos-bad text-pos-bad text-sm font-semibold">
                {{ form.errors.auto_logout_timeout }}
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                class="pos-btn"
                :disabled="form.processing"
                @click="localVisible = false"
            >
                Cancel
            </button>
            <button
                type="button"
                class="pos-btn pos-btn--primary"
                :disabled="form.processing || ! hasChanges"
                @click="saveTimeout"
            >
                <i class="pi" :class="form.processing ? 'pi-spin pi-spinner' : 'pi-check'"></i>
                Save
            </button>
        </template>
    </Dialog>
</template>