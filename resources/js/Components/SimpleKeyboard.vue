<template>
    <div :class="keyboardClass"></div>
</template>

<script setup>
import Keyboard from "simple-keyboard";
import "simple-keyboard/build/css/index.css";
import {onMounted, ref, watch} from "vue";

const props = defineProps({
    keyboardClass: {
        default: "simple-keyboard",
        type: String
    },
    input: {
        type: String
    },
    options: {
        type: Object
    }
})
const keyboard = ref(null);
onMounted(() => {
    keyboard.value = new Keyboard({
        onChange: input => onChange(input),
        onKeyPress: button => onKeyPress(button),
        ...props.options
    });
});

const emit = defineEmits(["onChange", "onKeyPress"]);

function onChange(input) {
    emit("onChange", input);
}
function onKeyPress(button) {
    emit("onKeyPress", button);
}

watch(() => props.input, (input) => {
    keyboard.value.setInput(input);
});

</script>

<!-- Add "scoped" attribute to limit CSS to this component only -->
<style>
.hg-theme-default.hg-layout-numeric .hg-button {
    align-items:center;
    display:flex;
    height:5rem;
    justify-content:center;
    width:33.3%;
}
</style>
