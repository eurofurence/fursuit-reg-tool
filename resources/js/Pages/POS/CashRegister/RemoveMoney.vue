<script setup>
import { Head } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import { useForm } from "laravel-precognition-vue-inertia";
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import ConfirmModal from "@/Components/POS/ConfirmModal.vue";
import {ref} from "vue";
import Button from 'primevue/button';
import Message from "primevue/message";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    balance: String
});

const form = useForm('POST', route('pos.wallet.money.remove.submit'), {
    amount: ''
});

const amount = ref('');
const showConfirmModal = ref(false);
const showWithdrawAllModal = ref(false);

const keyboardOptions = {
    layout: {
        default: ["7 8 9", "4 5 6","1 2 3",  "0 {backspace} {enter}"]
    },
    display: {
        "{backspace}": "Delete",
        "{enter}": "Withdraw Amount",
    },
    autoUseTouchEvents: false,
    theme: "hg-theme-default hg-layout-numeric numeric-theme"
}

const submit = () => {
    form.amount = amount;
    form.submit();
    showConfirmModal.value = false;
};

const withdrawAll = () => {
    form.amount = `${props.balance || 0}`;
    form.submit();
    showWithdrawAllModal.value = false;
}

const keyPress = (event) => {
    switch (event) {
        case "{backspace}":
            amount.value = amount.value.slice(0, -1);
            break;
        case "{enter}":
            if(amount.value.length > 0)
                showConfirmModal.value = true;
            break;
        default:
            amount.value += event;
            if (parseInt(amount.value) >= parseInt(props.balance || 0))
                amount.value = props.balance || 0;
            break;
    }
};

</script>

<template>
    <Head title="Machine Wallet"/>
    <div>
        <ConfirmModal
            title="Confirm Amount"
            :message="'Are you sure you want to withdraw ' + (parseInt(amount || 0) / 100.0).toFixed(2) + ' € from the cash register?'"
            :show="showConfirmModal"
            @confirm="submit()"
            @cancel="showConfirmModal = false;"
        />
        <ConfirmModal
            title="Confirm Amount"
            :message="'Are you sure you want to withdraw ' + (parseInt(props.balance || 0) / 100.0).toFixed(2) + ' € from the cash register?'"
            :show="showWithdrawAllModal"
            @confirm="withdrawAll()"
            @cancel="showWithdrawAllModal = false;"
        />
    </div>
    <div class="grow flex flex-col gap-3 items-center w-full max-w-lg mx-auto">
        <Message v-if="form.invalid('amount')" class="w-full !my-0" severity="error" :closable="false">{{ form.errors.amount }}</Message>
        <div class="text-6xl text-gray-700 my-3">
            {{ (parseInt(amount || 0) / 100.0).toFixed(2) }} €
        </div>
        <SimpleKeyboard @onKeyPress="keyPress" :options='keyboardOptions'></SimpleKeyboard>
        <Button label="Withdraw all" severity="danger" class="w-full py-4" @click="showWithdrawAllModal = true" />
    </div>
</template>
