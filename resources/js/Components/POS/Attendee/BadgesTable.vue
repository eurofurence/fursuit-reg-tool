<script setup>

import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Button from "primevue/button";
import dayjs from "dayjs";
import {ref, watchEffect} from "vue";
import Checkbox from "primevue/checkbox";

defineProps({
    attendee: Object,
    badges: Array
})

const emit = defineEmits(['update:selectedBadges', 'printBadge']);

const selectedBadges = ref();

/** Emit update everytime the selectedBadges change */
watchEffect(() => {
    emit('update:selectedBadges', selectedBadges.value);
});
</script>

<template>
<!--    {{ badges }}-->
    <DataTable dataKey="id" v-model:selection="selectedBadges" :value="badges" scrollable scrollHeight="400px" class="-m-5" tableStyle="min-width: 50rem">
        <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
        <Column field="updated_at" header="Date">
            <template #body="slotProps">
                {{ dayjs(slotProps.data.updated_at).format('DD.MM.YY H:mm') }}
            </template>
        </Column>
        <Column field="fursuit.name" header="Fursuit"></Column>
        <Column field="printed_at" header="Print">
            <template #body="slotProps">
                {{ dayjs(slotProps.data.printed_at).format('DD.MM.YY') || 'not yet' }}
            </template>
        </Column>
        <Column field="dual_side_print" header="Duplex">
            <template #body="slotProps">
                <Checkbox :modelValue="slotProps.data.dual_side_print" :binary="true" />
            </template>
        </Column>
        <Column field="status" header="Paid"></Column>
        <Column header="Actions">
            <template #body="slotProps">
                <Button size="large" @click="emit('printBadge', slotProps.data.id)">Print</Button>
            </template>
        </Column>
    </DataTable>
</template>

