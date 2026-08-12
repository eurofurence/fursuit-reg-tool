<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import { Check, Flag, Lock } from 'lucide-vue-next'

type Achievement = {
    id: number
    title: string
    description: string
    task: string | null
    completed: boolean
    progress: number
    maxProgress: number
    progressPercentage: number
    earnedAt?: string | null
    isOptional: boolean
    isLocked: boolean
    hiddenByLock: boolean
    progressDetail?: { totalProgress: string[]; currentProgress: string[] } | null
}

const props = defineProps<{ achievements: Achievement[]; flash?: any }>()

const SEEN_KEY = 'cea:achievements:earned:v2'

const filter = ref<'all' | 'earned' | 'progress'>('all')
const fresh = ref<number[]>([])

/* what was already earned last visit, so a new one can be marked */
onMounted(() => {
    const earned = props.achievements.filter(a => a.completed).map(a => a.id)
    try {
        const before: number[] = JSON.parse(localStorage.getItem(SEEN_KEY) ?? '[]')
        fresh.value = earned.filter(id => !before.includes(id))
        localStorage.setItem(SEEN_KEY, JSON.stringify(earned))
    } catch {
        // private mode, or a corrupt value: the marker is a nicety, not a feature
    }
})

/**
 * Tier by how much work an achievement is, so the list reads at a glance.
 * The optional ones are the staff and team codes, which get their own colour.
 */
function tier(a: Achievement) {
    if (a.isOptional) return 'var(--cea-tier-team)'
    if (a.maxProgress >= 50) return 'var(--cea-tier-3)'
    if (a.maxProgress >= 10) return 'var(--cea-tier-2)'
    return 'var(--cea-tier-1)'
}

const earnedCount = computed(() => props.achievements.filter(a => a.completed).length)
const shown = computed(() => props.achievements.filter(a =>
    filter.value === 'all'
    || (filter.value === 'earned' && a.completed)
    || (filter.value === 'progress' && !a.completed)))

function earnedOn(a: Achievement) {
    if (!a.earnedAt) return 'Earned'
    return `Earned ${new Date(a.earnedAt).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}`
}
</script>

<template>
    <CatchEmAllLayout
        title="Achievements"
        :subtitle="`${earnedCount} of ${achievements.length} earned`"
        :count="null"
        hue="var(--cea-tier-2)"
        :flash="flash"
    >
        <div class="cea-seg" style="margin-bottom: 14px">
            <button :class="{ on: filter === 'all' }" @click="filter = 'all'">All</button>
            <button :class="{ on: filter === 'earned' }" @click="filter = 'earned'">Earned</button>
            <button :class="{ on: filter === 'progress' }" @click="filter = 'progress'">In progress</button>
        </div>

        <div
            v-for="item in shown"
            :key="item.id"
            class="cea-ach"
            :class="{ earned: item.completed }"
            :style="{ '--cea-tone': tier(item) }"
        >
            <span class="disc" :class="{ locked: !item.completed }">
                <Check v-if="item.completed" :size="20" />
                <Lock v-else-if="item.isLocked" :size="20" />
                <Flag v-else :size="20" />
            </span>
            <div class="body">
                <b>{{ item.hiddenByLock ? 'Hidden' : item.title }}</b>
                <span v-if="fresh.includes(item.id)" class="cea-new" style="margin-left: 8px">new</span>
                <small v-if="item.completed">{{ earnedOn(item) }}</small>
                <small v-else-if="item.isLocked">Locked until you finish the one before it</small>
                <small v-else>{{ item.progress }} of {{ item.maxProgress }}</small>

                <div v-if="!item.completed && !item.isLocked" class="cea-bar" style="margin-top: 9px">
                    <i :style="{ width: `${item.progressPercentage}%`, background: tier(item) }" />
                </div>

                <p v-if="!item.hiddenByLock" class="desc">{{ item.description }}</p>
                <p v-if="item.progressDetail?.totalProgress?.length" class="desc" style="opacity: .8">
                    {{ item.progressDetail.currentProgress.length }} of
                    {{ item.progressDetail.totalProgress.length }}:
                    {{ item.progressDetail.currentProgress.join(', ') || 'nothing yet' }}
                </p>
            </div>
        </div>

        <p v-if="!shown.length" class="cea-hint">Nothing here yet.</p>
    </CatchEmAllLayout>
</template>

<style scoped>
.cea-seg {
    display: inline-flex;
    gap: 3px;
    padding: 3px;
    background: var(--cea-panel-2);
    border: 1px solid var(--cea-line-soft);
    border-radius: 10px;
}
.cea-seg button {
    border: 0;
    background: none;
    color: var(--cea-muted);
    font-weight: 600;
    font-size: 12.5px;
    padding: 7px 11px;
    border-radius: 8px;
    cursor: pointer;
}
.cea-seg button.on { background: var(--cea-accent); color: #fff; }
</style>
