<script setup>
import { Head, Link } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import InputText from "primevue/inputtext";
import SimpleKeyboard from "@/Components/SimpleKeyboard.vue";
import {ref, onMounted} from "vue";
import {useForm} from "laravel-precognition-vue-inertia";
import Message from "primevue/message";

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
    if (attendeeIdInput.value) {
        attendeeIdInput.value.$el.focus();
    }
});

</script>

<template>
    <div class="flex-grow w-full max-w-xl mx-auto p-10 flex flex-col gap-4 justify-center">
        <Message v-if="form.invalid('attendeeId')" severity="error">{{ form.errors.attendeeId }}</Message>
        <InputText ref="attendeeIdInput" v-model="attendeeId" class="w-full text-2xl" type="text" size="large" placeholder="Attendee ID" :maxlength="maxAttendeeIdLength" @keydown="handleKeydown" />
        <SimpleKeyboard @onKeyPress="keyPress" :options='keyboardOptions'></SimpleKeyboard>
    </div>
</template>
