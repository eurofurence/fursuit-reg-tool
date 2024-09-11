<script setup>
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';

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

</script>

<template>
    <Dialog :closable="false" :visible="show" modal :header="title" style="width:25rem;">
        <p class="mb-3">{{ message }}</p>
        <div class="grid grid-cols-2 gap-2">
            <Button :severity="rejectSeverity" class="w-full h-24" :label="rejectLabel" @click="emit('cancel')" />
            <Button :severity="acceptSeverity" class="w-full h-24" :label="acceptLabel" @click="emit('confirm')" />
        </div>
    </Dialog>
</template>

<style scoped>

</style>
