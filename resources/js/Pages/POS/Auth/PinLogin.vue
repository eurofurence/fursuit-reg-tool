<script setup>
import Card from 'primevue/card';
import Button from 'primevue/button';
import InputOtp from 'primevue/inputotp';
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import Message from 'primevue/message';
import {useForm} from "laravel-precognition-vue-inertia";
import {ref, watch, onMounted, onUnmounted} from "vue";
import sjcl from 'sjcl';
import POSLayout from "@/Layouts/POSLayout.vue";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    salt: {
        type: String,
        required: true
    }
});

const form = useForm('POST', route('pos.auth.user.pin.submit'), {
    code: '',
    is_rfid: false
});

// Authentication modes
const authMode = ref('rfid'); // 'rfid' or 'pin'
const rfidCode = ref('');
const pinCode = ref('');
const isListening = ref(true);

// Keyboard configuration for PIN entry
const keyboardOptions = {
    layout: {
        default: ["7 8 9", "4 5 6", "1 2 3", "0 {backspace} {enter}"]
    },
    display: {
        "{backspace}": "backspace ⌫",
        "{enter}": "enter ↵",
    },
    autoUseTouchEvents: false,
    theme: "hg-theme-default hg-layout-numeric numeric-theme"
};

// RFID Scanner Detection
let keyBuffer = '';
let keyTimer = null;
const RFID_MIN_LENGTH = 8; // Minimum length for RFID codes
const KEY_TIMEOUT = 100; // Milliseconds between keys to detect scanner

const handleKeyPress = (event) => {
    if (!isListening.value || authMode.value !== 'rfid') return;
    
    // Clear previous timer
    if (keyTimer) {
        clearTimeout(keyTimer);
    }
    
    // Add character to buffer
    if (event.key.length === 1) {
        keyBuffer += event.key;
    } else if (event.key === 'Enter') {
        // Process the buffer as RFID code
        if (keyBuffer.length >= RFID_MIN_LENGTH) {
            rfidCode.value = keyBuffer;
            submitRfidLogin();
        }
        keyBuffer = '';
        return;
    } else if (event.key === 'Backspace') {
        keyBuffer = keyBuffer.slice(0, -1);
    }
    
    // Set timer to clear buffer if no more keys come quickly
    keyTimer = setTimeout(() => {
        keyBuffer = '';
    }, KEY_TIMEOUT);
};

const submitRfidLogin = () => {
    form.code = rfidCode.value;
    form.is_rfid = true;
    form.submit();
    rfidCode.value = '';
};

const submitPinLogin = () => {
    if (pinCode.value.length < 6) return;
    
    const myBitArray = sjcl.hash.sha256.hash(pinCode.value + props.salt);
    form.code = sjcl.codec.hex.fromBits(myBitArray);
    form.is_rfid = false;
    form.submit();
    pinCode.value = '';
};

// Handle virtual keyboard input
const handleVirtualKeyPress = (event) => {
    if (event === "{backspace}") {
        pinCode.value = pinCode.value.slice(0, -1);
    } else if (event === "{enter}") {
        submitPinLogin();
    } else {
        if (pinCode.value.length < 6) {
            pinCode.value += event;
        }
    }
};

// Auto-submit PIN when 6 digits entered
watch(pinCode, (value) => {
    if (value.length === 6) {
        submitPinLogin();
    }
}, {flush: 'post'});

// Switch between modes
const switchToPin = () => {
    authMode.value = 'pin';
    isListening.value = false;
    pinCode.value = '';
};

const switchToRfid = () => {
    authMode.value = 'rfid';
    isListening.value = true;
    rfidCode.value = '';
    keyBuffer = '';
};

// Lifecycle
onMounted(() => {
    document.addEventListener('keydown', handleKeyPress);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeyPress);
    if (keyTimer) {
        clearTimeout(keyTimer);
    }
});
</script>

<template>
    <div class="max-w-4xl mx-auto">
        <!-- RFID Scanner Mode (Default) -->
        <div v-if="authMode === 'rfid'">
            <div class="text-center mb-8">
                <div class="mb-6">
                    <i class="pi pi-qrcode text-8xl text-blue-500 mb-4 animate-pulse"></i>
                </div>
                <h1 class="text-4xl font-bold text-slate-800 mb-3">Scan Your Badge</h1>
                <p class="text-xl text-slate-600 max-w-2xl mx-auto leading-relaxed">
                    Hold your RFID badge near the scanner or position it for scanning.
                </p>
            </div>

            <!-- RFID Scanner Card -->
            <Card class="shadow-xl border-0 mb-6">
                <template #content>
                    <div class="p-8 text-center">
                        <!-- Scanner Status -->
                        <div class="mb-6">
                            <div class="inline-flex items-center justify-center w-24 h-24 bg-blue-100 rounded-full mb-4">
                                <i class="pi pi-wifi text-4xl text-blue-600"></i>
                            </div>
                            <h2 class="text-2xl font-semibold text-slate-700 mb-2">Scanner Ready</h2>
                            <p class="text-slate-600">
                                <span class="inline-flex items-center">
                                    <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                                    Listening for badge scan...
                                </span>
                            </p>
                        </div>

                        <!-- Error Message -->
                        <div v-if="form.invalid('code')" class="mb-6">
                            <Message severity="error" class="text-left">
                                <i class="pi pi-exclamation-triangle mr-2"></i>
                                {{ form.errors.code }}
                            </Message>
                        </div>

                        <!-- RFID Input Display (hidden but shows when code detected) -->
                        <div v-if="rfidCode" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                            <div class="flex items-center justify-center text-green-800">
                                <i class="pi pi-check-circle mr-2"></i>
                                <span class="font-medium">Badge Detected: {{ rfidCode.substring(0, 4) }}****</span>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Alternative PIN Login -->
            <Card class="shadow-lg border-0">
                <template #content>
                    <div class="p-6 text-center">
                        <h3 class="text-xl font-semibold text-slate-700 mb-3">Alternative Login</h3>
                        <p class="text-slate-600 mb-4">Don't have your badge? You can use your PIN instead.</p>
                        <Button 
                            @click="switchToPin"
                            icon="pi pi-lock"
                            label="Enter PIN Code Instead"
                            class="p-button-lg p-button-outlined"
                        />
                    </div>
                </template>
            </Card>
        </div>

        <!-- PIN Entry Mode -->
        <div v-else-if="authMode === 'pin'">
            <!-- Back Button -->
            <div class="mb-6">
                <Button 
                    icon="pi pi-arrow-left" 
                    @click="switchToRfid" 
                    severity="secondary" 
                    class="p-button-lg" 
                    label="Back to Badge Scanner"
                />
            </div>

            <!-- PIN Entry Card -->
            <Card class="shadow-xl border-0">
                <template #content>
                    <div class="p-8 text-center">
                        <!-- PIN Icon -->
                        <div class="mb-6">
                            <div class="inline-flex items-center justify-center w-20 h-20 bg-amber-100 rounded-full mb-4">
                                <i class="pi pi-lock text-3xl text-amber-600"></i>
                            </div>
                            <h1 class="text-3xl font-bold text-slate-800 mb-2">Enter Your PIN</h1>
                            <p class="text-lg text-slate-600">Please enter your 6-digit PIN code</p>
                        </div>

                        <!-- Error Message -->
                        <div v-if="form.invalid('code')" class="mb-6">
                            <Message severity="error" class="text-left">
                                <i class="pi pi-exclamation-triangle mr-2"></i>
                                {{ form.errors.code }}
                            </Message>
                        </div>

                        <!-- PIN Input -->
                        <div class="mb-8">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-slate-700 mb-3">Enter 6-digit PIN</label>
                                <div class="flex justify-center">
                                    <InputOtp 
                                        :invalid="form.invalid('code')" 
                                        :autofocus="true" 
                                        :length="6" 
                                        integerOnly 
                                        mask 
                                        v-model="pinCode"
                                        class="pin-input"
                                    />
                                </div>
                            </div>
                            
                            <!-- PIN Status -->
                            <div class="text-sm text-slate-500">
                                {{ pinCode.length }}/6 digits entered
                            </div>
                        </div>

                        <!-- Virtual Keyboard -->
                        <div class="bg-slate-50 rounded-xl p-4">
                            <SimpleKeyboard @onKeyPress="handleVirtualKeyPress" :options='keyboardOptions'></SimpleKeyboard>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Instructions Card -->
        <Card class="mt-6 shadow-lg border-0">
            <template #content>
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-slate-700 mb-3">
                        <i class="pi pi-info-circle mr-2"></i>
                        Instructions
                    </h3>
                    <div class="space-y-2 text-slate-600">
                        <div v-if="authMode === 'rfid'">
                            <p>• <strong>Badge Scanner:</strong> Hold your RFID badge close to the scanner</p>
                            <p>• <strong>Automatic Detection:</strong> The system will automatically detect and authenticate your badge</p>
                            <p>• <strong>Alternative:</strong> Click "Enter PIN Code Instead" if you don't have your badge</p>
                        </div>
                        <div v-else>
                            <p>• <strong>PIN Entry:</strong> Enter your 6-digit personal identification number</p>
                            <p>• <strong>Virtual Keyboard:</strong> Use the on-screen keypad or physical keyboard</p>
                            <p>• <strong>Auto Submit:</strong> The system will automatically log you in after 6 digits</p>
                        </div>
                    </div>
                </div>
            </template>
        </Card>
    </div>
</template>

<style scoped>
/* PIN Input Styling */
:deep(.pin-input .p-inputotp-input) {
    width: 3rem;
    height: 3rem;
    font-size: 1.5rem;
    font-weight: 600;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    margin: 0 0.25rem;
    text-align: center;
    transition: all 0.2s ease;
}

:deep(.pin-input .p-inputotp-input:focus) {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

:deep(.pin-input .p-inputotp-input.p-invalid) {
    border-color: #ef4444;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
}

/* Keyboard Styling */
:deep(.simple-keyboard) {
    background: transparent;
    border-radius: 12px;
}

:deep(.simple-keyboard .hg-button) {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    color: #374151;
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0.25rem;
    min-height: 3.5rem;
    min-width: 3.5rem;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

:deep(.simple-keyboard .hg-button:hover) {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

:deep(.simple-keyboard .hg-button:active) {
    transform: translateY(0);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* Special key styling */
:deep(.simple-keyboard .hg-button.hg-functionBtn) {
    background: #3b82f6;
    color: white;
    border-color: #2563eb;
}

:deep(.simple-keyboard .hg-button.hg-functionBtn:hover) {
    background: #2563eb;
    border-color: #1d4ed8;
}

/* Pulse animation for scanner */
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Mobile optimization */
@media (max-width: 768px) {
    :deep(.pin-input .p-inputotp-input) {
        width: 2.5rem;
        height: 2.5rem;
        font-size: 1.25rem;
        margin: 0 0.125rem;
    }
    
    :deep(.simple-keyboard .hg-button) {
        min-height: 3rem;
        min-width: 3rem;
        font-size: 1.1rem;
        margin: 0.125rem;
    }
}

/* Tablet optimization */
@media (min-width: 768px) and (max-width: 1024px) {
    :deep(.pin-input .p-inputotp-input) {
        width: 3.5rem;
        height: 3.5rem;
        font-size: 1.75rem;
    }
    
    :deep(.simple-keyboard .hg-button) {
        min-height: 4rem;
        min-width: 4rem;
        font-size: 1.5rem;
        margin: 0.375rem;
    }
}
</style>