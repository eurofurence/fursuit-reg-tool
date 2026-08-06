<script setup>
import Dialog from 'primevue/dialog';
import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import { posDialogPt } from '@/Components/POS/posDialog.js';
import FursuitTable from '@/Components/POS/Attendee/FursuitTable.vue';
import CheckoutsTable from '@/Components/POS/Attendee/CheckoutsTable.vue';

/*
 * Fursuits and past checkouts are look-up material: a desk needs them a few
 * times a shift, not on every attendee. Off the main screen they stop competing
 * with the badges, and the tables keep their dense spreadsheet layout, which is
 * the right shape for reading rather than tapping.
 */
defineProps({
    show: Boolean,
    attendee: Object,
    fursuits: Array,
    checkouts: Array,
});

const emit = defineEmits(['close']);
</script>

<template>
    <Dialog
        :visible="show"
        modal
        :header="`${attendee?.name || 'Attendee'} — details`"
        :style="{ width: '72rem' }"
        :pt="posDialogPt"
        @update:visible="emit('close')"
    >
        <TabView>
            <TabPanel :header="`Fursuits (${fursuits?.length || 0})`">
                <FursuitTable :fursuits="fursuits" :attendee="attendee" />
            </TabPanel>
            <TabPanel :header="`Checkouts (${checkouts?.length || 0})`">
                <CheckoutsTable :checkouts="checkouts" />
            </TabPanel>
        </TabView>

        <template #footer>
            <button type="button" class="pos-btn pos-btn--commit" @click="emit('close')">Close</button>
        </template>
    </Dialog>
</template>
