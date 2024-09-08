<script setup>

import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Button from "primevue/button";
import dayjs from "dayjs";
import {ref} from "vue";

defineProps({
    attendee: Object,
    badges: Array
})

const selectedBadges = ref();
</script>

<template>
<!--    {{ badges }}-->
    <DataTable dataKey="id" v-model:selection="selectedBadges" :value="badges" scrollable scrollHeight="400px" class="-m-5" tableStyle="min-width: 50rem">
<!--        <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>-->
        <Column field="updated_at" header="Date">
            <template #body="slotProps">
                {{ dayjs(slotProps.data.updated_at).format('DD.MM.YY H:mm') }}
            </template>
        </Column>
        <Column field="fursuit.name" header="Fursuit">

        </Column>
        <Column field="printed_at" header="Print">
            <template #body="slotProps">
                {{ slotProps.data.printed_at || 'not yet' }}
            </template>
        </Column>
        <Column field="status" header="Paid"></Column>
        <Column header="Actions">
            <template #body="slotProps">
                <Button size="small">Print</Button>
            </template>
        </Column>
    </DataTable>
</template>

