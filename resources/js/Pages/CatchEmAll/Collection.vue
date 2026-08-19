<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import FursuitPhoto from '@/Components/CatchEmAll/FursuitPhoto.vue'
import { Circle, Gem, LayoutGrid, List, Medal, Sparkles, Star } from 'lucide-vue-next'

type Suit = {
    species: string
    count: number
    caught: number
    profileUuid: string | null
    ranking: { level: string; label: string; color: string; icon: string }
    gallery: {
        id: number
        name: string
        species: string
        image: string | null
        owner?: string | null
        profileUuid?: string | null
    }
}

const props = defineProps<{
    collection: { suits: Suit[]; species: Record<string, number>; totalCatches: number }
    eventsWithEntries?: any[]
    selectedEvent?: number | null
    isGlobal?: boolean
    eventTotal?: number
    flash?: any
}>()

/* highest first, matching FursuitRanking::ranked() */
const RANKINGS = [
    { key: 'legend', label: 'Legend', icon: '/icons/cea/paw_icon_rank_5.svg' },
    { key: 'extraordinaire', label: 'Extraordinaire', icon: '/icons/cea/paw_icon_rank_4.svg' },
    { key: 'fluffy', label: 'Fluffy', icon: '/icons/cea/paw_icon_rank_3.svg' },
    { key: 'regular', label: 'Regular', icon: '/icons/cea/paw_icon_rank_2.svg' },
    { key: 'novice', label: 'Novice', icon: '/icons/cea/paw_icon_rank_1.svg' },
]

const view = ref<'grid' | 'list'>('grid')
const ranking = ref<string>('all')
const openSpecies = ref<string | null>(null)

const suits = computed(() => props.collection?.suits ?? [])
const speciesCount = computed(() => Object.keys(props.collection?.species ?? {}).length)
const total = computed(() => props.eventTotal ?? 0)
const percent = computed(() =>
    total.value ? Math.round((suits.value.length / total.value) * 1000) / 10 : 0)

const tally = computed(() => RANKINGS.map(entry => ({
    ...entry,
    colour: suits.value.find(s => s.ranking.level === entry.key)?.ranking.color ?? colourFor(entry.key),
    n: suits.value.filter(s => s.ranking.level === entry.key).length,
})))

/* fallbacks so an empty tier still shows its own colour */
function colourFor(level: string) {
    return {
        legend: '#c3aef5', extraordinaire: '#d9a520', fluffy: '#b9c4cf',
        regular: '#cf8b52', novice: '#6f9fd8',
    }[level] ?? '#6f9fd8'
}

const shown = computed(() =>
    (ranking.value === 'all' ? suits.value : suits.value.filter(s => s.ranking.level === ranking.value)).sort((a, b) => b.caught - a.caught || a.gallery.name.localeCompare(b.gallery.name)))

/* list view is a species view: one row per species with how many you met,
   because a heading per species with one row under it is twice the rows */
const bySpecies = computed(() => {
    const groups: Record<string, { species: string; colour: string; label: string; population: number; suits: Suit[] }> = {}
    for (const suit of shown.value) {
        groups[suit.species] ??= {
            species: suit.species,
            colour: suit.ranking.color,
            label: suit.ranking.label,
            population: suit.count,
            suits: [],
        }
        groups[suit.species].suits.push(suit)
    }
    return Object.values(groups).sort((a, b) => b.suits.length - a.suits.length)
})

const blanks = computed(() => Math.max(12 - shown.value.length, 3))
const hue = computed(() =>
    ranking.value === 'all' ? (suits.value[0]?.ranking.color ?? null) : colourFor(ranking.value))

function toggleRanking(key: string) {
    ranking.value = ranking.value === key ? 'all' : key
}

function openProfile(suit: Suit) {
    const uuid = suit.profileUuid ?? suit.gallery.profileUuid
    if (!uuid) return
    router.visit(route('catch-em-all.profiles.show', uuid) + `?from=${suit.gallery.id}`)
}
</script>

<template>
    <CatchEmAllLayout
        title="Collection"
        :subtitle="`${suits.length} of ${total.toLocaleString('en')} badges at EF30`"
        :count="collection?.totalCatches ?? 0"
        :hue="hue"
        :isEventActive="true"
        :flash="flash"
    >
        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 14px">
            <select v-model="ranking" class="cea-select">
                <option value="all">All rankings</option>
                <option v-for="entry in RANKINGS" :key="entry.key" :value="entry.key">{{ entry.label }}</option>
            </select>
            <div class="cea-seg">
                <button :class="{ on: view === 'grid' }" title="Grid" aria-label="Grid" @click="view = 'grid'">
                    <LayoutGrid :size="17" />
                </button>
                <button :class="{ on: view === 'list' }" title="List" aria-label="List" @click="view = 'list'">
                    <List :size="17" />
                </button>
            </div>
        </div>

        <div class="cea-headline">
            <div class="num"><b>{{ suits.length }}</b><span>caught</span></div>
            <div class="meta">
                <div class="cea-bar"><i :style="{ width: `${Math.max(percent, 1.5)}%` }" /></div>
                <small>{{ percent }}% of {{ total.toLocaleString('en') }} badges · {{ speciesCount }} species</small>
            </div>
        </div>

        <div class="cea-ranking">
            <button
                v-for="entry in tally"
                :key="entry.key"
                class="cea-stat flex items-center justify-center gap-1 flex-col"
                :class="{ on: ranking === entry.key }"
                :style="{
                    '--cea-tone': entry.colour,
                    '--cea-icon': `url('${entry.icon}')`,
                }"
                @click="toggleRanking(entry.key)"
            >
                <span
                    class="rank-icon"
                    role="img"
                    :aria-label="entry.label"
                />
                <b>{{ entry.n }}</b>
                <!--<small>{{ entry.label }}</small>-->
            </button>
        </div>

        <template v-if="view === 'grid'">
            <div class="cea-tiles">
                <button
                    v-for="suit in shown"
                    :key="suit.gallery.id"
                    class="cea-tile"
                    :style="{ '--cea-tone': suit.ranking.color }"
                    :title="`${suit.gallery.name} · ${suit.ranking.label}`"
                    @click="openProfile(suit)"
                >
                    <FursuitPhoto :src="suit.gallery.image" :name="suit.gallery.name" :tone="suit.ranking.color" />
                    <span v-if="(suit.caught ?? 0) > 1" class="count">
                        {{ suit.caught }}
                    </span>
                </button>
                <span v-for="n in blanks" :key="`blank-${n}`" class="cea-tile blank" />
            </div>
            <p class="cea-hint" style="margin-top: 14px">
                Tap a sticker for the player behind it. Dashed slots are people still out there.
            </p>
        </template>

        <template v-else>
            <div class="cea-two">
            <div
                v-for="group in bySpecies"
                :key="group.species"
                class="cea-species"
                :style="{ '--cea-tone': group.colour }"
                @click="openSpecies = openSpecies === group.species ? null : group.species"
            >
                <div class="cea-catchrow" style="margin: 0; border: 0; border-radius: 0; background: none">
                    <span class="thumb">
                        <FursuitPhoto
                            :src="group.suits[0].gallery.image"
                            :name="group.suits[0].gallery.name"
                            :tone="group.colour"
                        />
                    </span>
                    <span class="who">
                        <b>{{ group.species }}</b>
                        <small>{{ group.suits.length }} caught · {{ group.population }} at EF30</small>
                    </span>
                    <span class="cea-rlabel" :style="{ color: group.colour }">{{ group.label }}</span>
                </div>
                <div v-if="openSpecies === group.species" class="subrows">
                    <button
                        v-for="suit in group.suits"
                        :key="suit.gallery.id"
                        class="cea-subrow"
                        @click.stop="openProfile(suit)"
                    >
                        <span>{{ suit.gallery.name }}</span>
                        <small>{{ suit.gallery.owner ?? 'unknown owner' }}</small>
                    </button>
                </div>
            </div>
            </div>
            <p v-if="!bySpecies.length" class="cea-hint">Nothing at that ranking yet.</p>
        </template>
        <p class="cea-hint" style="margin-top: 14px">Icons by Nighty &gt;:3</p>
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
.cea-select:focus { border-color: var(--cea-accent-hi); }
.cea-seg {
    display: flex;
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
    padding: 7px 12px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
}
.cea-seg button.on { background: var(--cea-accent); color: #fff; }
.rank-icon {
    width: 3.2rem;
    height: 3.2rem;
    display: block;
    background-color: var(--cea-tone);
    mask-image: var(--cea-icon);
    mask-position: center;
    mask-repeat: no-repeat;
    mask-size: contain;
    -webkit-mask-image: var(--cea-icon);
    -webkit-mask-position: center;
    -webkit-mask-repeat: no-repeat;
    -webkit-mask-size: contain;
}
</style>
