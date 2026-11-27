<script setup>
import { computed } from 'vue';
import { AlertDialog, AlertDialogContent, AlertDialogDescription, AlertDialogTitle } from '@/Components/UI/alert-dialog';

const props = defineProps({
    title: String,
    message: String,
    show: Boolean,
    acceptLabel: {
        type: String,
        default: 'Confirm'
    },
    acceptSeverity: {
        type: String,
        default: null
    },
    rejectLabel: {
        type: String,
        default: 'Cancel'
    },
    rejectSeverity: {
        type: String,
        default: 'secondary'
    },
    // Cleared for dialogs the keyboard deliberately cannot answer, so the cap
    // does not promise a shortcut that has been switched off.
    showEnterHint: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['confirm', 'cancel']);

// The severity props stay the component's API; here they only pick which
// ledger button the action wears. A destructive confirm goes red, everything
// else takes the accent — cancel is always the quiet one.
const acceptClass = computed(() => (props.acceptSeverity === 'danger' ? 'pos-btn--danger' : 'pos-btn--primary'));
const rejectClass = computed(() => (props.rejectSeverity === 'danger' ? 'pos-btn--danger' : ''));

</script>

<template>
    <!-- Controlled by `show` alone: Escape and the backdrop leave the answer to the buttons. -->
    <AlertDialog :open="show">
        <AlertDialogContent class="pos-dialog pos-surface z-1102 gap-0 p-0 shadow-none sm:max-w-[25rem]">
            <div class="pos-dialog__head">
                <AlertDialogTitle class="pos-dialog__title">{{ title }}</AlertDialogTitle>
            </div>
            <div class="pos-dialog__body">
                <AlertDialogDescription class="mb-3 text-base text-pos-text">{{ message }}</AlertDialogDescription>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="pos-btn pos-btn--commit w-full" :class="rejectClass" @click="emit('cancel')">
                        {{ rejectLabel }}
                    </button>
                    <button type="button" class="pos-btn pos-btn--commit w-full" :class="acceptClass" @click="emit('confirm')">
                        {{ acceptLabel }} <span v-if="showEnterHint" class="pos-kcap">Enter</span>
                    </button>
                </div>
            </div>
        </AlertDialogContent>
    </AlertDialog>
</template>
