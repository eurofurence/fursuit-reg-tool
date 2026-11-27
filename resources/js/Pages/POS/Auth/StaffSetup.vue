<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";
import { useForm } from "@inertiajs/vue3";
import { ref } from 'vue';
import { Check, CircleCheck, Eye, EyeOff, Loader2, QrCode, RefreshCw } from '@lucide/vue';
import { Input } from '@/Components/UI/input';
import UiMessage from '@/Components/UI/UiMessage.vue';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    staff_name: String,
});

const showPin = ref(false);
const showPinConfirmation = ref(false);
const isScanning = ref(false);
const rfidTag = ref('');
const rfidBuffer = ref(''); // Buffer to accumulate RFID input
const rfidTimeout = ref(null); // Timer for RFID input completion

const form = useForm('post', route('pos.auth.setup.complete'), {
    pin_code: '',
    pin_code_confirmation: '',
    rfid_tag: '',
});

function startRfidScan() {
    isScanning.value = true;
    rfidTag.value = '';
    rfidBuffer.value = '';
    
    // Clear any existing timeout
    if (rfidTimeout.value) {
        clearTimeout(rfidTimeout.value);
        rfidTimeout.value = null;
    }
    
    // Focus on hidden input for RFID scanner
    const rfidInput = document.getElementById('rfid-input');
    if (rfidInput) {
        rfidInput.value = ''; // Clear any existing value
        rfidInput.focus();
    }
}

function handleRfidInput(event) {
    if (!isScanning.value) return;
    
    const currentValue = event.target.value;
    const cleanValue = currentValue.trim().replace(/[^0-9]/g, '');
    
    // Update buffer with new value
    if (cleanValue && cleanValue.length > rfidBuffer.value.length) {
        rfidBuffer.value = cleanValue;
    }
    
    // Clear existing timeout
    if (rfidTimeout.value) {
        clearTimeout(rfidTimeout.value);
    }
    
    // Set a longer timeout to ensure complete input from scanner
    // RFID scanners typically send data in bursts, so we need more time
    rfidTimeout.value = setTimeout(() => {
        const finalValue = rfidBuffer.value;
        
        if (finalValue && finalValue.length >= 8 && finalValue.length <= 20) {
            rfidTag.value = finalValue;
            form.rfid_tag = finalValue;
            isScanning.value = false;
            event.target.value = '';
            rfidBuffer.value = '';
        } else if (finalValue && finalValue.length > 0) {
            console.warn('RFID incomplete - got', finalValue.length, 'digits:', finalValue);
            // Keep scanning if we got partial data
        }
    }, 500); // Increased to 500ms for complete scanner input
}

function handleRfidKeydown(event) {
    if (!isScanning.value) return;
    
    // If Enter key is pressed (common with RFID scanners)
    if (event.key === 'Enter') {
        event.preventDefault();
        
        // Clear any pending timeout first
        if (rfidTimeout.value) {
            clearTimeout(rfidTimeout.value);
            rfidTimeout.value = null;
        }
        
        // Process the current value immediately when Enter is pressed
        const currentValue = event.target.value.trim().replace(/[^0-9]/g, '');
        
        // Use the buffer value as it may be more complete than current input value
        const finalValue = currentValue.length > rfidBuffer.value.length ? currentValue : rfidBuffer.value;
        
        if (finalValue && finalValue.length >= 8 && finalValue.length <= 20) {
            rfidTag.value = finalValue;
            form.rfid_tag = finalValue;
            isScanning.value = false;
            event.target.value = '';
            rfidBuffer.value = '';
        } else if (finalValue && finalValue.length > 0 && finalValue.length < 8) {
            // If still too short, show helpful message but keep scanning
            console.warn('RFID tag too short:', finalValue, 'length:', finalValue.length);
        }
    }
}

function resetRfidScan() {
    isScanning.value = false;
    rfidTag.value = '';
    rfidBuffer.value = '';
    form.rfid_tag = '';
    
    // Clear timeout if exists
    if (rfidTimeout.value) {
        clearTimeout(rfidTimeout.value);
        rfidTimeout.value = null;
    }
    
    // Clear the input field
    const rfidInput = document.getElementById('rfid-input');
    if (rfidInput) {
        rfidInput.value = '';
    }
}

function submitSetup() {
    form.submit();
}
</script>

<template>
    <div class="w-full min-h-full bg-pos-panel-2 flex items-center justify-center p-6">
        <div class="w-full max-w-lg">
            <div class="text-center mb-6">
                <h2 class="text-3xl font-extrabold text-pos-text">
                    Staff Account Setup
                </h2>
                <p class="mt-2 text-sm text-pos-muted">
                    Welcome {{ staff_name }}! Complete your account setup.
                </p>
            </div>

            <div class="pos-card">
                    <form @submit.prevent="submitSetup" class="space-y-6">
                        <!-- PIN Code Setup -->
                        <div>
                            <label for="pin_code" class="block text-sm font-medium text-pos-text mb-2">
                                Set Your PIN Code (6 digits)
                            </label>
                            <div class="relative">
                                <Input
                                    id="pin_code"
                                    v-model="form.pin_code"
                                    :type="showPin ? 'text' : 'password'"
                                    :aria-invalid="form.invalid('pin_code') || undefined"
                                    class="h-12 pr-14 text-base"
                                    placeholder="Enter 6-digit PIN"
                                    maxlength="6"
                                />
                                <button
                                    type="button"
                                    class="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-pos-muted hover:text-pos-text"
                                    :aria-label="showPin ? 'Hide PIN' : 'Show PIN'"
                                    @click="showPin = !showPin"
                                >
                                    <EyeOff v-if="showPin" class="size-5" aria-hidden="true" />
                                    <Eye v-else class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                            <div v-if="form.errors.pin_code" class="mt-1">
                                <UiMessage severity="error" :closable="false">
                                    {{ form.errors.pin_code }}
                                </UiMessage>
                            </div>
                            <p class="mt-1 text-xs text-pos-muted">
                                Choose a secure PIN. Avoid simple patterns like 123456 or 111111.
                            </p>
                        </div>

                        <!-- PIN Confirmation -->
                        <div>
                            <label for="pin_code_confirmation" class="block text-sm font-medium text-pos-text mb-2">
                                Confirm PIN Code
                            </label>
                            <div class="relative">
                                <Input
                                    id="pin_code_confirmation"
                                    v-model="form.pin_code_confirmation"
                                    :type="showPinConfirmation ? 'text' : 'password'"
                                    :aria-invalid="form.invalid('pin_code_confirmation') || undefined"
                                    class="h-12 pr-14 text-base"
                                    placeholder="Confirm 6-digit PIN"
                                    maxlength="6"
                                />
                                <button
                                    type="button"
                                    class="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-pos-muted hover:text-pos-text"
                                    :aria-label="showPinConfirmation ? 'Hide PIN' : 'Show PIN'"
                                    @click="showPinConfirmation = !showPinConfirmation"
                                >
                                    <EyeOff v-if="showPinConfirmation" class="size-5" aria-hidden="true" />
                                    <Eye v-else class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                            <div v-if="form.errors.pin_code_confirmation" class="mt-1">
                                <UiMessage severity="error" :closable="false">
                                    {{ form.errors.pin_code_confirmation }}
                                </UiMessage>
                            </div>
                        </div>

                        <!-- RFID Setup -->
                        <div>
                            <label class="block text-sm font-medium text-pos-text mb-2">
                                Register RFID Tag
                            </label>
                            
                            <div v-if="!rfidTag && !isScanning" class="text-center">
                                <button type="button" @click="startRfidScan" class="pos-btn">
                                    <QrCode class="size-4" aria-hidden="true" />
                                    Scan RFID Tag
                                </button>
                                <p class="mt-2 text-xs text-pos-muted">
                                    Click and then scan your RFID tag/badge (8-20 digits)
                                </p>
                            </div>

                            <div v-if="isScanning" class="text-center p-4 border-2 border-dashed border-pos-accent/30 rounded-pos bg-pos-accent/10">
                                <div class="animate-pulse">
                                    <QrCode class="size-8 mx-auto text-pos-accent mb-2" aria-hidden="true" />
                                    <p class="text-pos-accent font-medium">Scanning for RFID tag...</p>
                                    <p class="text-xs text-pos-accent mt-1">Hold your tag near the scanner</p>
                                </div>
                                <button type="button" @click="resetRfidScan" class="pos-btn pos-btn--sm mt-3">
                                    Cancel
                                </button>
                            </div>

                            <div v-if="rfidTag" class="p-3 bg-pos-good/10 border border-pos-good/30 rounded-pos">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <CircleCheck class="size-4 text-pos-good mr-2" aria-hidden="true" />
                                        <span class="text-pos-good font-medium">Tag Registered</span>
                                    </div>
                                    <button type="button" @click="resetRfidScan" class="pos-btn pos-btn--sm">
                                        <RefreshCw class="size-4" aria-hidden="true" />
                                        Scan Different Tag
                                    </button>
                                </div>
                                <p class="text-xs text-pos-good mt-1 font-mono">{{ rfidTag }}</p>
                            </div>

                            <!-- Hidden input for RFID scanner -->
                            <input
                                id="rfid-input"
                                @input="handleRfidInput"
                                @keydown="handleRfidKeydown"
                                type="text"
                                class="absolute -left-9999px opacity-0"
                                autocomplete="off"
                            />

                            <div v-if="form.errors.rfid_tag" class="mt-1">
                                <UiMessage severity="error" :closable="false">
                                    {{ form.errors.rfid_tag }}
                                </UiMessage>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button
                            type="submit"
                            :disabled="form.processing || !form.pin_code || !form.pin_code_confirmation || !form.rfid_tag"
                            class="pos-btn pos-btn--primary w-full"
                        >
                            <Loader2 v-if="form.processing" class="size-4 animate-spin" aria-hidden="true" />
                            <Check v-else class="size-4" aria-hidden="true" />
                            Complete Setup
                        </button>
                    </form>
            </div>
        </div>
    </div>
</template>