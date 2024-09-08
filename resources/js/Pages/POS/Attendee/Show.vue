<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";

import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import BadgesTable from "@/Components/POS/Attendee/BadgesTable.vue";
import FursuitTable from "@/Components/POS/Attendee/FursuitTable.vue";
import DashboardButton from "@/Components/POS/DashboardButton.vue";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    fursuits: Array,
    attendee: Object,
});

const selectedBadges = ref();

</script>

<template>
    <div class="grid grid-cols-2 gap-4 p-4">
        <div>
            <div class="bg-white p-4 mb-4 rounded-lg shadow">
                <h1 class="text-2xl font-bold">{{ attendee.name }} # {{ attendee.attendee_id }}</h1>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <DashboardButton label="Pay" icon="pi pi-money-bill" route="#"></DashboardButton>
                <DashboardButton label="Handout" icon="pi pi-th-large" route="#"></DashboardButton>
            </div>
        </div>
        <div>
            <TabView>
                <TabPanel header="Badges">
                  <BadgesTable :badges="badges" :attendee="attendee" @update:selected-badges="args => selectedBadges.value = args" />
                </TabPanel>
                <TabPanel header="Fursuit">
                   <FursuitTable :fursuits="fursuits" :attendee="attendee" />
                </TabPanel>
                <TabPanel header="Transactions">
                           <p class="m-0">
                        At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident, similique sunt in culpa qui
                        officia deserunt mollitia animi, id est laborum et dolorum fuga. Et harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus.
                    </p>
                </TabPanel>
            </TabView>
        </div>
    </div>
</template>
