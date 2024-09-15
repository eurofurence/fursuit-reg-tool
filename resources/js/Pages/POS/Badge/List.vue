<script setup>
import { Head, Link } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Button from "primevue/button";
import Dropdown from "primevue/dropdown";
import Tag from "primevue/tag";
import IconField from "primevue/iconfield";
import InputIcon from "primevue/inputicon";
import InputText from "primevue/inputtext";
import { FilterMatchMode } from 'primevue/api';

import { ref, onMounted } from 'vue';

const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    status: { value: null, matchMode: FilterMatchMode.EQUALS },
    'fursuit.status': { value: null, matchMode: FilterMatchMode.EQUALS },
});

defineOptions({
    layout: POSLayout,
});

defineProps({
    badges: Array
});

const statuses = ref(['pending', 'picked_up', 'ready_for_pickup', 'negotiation', 'renewal', 'proposal']);
const getSeverity = (status) => {
    switch (status) {
        case 'pending':
            return 'danger';

        case 'picked_up':
            return 'success';

        case 'ready_for_pickup':
            return 'info';

        case 'negotiation':
            return 'warning';

        case 'renewal':
            return null;
    }
}

</script>

<template>
    <div class="flex-grow w-full p-4 flex flex-col gap-4">
        <DataTable dataKey="id"  v-model:filters="filters" filterDisplay="row" :value="badges" :globalFilterFields="['fursuit.user.attendee_id','fursuit.user.name', 'fursuit.name']">
            <template #header>
                <div class="flex justify-content-end">
                    <IconField iconPosition="left">
                        <InputIcon>
                            <i class="pi pi-search" />
                        </InputIcon>
                        <InputText v-model="filters['global'].value" placeholder="Keyword Search" />
                    </IconField>
                </div>
            </template>
            <Column field="fursuit.user.attendee_id" header="Attendee ID" />
            <Column field="fursuit.user" header="Attendee">
                <template #body="slotProps">
                    <Link 
                        :href="route('pos.attendee.show', { attendeeId: slotProps.data.fursuit.user.attendee_id })"
                    >
                        <Button severity="help" icon="pi pi-external-link" :label="slotProps.data.fursuit.user.name" />
                    </Link>
                </template>
            </Column>
            <Column field="fursuit.name" header="Fursuit"></Column>
            <Column field="fursuit.status" header="Fursuit Status" :showFilterMenu="false">
                <template #body="{ data }">
                    <Tag :value="data.fursuit.status" :severity="getSeverity(data.fursuit.status)" /> 
                </template>
                <template #filter="{ filterModel, filterCallback }">
                    <Dropdown v-model="filterModel.value" @change="filterCallback()" :options="statuses" placeholder="Select One" class="p-column-filter" style="min-width: 12rem" :showClear="true">
                        <template #option="slotProps">
                            <Tag :value="slotProps.option" :severity="getSeverity(slotProps.option)" />
                        </template>
                    </Dropdown>
                </template>
            </Column>
            <Column field="status" header="Badge Status" :showFilterMenu="false">
                <template #body="{ data }">
                    <Tag :value="data.status" :severity="getSeverity(data.status)" /> 
                </template>
                <template #filter="{ filterModel, filterCallback }">
                    <Dropdown v-model="filterModel.value" @change="filterCallback()" :options="statuses" placeholder="Select One" class="p-column-filter" style="min-width: 12rem" :showClear="true">
                        <template #option="slotProps">
                            <Tag :value="slotProps.option" :severity="getSeverity(slotProps.option)" />
                        </template>
                    </Dropdown>
                </template>
            </Column>
            <Column header="Action">

            </Column>
        </DataTable>
    </div>
</template>
