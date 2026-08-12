<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import FursuitPhoto from '@/Components/CatchEmAll/FursuitPhoto.vue'
import BottomSheet from '@/Components/CatchEmAll/BottomSheet.vue'

type Catch = {
    id: number
    fursuitId: number
    name: string
    species: string | null
    owner: string | null
    image: string | null
    caughtAt: string | null
    profileUuid: string | null
    rarity: { level: string; label: string; color: string }
}

const props = defineProps<{
    recentCatch?: any | null
    flash?: any
    isGameRunning: boolean
    code: string | ''
    autoCatch: boolean
    recent: Catch[]
    caughtTotal: number
    eventTotal: number
}>()

const form = useForm({ catch_code: (props.code ?? '').toUpperCase() })

/* The sheet is driven by the flashed catch, and closing it must not reopen on
   the next visit, so the dismissed id is remembered. */
const dismissed = ref<number | null>(null)
const sheetOpen = computed(() => !!props.recentCatch && dismissed.value !== props.recentCatch?.id)
watch(() => props.recentCatch?.id, () => (dismissed.value = null))

const field = ref<HTMLInputElement | null>(null)
onMounted(() => {
    if (props.autoCatch && props.code) submit()
    else if (props.isGameRunning) field.value?.focus()
})

function onInput(event: Event) {
    const input = event.target as HTMLInputElement
    const clean = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5)
    form.catch_code = clean
    input.value = clean
}

function submit() {
    if (form.processing || form.catch_code.length < 5) return
    form.transform(data => ({ ...data, catch_code: data.catch_code.toUpperCase() }))
        .post(route('catch-em-all.catch.submit'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        })
}

/* The colour of the screen follows the last thing you caught. */
const hue = computed(() => props.recent[0]?.rarity?.color ?? null)

function openProfile(entry: Catch) {
    if (!entry.profileUuid) return
    router.visit(route('catch-em-all.profiles.show', entry.profileUuid) + `?from=${entry.fursuitId}`)
}
</script>

<template>
    <CatchEmAllLayout
        title="Catch 'Em All"
        :subtitle="isGameRunning ? 'Eurofurence 30' : 'Game closed'"
        :count="caughtTotal"
        :hue="hue"
        :flash="flash"
    >
        <div v-if="!isGameRunning" class="cea-note warn" style="margin-bottom: 14px">
            The game is closed right now. Codes work again once it reopens.
        </div>

        <div v-else class="cea-card">
            <div class="cea-hint" style="letter-spacing: .16em; text-transform: uppercase; font-weight: 700; font-size: 11px; margin-bottom: 12px">
                Badge code
            </div>
            <input
                ref="field"
                class="cea-field"
                :value="form.catch_code"
                maxlength="5"
                placeholder="ABCDE"
                inputmode="text"
                autocomplete="off"
                autocorrect="off"
                autocapitalize="characters"
                spellcheck="false"
                @input="onInput"
                @keydown.enter="submit"
            />
            <button
                class="cea-btn"
                style="margin-top: 14px"
                :disabled="form.catch_code.length < 5 || form.processing"
                @click="submit"
            >
                {{ form.processing ? 'Catching' : 'Catch' }}
            </button>
            <p class="cea-hint" style="margin-top: 12px">
                Five characters, printed under the name on their badge.
            </p>
        </div>

        <template v-if="recent.length">
            <h3 class="cea-sec">
                <span><b class="cea-tick" />Latest</span>
                <span class="meta">{{ caughtTotal }} of {{ eventTotal.toLocaleString('en') }}</span>
            </h3>
            <div class="cea-tiles">
                <button
                    v-for="entry in recent.slice(0, 6)"
                    :key="entry.id"
                    class="cea-tile"
                    :style="{ '--cea-tone': entry.rarity.color }"
                    :title="`${entry.name} · ${entry.rarity.label}`"
                    @click="openProfile(entry)"
                >
                    <FursuitPhoto :src="entry.image" :name="entry.name" :tone="entry.rarity.color" />
                </button>
            </div>

            <h3 class="cea-sec"><span><b class="cea-tick" />Today</span></h3>
            <button
                v-for="entry in recent.slice(0, 6)"
                :key="`row-${entry.id}`"
                class="cea-catchrow"
                :style="{ '--cea-tone': entry.rarity.color }"
                @click="openProfile(entry)"
            >
                <span class="thumb"><FursuitPhoto :src="entry.image" :name="entry.name" :tone="entry.rarity.color" /></span>
                <span class="who">
                    <b>{{ entry.name }}</b>
                    <small>{{ entry.species }}<template v-if="entry.owner"> · {{ entry.owner }}</template></small>
                </span>
                <span class="cea-hint">{{ entry.caughtAt }}</span>
            </button>
        </template>

        <p v-else-if="isGameRunning" class="cea-hint" style="margin-top: 22px">
            Nothing caught yet. Ask a suiter for the code under the name on their badge.
        </p>

        <BottomSheet :open="sheetOpen" @close="dismissed = recentCatch?.id ?? null">
            <template v-if="recentCatch">
                <div class="art">
                    <FursuitPhoto
                        :src="recentCatch.image"
                        :name="recentCatch.name"
                        :tone="recentCatch.rarity?.color"
                    />
                </div>
                <div class="nm">{{ recentCatch.name }}</div>
                <div class="mt">
                    {{ recentCatch.species }}<template v-if="recentCatch.user"> · badge by {{ recentCatch.user }}</template>
                </div>
                <div>
                    <span class="cea-kind" :style="{ background: recentCatch.rarity?.color }">
                        {{ recentCatch.rarity?.label }}
                    </span>
                </div>
                <div class="cea-stats" style="margin-top: 18px">
                    <div class="cea-stat" style="--cea-tone: var(--cea-accent-bright)">
                        <b>{{ caughtTotal }}</b><small>caught</small>
                    </div>
                    <div class="cea-stat" :style="{ '--cea-tone': recentCatch.rarity?.color }">
                        <b>{{ recentCatch.speciesCount ?? 1 }}</b><small>at the event</small>
                    </div>
                    <div class="cea-stat" style="--cea-tone: var(--cea-gold)">
                        <b>{{ Math.round((caughtTotal / Math.max(eventTotal, 1)) * 1000) / 10 }}%</b><small>of event</small>
                    </div>
                </div>
                <button class="cea-btn" style="margin-top: 18px" @click="dismissed = recentCatch?.id ?? null">
                    Next code
                </button>
            </template>
        </BottomSheet>
    </CatchEmAllLayout>
</template>
