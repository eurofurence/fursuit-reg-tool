<script setup>

import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Checkbox from "primevue/checkbox";
import dayjs from "dayjs";
import {ref} from "vue";
import Avatar from "primevue/avatar";

defineProps({
    attendee: Object,
    transactions: Array
})
</script>

<template>
    <DataTable dataKey="id" :value="transactions" scrollable scrollHeight="400px" class="-m-5" tableStyle="min-width: 50rem">
        <Column field="created_at" header="Date">
            <template #body="slotProps">
                {{ (slotProps.data.created_at) ? dayjs(slotProps.data.created_at).format('DD.MM.YY HH:mm') : '-' }}
            </template>
        </Column>
        <Column field="meta.title" header="Title" />
        <Column field="meta.description" header="Description" />
        <Column field="amount" header="Amount">
            <template #body="slotProps">
                <span v-if="parseInt(slotProps.data.amount) > 0" class="text-green-600">
                    {{ (parseInt(slotProps.data.amount) / 100.0).toFixed(2) }} €
                </span>
                <span v-else-if="parseInt(slotProps.data.amount) < 0" class="text-red-600">
                    {{ (parseInt(slotProps.data.amount) / 100.0).toFixed(2) }} €
                </span>
                <span v-else>
                    {{ (parseInt(slotProps.data.amount) / 100.0).toFixed(2) }} €
                </span>
            </template>
        </Column>
    </DataTable>
</template>

