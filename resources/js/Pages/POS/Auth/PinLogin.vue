<script setup>
import Card from 'primevue/card';
import Button from 'primevue/button';
import InputOtp from 'primevue/inputotp';
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import Message from 'primevue/message';
import {useForm} from "laravel-precognition-vue-inertia";
import {ref, watch, onMounted, onUnmounted, nextTick} from "vue";
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
const pinInputRef = ref(null);

// Keyboard configuration for PIN entry - NUMBERS ONLY
const keyboardOptions = {
    layout: {
        default: [
            "1 2 3",
            "4 5 6", 
            "7 8 9",
            "{backspace} 0 {enter}"
        ]
    },
    display: {
        "{backspace}": "⌫",
        "{enter}": "↵",
        "{space}": " "
    },
    autoUseTouchEvents: false,
    theme: "hg-theme-default"
};

// RFID Scanner Detection
let keyBuffer = '';
let keyTimer = null;
const RFID_MIN_LENGTH = 8; // Minimum length for RFID codes
const KEY_TIMEOUT = 100; // Milliseconds between keys to detect scanner

const handleKeyPress = (event) => {
    // Handle Backspace to go back from PIN mode to RFID mode
    if (authMode.value === 'pin' && event.key === 'Backspace') {
        // Check if we're not typing in an input field
        const activeElement = document.activeElement;
        const isInInput = activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA');
        
        // If PIN code is empty and we're not in an input, go back
        if (pinCode.value === '' && !isInInput) {
            event.preventDefault();
            switchToRfid();
            return;
        }
    }
    
    // Handle NumpadDivide (/) to switch to PIN mode from RFID mode
    if (authMode.value === 'rfid' && event.code === 'NumpadDivide') {
        event.preventDefault();
        switchToPin();
        return;
    }
    
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
    
    // Send PIN directly without hashing
    form.code = pinCode.value.toUpperCase();
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
    } else if (event === "{space}") {
        // Ignore space for PIN/setup code entry
        return;
    } else {
        if (pinCode.value.length < 6) {
            pinCode.value += event.toUpperCase();
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
    // Focus the PIN input after switching
    nextTick(() => {
        if (pinInputRef.value && pinInputRef.value.$el) {
            const firstInput = pinInputRef.value.$el.querySelector('input');
            if (firstInput) {
                firstInput.focus();
            }
        }
    });
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
    <div class="w-full min-h-full flex items-center justify-center">
        <div class="max-w-md w-full">
            <!-- Single Centered Card -->
            <Card class="shadow-xl border-0">
                <template #content>
                    <div class="p-8">
                        <!-- RFID Scanner Mode (Default) -->
                        <div v-if="authMode === 'rfid'" class="text-center">
                            <!-- Animated Icon -->
                            <div class="mb-6">
                                <i class="pi pi-qrcode text-6xl text-blue-500 animate-pulse"></i>
                            </div>
                            
                            <!-- Title -->
                            <h1 class="text-3xl font-bold text-slate-800 mb-6">Scan Your Access Tag</h1>
                            
                            <!-- Error Message -->
                            <div v-if="form.invalid('code')" class="mb-4">
                                <Message severity="error">{{ form.errors.code }}</Message>
                            </div>
                            
                            <!-- RFID Detection -->
                            <div v-if="rfidCode" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center justify-center text-green-800">
                                    <i class="pi pi-check-circle mr-2"></i>
                                    <span class="font-medium">Badge Detected</span>
                                </div>
                            </div>
                            
                            <!-- Status -->
                            <p class="text-slate-600 mb-6">
                                <span class="inline-flex items-center">
                                    <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                                    Listening for badge scan...
                                </span>
                            </p>
                            
                            <!-- Alternative Login Button -->
                            <Button 
                                @click="switchToPin"
                                label="Enter PIN Code"
                                severity="secondary"
                                class="w-full"
                            />
                        </div>

                        <!-- PIN Entry Mode -->
                        <div v-else-if="authMode === 'pin'">
                            <!-- Back Button -->
                            <button @click="switchToRfid" class="mb-4 text-slate-600 hover:text-slate-800">
                                <i class="pi pi-arrow-left mr-2"></i>Back
                            </button>
                            
                            <div class="text-center">
                                <!-- Title -->
                                <h1 class="text-2xl font-bold text-slate-800 mb-6">Enter PIN Code</h1>
                                
                                <!-- Error Message -->
                                <div v-if="form.invalid('code')" class="mb-4">
                                    <Message severity="error">{{ form.errors.code }}</Message>
                                </div>
                                
                                <!-- PIN Input -->
                                <div class="mb-6">
                                    <div class="flex justify-center">
                                        <InputOtp 
                                            ref="pinInputRef"
                                            :invalid="form.invalid('code')" 
                                            :autofocus="true" 
                                            :length="6" 
                                            mask 
                                            v-model="pinCode"
                                            class="pin-input"
                                        />
                                    </div>
                                </div>
                                
                                <!-- Virtual Keyboard -->
                                <div class="bg-slate-50 rounded-lg p-3">
                                    <SimpleKeyboard @onKeyPress="handleVirtualKeyPress" :options='keyboardOptions'></SimpleKeyboard>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>
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