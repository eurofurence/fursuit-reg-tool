<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import FursuitPhoto from '@/Components/CatchEmAll/FursuitPhoto.vue'
import { BookOpen, Crown, Gem, LayoutGrid, List, Sparkles, Star } from 'lucide-vue-next'

type Suit = {
    species: string
    count: number
    profileUuid: string | null
    rarity: { level: string; label: string; color: string; icon: string }
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

/* rarest first, matching FursuitRarity::ranked() */
const RARITIES = [
    { key: 'legendary', label: 'Legendary', icon: Crown },
    { key: 'epic', label: 'Epic', icon: Gem },
    { key: 'rare', label: 'Rare', icon: Sparkles },
    { key: 'uncommon', label: 'Uncommon', icon: Star },
    { key: 'common', label: 'Common', icon: BookOpen },
]

const view = ref<'grid' | 'list'>('grid')
const rarity = ref<string>('all')
const openSpecies = ref<string | null>(null)

const suits = computed(() => props.collection?.suits ?? [])
const speciesCount = computed(() => Object.keys(props.collection?.species ?? {}).length)
const total = computed(() => props.eventTotal ?? 0)
const percent = computed(() =>
    total.value ? Math.round((suits.value.length / total.value) * 1000) / 10 : 0)

const tally = computed(() => RARITIES.map(entry => ({
    ...entry,
    colour: suits.value.find(s => s.rarity.level === entry.key)?.rarity.color ?? colourFor(entry.key),
    n: suits.value.filter(s => s.rarity.level === entry.key).length,
})))

/* fallbacks so an empty tier still shows its own colour */
function colourFor(level: string) {
    return {
        legendary: '#e0a020', epic: '#a35fd6', rare: '#3f8fe0',
        uncommon: '#46b06a', common: '#7d90a6',
    }[level] ?? '#7d90a6'
}

const shown = computed(() =>
    rarity.value === 'all' ? suits.value : suits.value.filter(s => s.rarity.level === rarity.value))

/* list view is a species view: one row per species with how many you met,
   because a heading per species with one row under it is twice the rows */
const bySpecies = computed(() => {
    const groups: Record<string, { species: string; colour: string; label: string; population: number; suits: Suit[] }> = {}
    for (const suit of shown.value) {
        groups[suit.species] ??= {
            species: suit.species,
            colour: suit.rarity.color,
            label: suit.rarity.label,
            population: suit.count,
            suits: [],
        }
        groups[suit.species].suits.push(suit)
    }
    return Object.values(groups).sort((a, b) => b.suits.length - a.suits.length)
})

const blanks = computed(() => Math.max(12 - shown.value.length, 3))
const hue = computed(() =>
    rarity.value === 'all' ? (suits.value[0]?.rarity.color ?? null) : colourFor(rarity.value))

function toggleRarity(key: string) {
    rarity.value = rarity.value === key ? 'all' : key
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
        :flash="flash"
    >
        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 14px">
            <select v-model="rarity" class="cea-select">
                <option value="all">All rarities</option>
                <option v-for="entry in RARITIES" :key="entry.key" :value="entry.key">{{ entry.label }}</option>
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

        <div class="cea-rarity">
            <button
                v-for="entry in tally"
                :key="entry.key"
                class="cea-stat"
                :class="{ on: rarity === entry.key }"
                :style="{ '--cea-tone': entry.colour }"
                @click="toggleRarity(entry.key)"
            >
                <component :is="entry.icon" :size="16" />
                <b>{{ entry.n }}</b>
                <small>{{ entry.label }}</small>
            </button>
        </div>

        <template v-if="view === 'grid'">
            <div class="cea-tiles">
                <button
                    v-for="suit in shown"
                    :key="suit.gallery.id"
                    class="cea-tile"
                    :style="{ '--cea-tone': suit.rarity.color }"
                    :title="`${suit.gallery.name} · ${suit.rarity.label}`"
                    @click="openProfile(suit)"
                >
                    <FursuitPhoto :src="suit.gallery.image" :name="suit.gallery.name" :tone="suit.rarity.color" />
                    <span v-if="(collection.species[suit.species] ?? 0) > 1" class="count">
                        {{ collection.species[suit.species] }}
                    </span>
                </button>
                <span v-for="n in blanks" :key="`blank-${n}`" class="cea-tile blank" />
            </div>
            <p class="cea-hint" style="margin-top: 14px">
                Tap a sticker for the player behind it. Dashed slots are people still out there.
            </p>
        </template>

        <template v-else>
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
            <p v-if="!bySpecies.length" class="cea-hint">Nothing at that rarity yet.</p>
        </template>
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
</style>
