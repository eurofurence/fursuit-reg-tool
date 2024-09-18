<script setup>

import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Checkbox from "primevue/checkbox";
import {ref} from "vue";
import Avatar from "primevue/avatar";

defineProps({
    attendee: Object,
    fursuits: Array
})
</script>

<template>
    <DataTable dataKey="id" :value="fursuits" scrollable scrollHeight="400px" class="-m-5" tableStyle="min-width: 50rem">
        <Column field="image_url" header="Icon">
            <template #body="slotProps">
                <Avatar :image="slotProps.data.image_url" size="large" :pt="{ image: { class: 'object-contain rounded-lg' }}" />
            </template>
        </Column>
        <Column field="name" header="Name" />
        <Column field="status" header="Status" />
        <Column field="species.name" header="Species" />
        <Column field="catch_em_all" header="Catch em all">
            <template #body="slotProps">
                <Checkbox :modelValue="slotProps.data.catch_em_all" :binary="true" />
            </template>
        </Column>
    </DataTable>
</template>

