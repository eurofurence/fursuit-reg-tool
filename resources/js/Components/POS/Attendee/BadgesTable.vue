<script setup>

import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Button from "primevue/button";
import dayjs from "dayjs";
import {ref, watchEffect} from "vue";
import Checkbox from "primevue/checkbox";
import {formatEuroFromCents} from "../../..//helpers.js";
import ConfirmModal from "@/Components/POS/ConfirmModal.vue";
import {useToast} from "primevue/usetoast";
import {useForm} from "laravel-precognition-vue-inertia";
import Tag from 'primevue/tag';
const toast = useToast();

defineProps({
    attendee: Object,
    badges: Array
})

const emit = defineEmits(['update:selectedBadges', 'printBadge']);

const selectedBadges = ref([]);

/** Emit update everytime the selectedBadges change */
watchEffect(() => {
    emit('update:selectedBadges', selectedBadges.value);
});

function changeHandout(badgeId, undo) {
    if(undo === false) {
        useForm('post',route('pos.badges.handout', {badge: badgeId}),{}).submit({
            preserveScroll: true,
        })
    } else {
        useForm('post',route('pos.badges.handout.undo', {badge: badgeId}),{}).submit({
            preserveScroll: true,
        })
    }
}

</script>

<template>
    <DataTable dataKey="id" v-model:selection="selectedBadges" :value="badges" class="-m-5" tableStyle="min-width: 50rem">
        <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
        <Column field="custom_id" header="ID"></Column>
        <Column field="fursuit.name" header="Fursuit"></Column>
        <Column field="fursuit.status" header="Fursuit Status"></Column>
        <Column field="printed_at" header="Print">
            <template #body="slotProps">
                {{ (slotProps.data.printed_at) ? dayjs(slotProps.data.printed_at).format('DD.MM.YY') : '-' }}
            </template>
        </Column>
        <Column field="dual_side_print" header="Duplex">
            <template #body="slotProps">
                <Checkbox :modelValue="slotProps.data.dual_side_print" :binary="true" />
            </template>
        </Column>
        <Column field="status" header="Status">
            <template #body="slotProps">
                <Tag severity="info"    v-if="slotProps.data.status === 'pending'" value="Pending" />
                <Tag severity="success" v-else-if="slotProps.data.status === 'picked_up'" value="Picked up" />
                <Tag severity="warning" v-else-if="slotProps.data.status === 'ready_for_pickup'" value="Ready for Pickup" />
                <Tag severity="danger"  v-else-if="slotProps.data.status === 'unpaid'" value="Unpaid" />
                <Tag v-else :value="slotProps.data.status" />
            </template>
        </Column>
        <Column field="wallet.balance" header="Price">
            <template #body="slotProps">
                {{ formatEuroFromCents(slotProps.data.total) }}
            </template>
        </Column>
        <Column header="Actions">
            <template #body="slotProps">
                <div class="grid grid-cols-2 gap-5">
                    <Button v-if="slotProps.data.status === 'pending'" @click="emit('printBadge', slotProps.data.id)">Print</Button>
                    <Button severity="secondary" v-if="slotProps.data.status !== 'pending'" @click="emit('printBadge', slotProps.data.id)">Reprint</Button>

                    <Button v-if="slotProps.data.status === 'ready_for_pickup'" @click="changeHandout(slotProps.data.id, false)">Handout</Button>
                    <Button severity="warning" v-if="slotProps.data.status === 'picked_up'" @click="changeHandout(slotProps.data.id, true)">Undo Handout</Button>
                </div>
            </template>
        </Column>
    </DataTable>
</template>

<style>

</style>

