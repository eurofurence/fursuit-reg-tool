<script setup>
import { Head, Link } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import {ref, onMounted} from "vue";
import {useForm} from "laravel-precognition-vue-inertia";

defineOptions({
    layout: POSLayout,
});

const form = useForm('POST', route('pos.attendee.lookup.submit'), {
    attendeeId: ''
});

const attendeeId = ref('');
const attendeeIdInput = ref(null);
const maxAttendeeIdLength = 5;

const keyboardOptions = {
    layout: {
        default: ["7 8 9", "4 5 6","1 2 3",  "0 {backspace} {enter}"]
    },
    display: {
        "{backspace}": "Delete",
        "{enter}": "Search",
    },
    autoUseTouchEvents: false,
    theme: "hg-theme-default hg-layout-numeric numeric-theme"
}

const keyPress = (event) => {
    switch (event) {
        case "{backspace}":
            attendeeId.value = attendeeId.value.slice(0, -1);
            break;
        case "{enter}":
            submit();
            break;
        default:
            if (attendeeId.value.length < maxAttendeeIdLength) {
                attendeeId.value += event;
            }
            break;
    }
};

const submit = () => {
    console.log(attendeeId.value);
    form.attendeeId = attendeeId;
    form.submit();
};

const handleKeydown = (event) => {
    if (event.key === 'Enter') {
        submit();
    }
};

onMounted(() => {
    attendeeIdInput.value?.focus();
});

</script>

<template>
    <div class="flex-grow w-full max-w-xl mx-auto py-6 flex flex-col justify-center">
        <div class="pos-card flex flex-col gap-3">
            <div class="pos-card__head">
                <h1>Attendee Lookup</h1>
                <span class="pos-muted text-xs">Numpad types here · scanner types here</span>
            </div>

            <p v-if="form.invalid('attendeeId')"
               class="px-3 py-2 rounded-pos border border-pos-bad text-pos-bad text-sm font-semibold">
                {{ form.errors.attendeeId }}
            </p>

            <input
                ref="attendeeIdInput"
                v-model="attendeeId"
                class="pos-field"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                placeholder="Attendee ID"
                :maxlength="maxAttendeeIdLength"
                @keydown="handleKeydown"
            />

            <SimpleKeyboard @onKeyPress="keyPress" :options='keyboardOptions'></SimpleKeyboard>

            <div class="flex justify-between text-xs text-pos-muted">
                <span><span class="pos-kcap mr-1">0-9</span>attendee id</span>
                <span><span class="pos-kcap mr-1">Enter</span>search</span>
                <span><span class="pos-kcap mr-1">⌫</span>delete</span>
            </div>
        </div>
    </div>
</template>
