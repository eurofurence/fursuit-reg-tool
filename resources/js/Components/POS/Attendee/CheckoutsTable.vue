<script setup>

import Column from "primevue/column";
import DataTable from "primevue/datatable";
import dayjs from "dayjs";
import Chip from 'primevue/chip';

defineProps({
    checkouts: Array
})
</script>

<template>
    <DataTable dataKey="id" :value="checkouts" scrollable scrollHeight="400px" class="-m-5" tableStyle="min-width: 50rem">
        <Column field="created_at" header="Date">
            <template #body="slotProps">
                {{ (slotProps.data.created_at) ? dayjs(slotProps.data.created_at).format('DD.MM.YY HH:mm') : '-' }}
            </template>
        </Column>
        <Column field="status" header="Status" />
        <Column field="payment_method" header="Method" />
        <Column field="items" header="Items" class="flex flex-wrap gap-2">
            <template #body="slotProps">
                <Chip v-for="item in slotProps.data.items" :label="item.name" />
            </template>
        </Column>
        <Column field="total" header="Total">
            <template #body="slotProps">
                {{ (parseInt(slotProps.data.total) / 100.0).toFixed(2) }} €
            </template>
        </Column>
    </DataTable>
</template>

