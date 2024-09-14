<script setup>
import {ref} from "vue";
import POSLayout from "@/Layouts/POSLayout.vue";
import Button from 'primevue/button';
import Cash from "@/Components/POS/Checkout/Cash.vue";
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import {formatEuroFromCents} from "@/helpers.js";

defineOptions({
    layout: POSLayout,
});

let given = ref(0);
let givenBills = ref([]);
let currentChange = ref([]);

const props = defineProps({
    checkout: Object,
    total: Number,
});

const denominations = [
    200, // what's wrong with you, the order amount is TWO EUROS
    100,  50,   20,  10,   5,   // paper cash
    2,    1,    0.5, 0.2, 0.1,  // coins
    0.05, 0.02, 0.01
];

const keyboardOptions = {
    layout: {
        default: [
            // bills
            "200€ 100€ 50€",
            "20€ 10€ 5€",
            // coins
            "2€ 1€",
            // literal dog water
            // "20¢ 10¢ 5¢",
            "{reset} {enter}",

        ]
    },
    display: {
        "{reset}": "Clear",
        "{enter}": "Pay With Cash",
    },
    autoUseTouchEvents: false,
    theme: "hg-theme-default hg-layout-numeric numeric-theme"
};

const positions = props.checkout.items;

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

function keyPress(event) {
    if (event === "{reset}") {
        clear();
    } else if (event === "{enter}") {
        // pay with cash logic here
        // todo: implement
    } else {
        const eventVal = denomToValue(event);
        givenBills.value.push(event)
        given.value = Math.round((given.value + eventVal) * 100) / 100;;
        // default value of 4 for testing
        // todo: remove...                           vvvv ...this bit here
        currentChange.value = calcChange(props.total || 4, given.value);
    }
}

</script>

<template>
    <div class="grid grid-cols-2 gap-4 px-4 pb-4 h-full">
        <!-- cash -->
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-4 ">
                <div class="flex flex-col flex-wrap gap-1 ">
                    <span class="flex rounded-lg bg-blue-200 p-3 shrink">Given: {{ given }}€ total</span>
                    <div class="flex flex-row gap-1 flex-wrap overflow-hidden">
                        <div v-for="b in givenBills" :key="b">
                            <Cash :denomination="denomToValue(b)" />
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-1 " v-if="currentChange.length > 0">
                    <span class="flex rounded-lg bg-amber-200 p-3 shrink">Change:</span>
                    <div class="flex flex-wrap gap-4">
                        <div class="grid grid-cols-[auto_1fr] gap-4 items-center" v-for="c in currentChange" :key="c">
                        <span>{{ c.amount }}x </span>
                        <div class="flex">
                            <Cash :denomination="c.denomination" />
                        </div>
                    </div>
                    </div>
                </div>
                <!-- default value of 2 for testing, todo: remove...   this bit here: vvvv -->
                <div class="hidden flex rounded-lg bg-red-300 p-3" v-else-if="given < (total || 4)">
                    <span class=""><strong>!</strong> Insufficient amount</span>
                </div>
                <div class="flex rounded-lg bg-teal-200 p-3" v-else>
                    <span class=""><strong>✓</strong> Exact change!</span>
                </div>
            </div>
            <div class="flex-1 flex items-end">
                <SimpleKeyboard @onKeyPress="keyPress" :options="keyboardOptions" />
            </div>
        </div>
        <!-- card & status -->
        <div class="bg-white border-gray-400 rounded-lg text-black">
            <div class="flex flex-col grow p-4 gap-4 h-[100%]">
                <div class="flex flex-col grow">
                    <span class="text-2xl">Positions:</span>
                    <div v-if="positions" class="flex flex-col">
                        <div v-for="pos in (positions)" :key="pos" class="flex p-2 rounded-lg gap-2 items-center">
                            <span><strong class="bg-white rounded-lg p-1">#{{pos.payable_id}}</strong></span>
                            <div class="flex bg-gray-200 grow justify-between p-1 rounded-lg">
                                <div>
                                    <strong>{{ pos.name }}</strong>
                                    <ul>
                                        <li v-for="item in pos.description" :key="item">{{ item }}</li>
                                    </ul>
                                </div>
                                <span class="ml-[auto]"><strong>{{ formatEuroFromCents(pos.total) }}</strong></span>
                            </div>
                        </div>
                    </div>
                    <div v-else>
                        <strong>No items provided!</strong> If you didn't accidentally initiate a transaction on a completely empty order, this is probably a bug.
                    </div>
                </div>
                <!-- todo: implement -->
                <div>
                    <div class="text-1xl text-gray-600 border-b-gray-400 flex justify-between items-end border-b-2 border-dotted border-black pb-2 mb-2">
                        <div>Tax</div>
                        <div>{{ formatEuroFromCents(checkout.tax) }}</div>
                    </div>
                    <div class="text-2xl flex justify-between items-end border-b-2 border-double border-black pb-2">
                        <div>Total</div>
                        <div>{{ formatEuroFromCents(checkout.total) }}</div>
                    </div>
                </div>
                <div class="flex rounded-lg bg-cyan-200 p-3 shrink">card status here</div>
                <div class="flex justify-between gap-4 shrink">
                    <Button label="Cancel Transaction" @click="cancel" class="grow"></Button>
                    <Button label="Pay With Card" @click="cancel" class="grow"></Button>
                </div>
            </div>
        </div>
    </div>
</template>
