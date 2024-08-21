<script setup>
import POSLayout from "@/Layouts/POSLayout.vue";

import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    badges: Array,
    attendee: Object
});
</script>

<template>
    <div class="p-8">
        <TabView>
            <TabPanel header="Badges">
                <DataTable :value="badges" scrollable scrollHeight="400px" tableStyle="min-width: 50rem">
                    <Column field="updated_at" header="Date"></Column>
                    <Column field="fursuit_id" header="Fursuit"></Column>
                    <Column field="printed_at" header="Print"></Column>
                    <Column field="total" header="Paid"></Column>
                    <Column field="picked_up_at" header="Pickup Location"></Column>
                    <Column header="Actions"></Column>
                </DataTable>
            </TabPanel>
            <TabPanel header="Fursuit">
                <p class="m-0">
                    Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim
                    ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Consectetur, adipisci velit, sed quia non numquam eius modi.
                </p>
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
