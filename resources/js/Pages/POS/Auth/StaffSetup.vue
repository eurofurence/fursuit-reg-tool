<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";
import { useForm } from "laravel-precognition-vue-inertia";
import { ref } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Card from 'primevue/card';
import Message from 'primevue/message';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    staff_name: String,
});

const isScanning = ref(false);
const rfidTag = ref('');
const rfidBuffer = ref(''); // Buffer to accumulate RFID input
const rfidTimeout = ref(null); // Timer for RFID input completion

const form = useForm('POST', route('pos.auth.setup.complete'), {
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
    <div class="w-full min-h-full bg-gray-50 flex items-center justify-center p-6">
        <div class="w-full max-w-lg">
            <div class="text-center mb-6">
                <h2 class="text-3xl font-extrabold text-gray-900">
                    Staff Account Setup
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    Welcome {{ staff_name }}! Complete your account setup.
                </p>
            </div>

            <Card class="p-6">
                <template #content>
                    <form @submit.prevent="submitSetup" class="space-y-6">
                        <!-- PIN Code Setup -->
                        <div>
                            <label for="pin_code" class="block text-sm font-medium text-gray-700 mb-2">
                                Set Your PIN Code (6 digits)
                            </label>
                            <Password
                                id="pin_code"
                                v-model="form.pin_code"
                                :feedback="false"
                                :toggleMask="true"
                                :invalid="form.invalid('pin_code')"
                                inputClass="w-full"
                                class="w-full"
                                placeholder="Enter 6-digit PIN"
                                maxlength="6"
                            />
                            <div v-if="form.errors.pin_code" class="mt-1">
                                <Message severity="error" :closable="false">
                                    {{ form.errors.pin_code }}
                                </Message>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Choose a secure PIN. Avoid simple patterns like 123456 or 111111.
                            </p>
                        </div>

                        <!-- PIN Confirmation -->
                        <div>
                            <label for="pin_code_confirmation" class="block text-sm font-medium text-gray-700 mb-2">
                                Confirm PIN Code
                            </label>
                            <Password
                                id="pin_code_confirmation"
                                v-model="form.pin_code_confirmation"
                                :feedback="false"
                                :toggleMask="true"
                                :invalid="form.invalid('pin_code_confirmation')"
                                inputClass="w-full"
                                class="w-full"
                                placeholder="Confirm 6-digit PIN"
                                maxlength="6"
                            />
                            <div v-if="form.errors.pin_code_confirmation" class="mt-1">
                                <Message severity="error" :closable="false">
                                    {{ form.errors.pin_code_confirmation }}
                                </Message>
                            </div>
                        </div>

                        <!-- RFID Setup -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Register RFID Tag
                            </label>
                            
                            <div v-if="!rfidTag && !isScanning" class="text-center">
                                <Button
                                    @click="startRfidScan"
                                    icon="pi pi-qrcode"
                                    label="Scan RFID Tag"
                                    class="p-button-outlined"
                                />
                                <p class="mt-2 text-xs text-gray-500">
                                    Click and then scan your RFID tag/badge (8-20 digits)
                                </p>
                            </div>

                            <div v-if="isScanning" class="text-center p-4 border-2 border-dashed border-blue-300 rounded-lg bg-blue-50">
                                <div class="animate-pulse">
                                    <i class="pi pi-qrcode text-3xl text-blue-500 mb-2"></i>
                                    <p class="text-blue-700 font-medium">Scanning for RFID tag...</p>
                                    <p class="text-xs text-blue-600 mt-1">Hold your tag near the scanner</p>
                                </div>
                                <Button
                                    @click="resetRfidScan"
                                    label="Cancel"
                                    severity="secondary"
                                    size="small"
                                    class="mt-3"
                                />
                            </div>

                            <div v-if="rfidTag" class="p-3 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <i class="pi pi-check-circle text-green-500 mr-2"></i>
                                        <span class="text-green-700 font-medium">Tag Registered</span>
                                    </div>
                                    <Button
                                        @click="resetRfidScan"
                                        icon="pi pi-refresh"
                                        severity="secondary"
                                        size="small"
                                        label="Scan Different Tag"
                                    />
                                </div>
                                <p class="text-xs text-green-600 mt-1 font-mono">{{ rfidTag }}</p>
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
                                <Message severity="error" :closable="false">
                                    {{ form.errors.rfid_tag }}
                                </Message>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <Button
                            type="submit"
                            :loading="form.processing"
                            :disabled="!form.pin_code || !form.pin_code_confirmation || !form.rfid_tag"
                            label="Complete Setup"
                            icon="pi pi-check"
                            class="w-full"
                        />
                    </form>
                </template>
            </Card>
        </div>
    </div>
</template>