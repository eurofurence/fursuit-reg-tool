<script setup>
import {onMounted, ref, watchEffect, computed} from "vue";
import { usePosKeyboard } from '@/composables/usePosKeyboard';
import POSLayout from "@/Layouts/POSLayout.vue";
import Button from 'primevue/button';
import Card from 'primevue/card';
import CashSVG from "@/Components/POS/Checkout/CashSVG.vue";
import {formatEuroFromCents} from "@/helpers.js";
import {useForm} from "laravel-precognition-vue-inertia";
import Message from 'primevue/message'
import {router} from "@inertiajs/vue3";
import {usePage} from '@inertiajs/vue3';

defineOptions({
    layout: POSLayout,
});

let given = ref(0);
let givenBills = ref([]);
let currentChange = ref([]);

const props = defineProps({
    checkout: Object,
    transaction: Object,
});

const page = usePage();
const machine = computed(() => page.props.auth.machine);

// Debug: Log machine data to console
console.log('🔍 Machine data:', machine.value);
console.log('🔍 SumUp Reader:', machine.value?.sumupReader);

const denominations = [
    200, // what's wrong with you, the order amount is TWO EUROS
    100,  50,   20,  10,   5,   // paper cash
    2,    1,    0.5, 0.2, 0.1,  // coins
    0.05, 0.02, 0.01
];

// Currency denominations for cash register
const cashDenominations = {
    banknotes: [50, 20, 10, 5],
    coins: [2, 1, 0.5, 0.2, 0.1, 0.05, 0.02, 0.01]
};

const positions = props.checkout.items;

const cardPaymentCheckInterval = ref(null);

watchEffect(() => {
    if (props.transaction) {
        if(props.transaction.status === 'PENDING' && !cardPaymentCheckInterval.value) {
            cardPaymentCheckInterval.value = setInterval(() => {
                console.log('checking transaction status');
                router.reload({only: ['transaction', 'checkout']});
                if (props.transaction.status !== 'PENDING') {
                    clearInterval(cardPaymentCheckInterval.value);
                }
            }, 1500);
        }
        if (props.transaction.status !== 'PENDING') {
            clearInterval(cardPaymentCheckInterval.value);
        }
    }
});

function denomToValue(denom) {
    return Number(denom.replace(/[^\d]/g, '')) / (denom.endsWith('¢') ? 100 : 1);
}

function calcChange(amount, given) {
    let diff = given - amount;

    // the code freaks out if negative numbers are involved
    if (diff <= 0)
        return [];

    return denominations.map(d => {
        const count = Math.floor(diff / d);

        diff -= d * count;

        return { denomination: d, amount: count };
    }).filter(d => d.amount > 0);
}

function clear() {
    given.value = 0;
    givenBills.value = [];
    currentChange.value = [];
}

function addCash(denomination) {
    givenBills.value.push(denomination);
    given.value = Math.round((given.value + denomination) * 100) / 100;
    currentChange.value = calcChange(props.checkout.total / 100, given.value);
}

function payWithCash() {
    if (given.value < (props.checkout.total / 100)) {
        console.log('Insufficient amount');
        return;
    }
    useForm('POST', route('pos.checkout.payWithCash', {'checkout': props.checkout.id}), {amount: given.value}).submit();
}

function cancel() {
    useForm('DELETE', route('pos.checkout.destroy', {'checkout': props.checkout.id}),{}).submit();
}

const startCardPaymentForm = useForm('POST', route('pos.checkout.startCardPayment', {'checkout': props.checkout.id}),{})

function startCardPayment() {
    startCardPaymentForm.submit();
}

// Use keyboard composable with custom handler for numpad divide
usePosKeyboard({
    // Override divide key to start card payment on this page
    onNumpadDivide: () => {
        // Only start card payment if checkout is not finished
        if (props.checkout.status !== 'FINISHED') {
            startCardPayment();
        }
    },
    // Don't disable global shortcuts, just override specific keys
    disableGlobalShortcuts: false
});

function getSeverityFromTransactionStatus(status) {
    switch (status) {
        case 'FAILED':
            return 'error';
        case 'PENDING':
            return 'warn';
        case 'SUCCESS':
            return 'success';
        default:
            return 'info';
    }
}

function receiptForm(via) {
    useForm('POST',route('pos.checkout.receipt.'+via, {'checkout': props.checkout.id}),{}).submit();
}

</script>

<template>
    <div class="w-full flex-1 flex flex-col">
        <!-- Main Content Area -->
        <div class="flex-1 flex flex-row gap-2 mb-2">
            <!-- Left Side - Cash Calculator -->
            <div class="flex-1" v-if="checkout.status !== 'FINISHED'">
                <!-- add flex-1 to content and make it flex-col with space-between -->
                <Card class="h-full" :pt="{
                    body: { class: 'p-5 flex-1 flex flex-col h-full'},
                    content: { class: 'h-full flex-1' }
                }">
                    <template #title>Cash Register</template>
                    <template #content>
                        <!-- Vertical Grid: Amount Area / Banknotes+Coins Area -->
                        <div class="grid grid-rows-2 gap-4 h-full">
                            <!-- Top Half - Cash Input Display -->
                            <div class="h-full overflow-y-auto flex-1">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-sm font-medium">Amount Given:</span>
                                    <div class="text-xl font-bold" :class="given >= (checkout.total / 100) ? 'text-green-600' : 'text-red-600'">
                                        {{ given.toFixed(2) }}€
                                    </div>
                                </div>

                                <!-- Given Cash and Change Display - Side by Side -->
                                <div class="flex flex-col gap-4 mb-2">
                                    <!-- Given Cash Display -->
                                    <div v-if="givenBills.length > 0">
                                        <div class="text-xs font-medium mb-1">Cash Received:</div>
                                        <div class="flex flex-wrap gap-1 max-h-16 overflow-y-auto">
                                            <CashSVG v-for="(bill, index) in givenBills" :key="index"
                                                     :denomination="bill" size="small" />
                                        </div>
                                    </div>

                                    <!-- Change Display -->
                                    <div v-if="currentChange.length > 0">
                                        <div class="text-xs font-medium mb-1">Change to Return:</div>
                                        <div class="flex flex-wrap gap-1">
                                            <div v-for="c in currentChange" :key="c.denomination" class="flex items-center gap-1 text-xs">
                                                <span class="font-semibold">{{ c.amount }}×</span>
                                                <CashSVG :denomination="c.denomination" size="small" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Status Message -->
                                <div class="text-xs">
                                    <div v-if="given < (checkout.total / 100)" class="text-red-600 font-medium">
                                        Need {{ ((checkout.total / 100) - given).toFixed(2) }}€ more
                                    </div>
                                    <div v-else-if="currentChange.length === 0" class="text-green-600 font-medium">
                                        Exact change - Ready to complete!
                                    </div>
                                    <div v-else class="text-yellow-600 font-medium">
                                        Change: {{ (given - (checkout.total / 100)).toFixed(2) }}€
                                    </div>
                                </div>
                            </div>

                            <!-- Bottom Half - Cash Calculator -->
                            <div class="border-t pt-4">
                                <h4 class="text-sm font-semibold mb-3">Cash Calculator</h4>
                                <div class="flex flex-col gap-3">
                                    <!-- Banknotes -->
                                    <div class="grid grid-cols-4 gap-2">
                                        <button v-for="denomination in cashDenominations.banknotes"
                                                :key="denomination"
                                                @click="addCash(denomination)"
                                                class="aspect-[3/2] hover:bg-blue-50 transition-colors rounded">
                                            <CashSVG :denomination="denomination" size="large" />
                                        </button>
                                    </div>

                                    <!-- Coins -->
                                    <div class="flex gap-1">
                                        <button v-for="denomination in cashDenominations.coins"
                                                :key="denomination"
                                                @click="addCash(denomination)"
                                                class="flex-1 hover:bg-blue-50 transition-colors rounded">
                                            <CashSVG :denomination="denomination" size="normal" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>

            <!-- Receipt Options (when finished) - Left side -->
            <div class="flex-1" v-if="checkout.status === 'FINISHED'">
                <Card class="h-full flex items-center justify-center">
                    <template #content>
                        <div class="text-center">
                            <div class="text-green-600 mb-4">
                                <i class="pi pi-check-circle text-4xl"></i>
                                <div class="text-xl font-bold mt-2">Transaction Complete</div>
                            </div>
                            <div class="flex gap-4 justify-center">
                                <Button severity="contrast" size="large" icon="pi pi-at" label="E-Mail Receipt"
                                        @click="receiptForm('email')" />
                                <Button severity="contrast" size="large" icon="pi pi-print" label="Print Receipt"
                                        @click="receiptForm('print')" />
                            </div>
                        </div>
                    </template>
                </Card>
            </div>

            <!-- Right Side - Transaction Overview -->
            <div class="flex-1">
                <Card class="h-full" :pt="{
                    body: { class: 'p-5 flex-1 flex flex-col h-full'},
                    content: { class: 'h-full flex-1 flex justify-between flex-col' }
                }">
                    <template #title>Transaction #{{ checkout.id }}</template>
                    <template #content>
                        <!-- Items List -->
                        <div class="flex-1 ">
                            <div class="mb-4 max-h-80 overflow-y-auto">
                                <div v-for="pos in positions" :key="pos.id" class="mb-3 p-2 border-b border-gray-200">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1">
                                            <div class="font-medium text-sm">{{ pos.name }}</div>
                                            <div class="text-xs text-gray-600">Fursuit Badge #{{ pos.payable_id }}</div>
                                            <div v-if="pos.description && pos.description.length" class="text-xs text-gray-500 mt-1">
                                                {{ pos.description.join(', ') }}
                                            </div>
                                        </div>
                                        <div class="font-bold">{{ formatEuroFromCents(pos.total) }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Totals -->
                        <div class="space-y-1 text-sm border-t pt-3">
                            <div class="flex justify-between">
                                <span>Subtotal:</span>
                                <span>{{ formatEuroFromCents(checkout.subtotal) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Tax (19%):</span>
                                <span>{{ formatEuroFromCents(checkout.tax) }}</span>
                            </div>
                            <div class="flex justify-between font-bold text-lg border-t pt-2">
                                <span>TOTAL:</span>
                                <span class="text-green-600">{{ formatEuroFromCents(checkout.total) }}</span>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="mt-4">
                            <div v-if="transaction" class="mb-2">
                                <div class="text-xs font-medium mb-1">Payment Status:</div>
                                <Message :closable="false" :severity="getSeverityFromTransactionStatus(transaction.status)" class="text-xs p-2">
                                    {{ transaction.status }}
                                </Message>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Bottom Row - Action Buttons -->
        <div class="flex gap-2 h-12">
            <!-- Cash Payment Button -->
            <Button v-if="checkout.status !== 'FINISHED'"
                @click="payWithCash"
                :disabled="given < (checkout.total / 100) || (transaction && (transaction.status === 'SUCCESSFUL' || transaction.status === 'PENDING'))"
                severity="success"
                size="small"
                class="flex-1 h-12 text-xs font-bold"
                icon="pi pi-money-bill"
                label="Complete Cash" />

            <!-- Card Payment Button -->
            <Button v-if="checkout.status !== 'FINISHED' && machine && machine.sumup_reader"
                @click="startCardPayment"
                :disabled="transaction && (transaction.status === 'SUCCESSFUL' || transaction.status === 'PENDING')"
                :loading="startCardPaymentForm.processing"
                severity="primary"
                size="small"
                class="flex-1 h-12 text-xs font-bold relative"
                icon="pi pi-credit-card">
                <template #default>
                    <span>Pay Card</span>
                    <span class="ml-2 text-xs opacity-75">[/]</span>
                </template>
            </Button>

            <!-- Clear Cash Button -->
            <Button v-if="checkout.status !== 'FINISHED'"
                @click="clear"
                severity="secondary"
                size="small"
                class="flex-1 h-12 text-xs font-bold"
                icon="pi pi-refresh"
                label="Clear" />

            <!-- Cancel Transaction Button -->
            <Button @click="cancel"
                :disabled="checkout.status === 'FINISHED' || (transaction && (transaction.status === 'SUCCESSFUL' || transaction.status === 'PENDING'))"
                severity="danger"
                size="small"
                class="flex-1 h-12 text-xs font-bold"
                icon="pi pi-times"
                label="Cancel" />
        </div>
    </div>
</template>
