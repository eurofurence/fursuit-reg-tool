<script setup>
import InputOtp from 'primevue/inputotp';
import {ref, watch} from "vue";
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import {useForm} from "laravel-precognition-vue-inertia";
import sjcl from 'sjcl';
import Message from 'primevue/message';
import Button from "primevue/button";
import {router} from "@inertiajs/vue3";

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
    <div class="h-screen flex items-center justify-center text-center">
        <div class="space-y-2">
            <Button icon-pos="left" icon="pi pi-chevron-circle-left"  @click="router.visit(route('pos.auth.user.select'))" size="big" severity="secondary" class="h-12 w-full mb-4 px-4" label="Select a different User"/>
            <h1 class="text-4xl font-semibold">Welcome back, {{user.name}}</h1>
            <div class="text-lg text-gray-700 pb-3">Please enter your pin code to unlock the POS System.</div>
            <!-- Error Message -->
            <Message v-if="form.invalid('code')" severity="error">{{ form.errors.code }}</Message>
            <!-- Login Form -->
            <div class="flex justify-center">
                <InputOtp :invalid="form.invalid('code')" :autofocus="true" :length="6" integerOnly mask v-model="optCode"/>
            </div>

            <div class="pt-6">
                <SimpleKeyboard @onKeyPress="keyPress" :options='keyboardOptions'></SimpleKeyboard>
            </div>
        </div>
    </div>
</template>

<style scoped>

</style>
