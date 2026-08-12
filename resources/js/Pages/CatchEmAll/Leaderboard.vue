<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import { Medal } from 'lucide-vue-next'

type Row = {
    event_user_id: number
    user_id: number
    name: string
    rank: number
    catches: number
    profile_uuid: string | null
}

const props = defineProps<{
    user: { id: number; name: string }
    leaderboard: Row[]
    eventsWithEntries: Array<{ id: number; name: string }>
    selectedEvent: number | null
    flash?: any
}>()

const event = ref<number | null>(props.selectedEvent)

const mine = computed(() => props.leaderboard.find(row => row.user_id === props.user.id) ?? null)
const total = computed(() => mine.value?.catches ?? 0)

function changeEvent() {
    router.get(route('catch-em-all.leaderboard'), { event: event.value }, {
        preserveState: true,
        preserveScroll: true,
    })
}

function openProfile(row: Row) {
    if (!row.profile_uuid) return
    router.visit(route('catch-em-all.profiles.show', row.profile_uuid))
}

/* gold, silver, copper for the first three; everyone else keeps their number */
const MEDALS = ['g', 's', 'b']
</script>

<template>
    <CatchEmAllLayout
        title="Leaderboard"
        subtitle="Most fursuits caught"
        :count="total"
        hue="var(--cea-gold)"
        :flash="flash"
    >
        <select
            v-if="eventsWithEntries?.length > 1"
            v-model="event"
            class="cea-select"
            style="margin-bottom: 14px"
            @change="changeEvent"
        >
            <option v-for="entry in eventsWithEntries" :key="entry.id" :value="entry.id">
                {{ entry.name }}
            </option>
        </select>

        <button
            v-for="row in leaderboard"
            :key="row.event_user_id"
            class="cea-row"
            :class="{ me: row.user_id === user.id }"
            :style="{ width: '100%', textAlign: 'left', cursor: row.profile_uuid ? 'pointer' : 'default' }"
            @click="openProfile(row)"
        >
            <span class="rank">
                <span v-if="row.rank <= 3" class="cea-medal" :class="MEDALS[row.rank - 1]">
                    <Medal :size="15" />
                </span>
                <template v-else>{{ row.rank }}</template>
            </span>
            <span class="who">
                <b>{{ row.name }}</b>
                <small v-if="row.user_id === user.id">you</small>
                <small v-else-if="!row.profile_uuid">no public profile</small>
                <small v-else>view profile</small>
            </span>
            <span class="n">{{ row.catches }}</span>
        </button>

        <p v-if="!leaderboard.length" class="cea-hint">Nobody has caught anything yet.</p>
        <p v-else class="cea-hint" style="margin-top: 14px">
            Ranked by fursuits caught. Players on the same score share a rank.
        </p>
    </CatchEmAllLayout>
</template>

<style scoped>
.cea-select {
    background: var(--cea-panel-2);
    border: 1px solid var(--cea-line-soft);
    color: var(--cea-ink);
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    padding: 9px 10px;
    outline: none;
}
</style>
