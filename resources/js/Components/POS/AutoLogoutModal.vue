<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import RadioButton from 'primevue/radiobutton';

const props = defineProps({
    visible: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:visible']);

const page = usePage();
const machine = computed(() => page.props.auth.machine);

// Timeout options (in seconds)
const timeoutOptions = [
    { label: '30 seconds', value: 30 },
    { label: '1 minute', value: 60 },
    { label: '2 minutes', value: 120 },
    { label: '3 minutes', value: 180 },
    { label: '5 minutes', value: 300 },
    { label: '15 minutes', value: 900 },
    { label: '30 minutes', value: 1800 },
    { label: 'Off (disabled)', value: null }
];

// Get current timeout from machine prop
const getCurrentTimeout = () => {
    return machine.value?.auto_logout_timeout ?? 300; // Default 5 minutes
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

// Get selected option label for display
const selectedOptionLabel = computed(() => {
    const option = timeoutOptions.find(opt => opt.value === form.auto_logout_timeout);
    return option?.label || 'Custom';
});

// Format timeout for display
const formatTimeout = (seconds) => {
    if (seconds === null || seconds === undefined) return 'Off (disabled)';
    if (seconds < 60) return `${seconds} seconds`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes`;
    return `${Math.floor(seconds / 3600)} hours`;
};
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
    >
        <div class="flex flex-col space-y-6">
            <!-- Current Setting Display -->
            <div class="bg-slate-50 p-4 rounded-lg">
                <h4 class="font-semibold text-slate-700 mb-2">Current Setting</h4>
                <p class="text-slate-600">
                    {{ formatTimeout(machine?.auto_logout_timeout) }}
                </p>
                <p class="text-sm text-slate-500 mt-1">
                    Machine: {{ machine?.name }}
                </p>
            </div>

            <!-- Timeout Options -->
            <div>
                <h4 class="font-semibold text-slate-700 mb-4">Select New Timeout</h4>
                <div class="space-y-3">
                    <div 
                        v-for="option in timeoutOptions" 
                        :key="option.value" 
                        class="flex items-center space-x-3 p-2 rounded hover:bg-slate-50 transition-colors"
                    >
                        <RadioButton 
                            v-model="form.auto_logout_timeout"
                            :inputId="`timeout_${option.value}`"
                            :value="option.value"
                        />
                        <label 
                            :for="`timeout_${option.value}`" 
                            class="flex-1 cursor-pointer font-medium"
                            :class="{
                                'text-slate-400': option.value === null,
                                'text-slate-700': option.value !== null
                            }"
                        >
                            {{ option.label }}
                        </label>
                    </div>
                </div>
            </div>

            <!-- Error Display -->
            <div v-if="form.errors.auto_logout_timeout" class="text-red-600 text-sm">
                {{ form.errors.auto_logout_timeout }}
            </div>

            <!-- Selected Option Preview -->
            <div v-if="form.auto_logout_timeout !== getCurrentTimeout()" class="bg-blue-50 p-4 rounded-lg">
                <h5 class="font-semibold text-blue-700 mb-1">New Setting</h5>
                <p class="text-blue-600">{{ selectedOptionLabel }}</p>
                <p class="text-xs text-blue-500 mt-1">
                    Click "Save" to apply this timeout to {{ machine?.name }}
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end space-x-2">
                <Button 
                    label="Cancel" 
                    icon="pi pi-times" 
                    @click="localVisible = false"
                    :disabled="form.processing"
                    severity="secondary"
                    size="small"
                />
                <Button 
                    label="Save" 
                    icon="pi pi-check"
                    @click="saveTimeout"
                    :loading="form.processing"
                    :disabled="form.auto_logout_timeout === getCurrentTimeout()"
                    size="small"
                />
            </div>
        </template>
    </Dialog>
</template>

<style scoped>
:deep(.p-dialog-header) {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

:deep(.p-dialog-content) {
    padding: 1.5rem;
}

:deep(.p-dialog-footer) {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 1rem 1.5rem;
}
</style>