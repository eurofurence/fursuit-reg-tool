<script setup lang="ts">
import { computed } from 'vue'
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
    flash?: any
}>()

const mine = computed(() => props.leaderboard.find(row => row.user_id === props.user.id) ?? null)
const total = computed(() => mine.value?.catches ?? 0)

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
