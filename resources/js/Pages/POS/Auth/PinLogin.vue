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
// Numpad order (7-8-9 on top), same as the dashboard lookup and the physical
// pad on the desk, so muscle memory carries between the two.
const keyboardOptions = {
    layout: {
        default: [
            "7 8 9",
            "4 5 6",
            "1 2 3",
            "0 {backspace} {enter}"
        ]
    },
    display: {
        "{backspace}": "Delete",
        "{enter}": "Login",
        "{space}": " "
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
            <Card class="border-0">
                <template #content>
                    <div class="p-8">
                        <!-- RFID Scanner Mode (Default) -->
                        <div v-if="authMode === 'rfid'" class="text-center">
                            <!-- Animated Icon -->
                            <div class="mb-6">
                                <i class="pi pi-qrcode text-6xl text-pos-accent animate-pulse"></i>
                            </div>
                            
                            <!-- Title -->
                            <h1 class="text-3xl font-bold text-pos-text mb-6">Scan Your Access Tag</h1>
                            
                            <!-- Error Message -->
                            <div v-if="form.invalid('code')" class="mb-4">
                                <Message severity="error">{{ form.errors.code }}</Message>
                            </div>
                            
                            <!-- RFID Detection -->
                            <div v-if="rfidCode" class="mb-4 p-3 bg-pos-good/10 border border-pos-good/30 rounded-pos">
                                <div class="flex items-center justify-center text-pos-good">
                                    <i class="pi pi-check-circle mr-2"></i>
                                    <span class="font-medium">Badge Detected</span>
                                </div>
                            </div>
                            
                            <!-- Status -->
                            <p class="text-pos-muted mb-6">
                                <span class="inline-flex items-center">
                                    <span class="w-2 h-2 bg-pos-good rounded-full mr-2 animate-pulse"></span>
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
                            <button @click="switchToRfid" class="mb-4 text-pos-muted hover:text-pos-text">
                                <i class="pi pi-arrow-left mr-2"></i>Back
                            </button>
                            
                            <div class="text-center">
                                <!-- Title -->
                                <h1 class="text-2xl font-bold text-pos-text mb-6">Enter PIN Code</h1>
                                
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
                                <SimpleKeyboard @onKeyPress="handleVirtualKeyPress" :options='keyboardOptions'></SimpleKeyboard>

                                <div class="flex justify-between text-xs text-pos-muted mt-3">
                                    <span><span class="pos-kcap mr-1">0-9</span>PIN</span>
                                    <span><span class="pos-kcap mr-1">Enter</span>login</span>
                                    <span><span class="pos-kcap mr-1">⌫</span>delete</span>
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
/*
 * PIN entry uses the same tokens as the dashboard lookup: mono tabular digits,
 * 3px shape, line borders, accent focus ring. The keypad itself is left to
 * resources/css/pos.css so both keypads stay identical by construction.
 */
:deep(.pin-input .p-inputotp-input) {
    width: 3.25rem;
    height: 3.5rem;
    font-family: var(--pos-num);
    font-variant-numeric: tabular-nums;
    font-size: 1.6rem;
    font-weight: 600;
    color: rgb(var(--pos-text));
    background: rgb(var(--pos-panel-2));
    border: 1px solid rgb(var(--pos-line));
    border-radius: var(--pos-radius);
    margin: 0 0.25rem;
    text-align: center;
}

:deep(.pin-input .p-inputotp-input:focus) {
    border-color: rgb(var(--pos-accent));
    outline: 3px solid rgb(var(--pos-accent));
    outline-offset: 2px;
    box-shadow: none;
}

:deep(.pin-input .p-inputotp-input.p-invalid) {
    border-color: rgb(var(--pos-bad));
    outline-color: rgb(var(--pos-bad));
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

/* Phones: keep the six boxes on one line. */
@media (max-width: 768px) {
    :deep(.pin-input .p-inputotp-input) {
        width: 2.5rem;
        height: 3rem;
        font-size: 1.3rem;
        margin: 0 0.125rem;
    }
}

/* Tablets are the main POS device: bigger targets, same shape. */
@media (min-width: 768px) and (max-width: 1024px) {
    :deep(.pin-input .p-inputotp-input) {
        width: 3.75rem;
        height: 4rem;
        font-size: 1.9rem;
    }
}
</style>