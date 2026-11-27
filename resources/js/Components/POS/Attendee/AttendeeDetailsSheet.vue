<script setup>
import UiDialog from '@/Components/UI/UiDialog.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/UI/tabs';
import { posDialog } from '@/Components/POS/posDialog.js';
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
    <UiDialog
        v-bind="posDialog"
        :visible="show"
        modal
        :header="`Details: ${attendee?.name || 'Attendee'}`"
        width="72rem"
        @update:visible="emit('close')"
    >
        <Tabs default-value="fursuits">
            <TabsList class="h-12">
                <TabsTrigger value="fursuits" class="px-4 text-base">Fursuits ({{ fursuits?.length || 0 }})</TabsTrigger>
                <TabsTrigger value="checkouts" class="px-4 text-base">Checkouts ({{ checkouts?.length || 0 }})</TabsTrigger>
            </TabsList>
            <TabsContent value="fursuits">
                <FursuitTable :fursuits="fursuits" :attendee="attendee" />
            </TabsContent>
            <TabsContent value="checkouts">
                <CheckoutsTable :checkouts="checkouts" />
            </TabsContent>
        </Tabs>

        <template #footer>
            <button type="button" class="pos-btn pos-btn--commit" @click="emit('close')">Close</button>
        </template>
    </UiDialog>
</template>
