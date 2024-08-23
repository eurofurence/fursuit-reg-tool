<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";

import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import BadgesTable from "@/Components/POS/Attendee/BadgesTable.vue";
import FursuitTable from "@/Components/POS/Attendee/FursuitTable.vue";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    fursuits: Array,
    attendee: Object,
});

// const badges = [
//     {
//         'updated_at': new Date(),
//         'fursuit_id': 1,
//
//
//     }
// ]
</script>

<template>
    <div class="p-8">
        <TabView>
            <TabPanel header="Badges">
              <BadgesTable :badges="badges" :attendee="attendee" />
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
</template>
