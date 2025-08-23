<script setup>
import InputOtp from 'primevue/inputotp';
import {ref, watch} from "vue";
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import {useForm} from "laravel-precognition-vue-inertia";
import sjcl from 'sjcl';
import Message from 'primevue/message';
import Button from "primevue/button";
import Card from 'primevue/card';
import {router} from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    salt: {
        type: String
    },
    user: {
        type: Object
    }
});

const form = useForm('POST', route('pos.auth.user.login.submit', {
    user: props.user.id
}), {
    code: ''
});

const optCode = ref('');
const keyboardOptions = {
    layout: {
        default: ["7 8 9", "4 5 6","1 2 3",  "0 {backspace} {enter}"]
    },
    display: {
        "{backspace}": "backspace ⌫",
        "{enter}": "enter ↵",
    },
    autoUseTouchEvents: false,
    theme: "hg-theme-default hg-layout-numeric numeric-theme"
}

function keyPress(event) {
    if (event === "{backspace}") {
        optCode.value = optCode.value.slice(0, -1);
    } else if (event === "{enter}") {
        submit();
    } else {
        // only add when length is less than 6
        if (optCode.value.length < 6) {
            optCode.value += event;
        }
    }
}

// watch / autosubmit when the length of the otp code is 6
watch(optCode, (value) => {
    if (value.length === 6) {
        submit();
    }
}, {flush: 'post'});

function submit() {
    // If less than 6 set form.errors.code to error message
    if (optCode.value.length < 6) {
        return;
    }
    const myBitArray = sjcl.hash.sha256.hash(optCode.value + props.salt)
    form.code = sjcl.codec.hex.fromBits(myBitArray);
    form.submit();
    // clear otp code
    optCode.value = '';
}
</script>

<template>
    <div class="max-w-4xl mx-auto">
        <!-- Back Button -->
        <div class="mb-6">
            <Button 
                icon="pi pi-arrow-left" 
                @click="router.visit(route('pos.auth.user.select'))" 
                severity="secondary" 
                class="p-button-lg" 
                label="Back to Login"
            />
        </div>

        <!-- Main Authentication Card -->
        <Card class="shadow-xl border-0">
            <template #content>
                <div class="p-8 text-center">
                    <!-- User Avatar/Icon -->
                    <div class="mb-6">
                        <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                            <i class="pi pi-user text-3xl text-blue-600"></i>
                        </div>
                        <h1 class="text-3xl font-bold text-slate-800 mb-2">Welcome back, {{ user.name }}</h1>
                        <p class="text-lg text-slate-600">Enter your PIN code to access the POS system</p>
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
                                    v-model="optCode"
                                    class="pin-input"
                                />
                            </div>
                        </div>
                        
                        <!-- PIN Status -->
                        <div class="text-sm text-slate-500">
                            {{ optCode.length }}/6 digits entered
                        </div>
                    </div>

                    <!-- Touch Keyboard -->
                    <div class="bg-slate-50 rounded-xl p-4">
                        <SimpleKeyboard @onKeyPress="keyPress" :options='keyboardOptions'></SimpleKeyboard>
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
