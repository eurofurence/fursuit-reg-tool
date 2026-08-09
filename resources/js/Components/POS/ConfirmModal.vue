<script setup>
import { computed } from 'vue';
import Dialog from 'primevue/dialog';
import { posDialogPt } from '@/Components/POS/posDialog.js';

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
    <Dialog :closable="false" :visible="show" modal :header="title" :pt="posDialogPt" style="width:25rem;">
        <p class="mb-3">{{ message }}</p>
        <div class="grid grid-cols-2 gap-2">
            <button type="button" class="pos-btn pos-btn--commit w-full" :class="rejectClass" @click="emit('cancel')">
                {{ rejectLabel }}
            </button>
            <button type="button" class="pos-btn pos-btn--commit w-full" :class="acceptClass" @click="emit('confirm')">
                {{ acceptLabel }} <span class="pos-kcap">Enter</span>
            </button>
        </div>
    </Dialog>
</template>
