<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'
import FursuitPhoto from '@/Components/CatchEmAll/FursuitPhoto.vue'
import { Link as LinkIcon, Star, Trash2, X } from 'lucide-vue-next'

type Fursuit = {
    id: number
    name: string
    species: string | null
    image: string | null
    caught: number
    rank: number | null
    rarity: { level: string; label: string; color: string; icon: string }
}

type Achievement = {
    id: number
    title: string
    maxProgress: number
    isOptional: boolean
    earnedAt: string | null
}

const props = defineProps<{
    profile: {
        uuid: string
        name: string
        avatar: string | null
        description: string | null
        colour: string | null
        colourHex: string
        links: string[]
        status: string | null
        rejection_reason: string | null
    }
    fursuits: Fursuit[]
    stats: { caught: number; rank: number | null } | null
    achievements: Achievement[]
    palette: Record<string, string>
    fromFursuit: number | null
    canEdit: boolean
    flash?: any
}>()

const editing = ref(false)
const lightbox = ref<Fursuit | null>(null)

const form = useForm({
    description: props.profile.description ?? '',
    colour: props.profile.colour,
    links: [...props.profile.links],
})

/* every profile carries its own colour, picked at random when it is created */
const hue = computed(() => props.palette[form.colour ?? ''] ?? props.profile.colourHex)

const STATUS: Record<string, string> = {
    approved: 'Visible to other players',
    pending: 'Hidden until a reviewer approves it',
    rejected: 'Hidden, see the reason below',
}

function tier(a: Achievement) {
    if (a.isOptional) return 'var(--cea-tier-team)'
    if (a.maxProgress >= 50) return 'var(--cea-tier-3)'
    if (a.maxProgress >= 10) return 'var(--cea-tier-2)'
    return 'var(--cea-tier-1)'
}

function addLink() {
    if (form.links.length >= 10) return
    form.links.push('')
}

function save() {
    form.transform(data => ({ ...data, links: data.links.filter(url => url.trim() !== '') }))
        .put(route('catch-em-all.profiles.update', props.profile.uuid), {
            preserveScroll: true,
            onSuccess: () => (editing.value = false),
        })
}

const caughtHere = computed(() => props.fursuits.filter(f => f.caught > 0).length)
</script>

<template>
    <CatchEmAllLayout
        :title="canEdit ? 'Your profile' : profile.name"
        :subtitle="canEdit ? (STATUS[profile.status ?? 'approved'] ?? '') : 'Player profile'"
        :count="canEdit ? (stats?.caught ?? 0) : null"
        :hue="hue"
        :flash="flash"
    >
        <div class="cea-showcase" :style="{ background: hue }">
            <svg viewBox="0 0 390 196" preserveAspectRatio="none" aria-hidden="true">
                <circle cx="52" cy="34" r="92" fill="#ffffff" opacity=".08" />
                <circle cx="340" cy="164" r="104" fill="#ffffff" opacity=".06" />
                <circle cx="300" cy="30" r="38" fill="#000000" opacity=".08" />
            </svg>
        </div>

        <!-- no picture, no empty box: the banner runs straight into the name -->
        <div class="cea-profhead" :class="{ 'no-avatar': !profile.avatar }">
            <div v-if="profile.avatar" class="cea-avatar">
                <img :src="profile.avatar" :alt="profile.name" />
            </div>
            <div class="cea-name">{{ profile.name }}</div>
            <div class="cea-sub">{{ canEdit ? STATUS[profile.status ?? 'approved'] : 'Player at Eurofurence 30' }}</div>
        </div>

        <div v-if="profile.rejection_reason" class="cea-note warn" style="margin-top: 14px">
            A reviewer hid this profile: {{ profile.rejection_reason }}
        </div>

        <div class="cea-stats" style="margin-top: 16px">
            <div class="cea-stat" style="--cea-tone: var(--cea-accent-bright)">
                <b>{{ stats?.caught ?? 0 }}</b><small>caught</small>
            </div>
            <div class="cea-stat" :style="{ '--cea-tone': hue ?? 'var(--cea-line-soft)' }">
                <b>{{ fursuits.length }}</b><small>fursuit{{ fursuits.length === 1 ? '' : 's' }}</small>
            </div>
            <div class="cea-stat" style="--cea-tone: var(--cea-gold)">
                <b>{{ stats?.rank ? `#${stats.rank}` : '—' }}</b><small>rank</small>
            </div>
        </div>

        <template v-if="achievements.length">
            <h3 class="cea-sec">
                <span><b class="cea-tick" />Achievements</span>
                <span class="meta">{{ achievements.length }} earned</span>
            </h3>
            <div class="cea-case">
                <div
                    v-for="item in achievements.slice(0, 6)"
                    :key="item.id"
                    class="cea-bcard"
                    :style="{ '--cea-tone': tier(item) }"
                >
                    <span class="disc"><Star :size="17" /></span>
                    <b>{{ item.title }}</b>
                    <small v-if="item.earnedAt">
                        {{ new Date(item.earnedAt).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) }}
                    </small>
                </div>
            </div>
        </template>

        <template v-if="fursuits.length">
            <h3 class="cea-sec">
                <span><b class="cea-tick" />Fursuits</span>
                <span class="meta">{{ caughtHere }} of {{ fursuits.length }} caught by someone</span>
            </h3>
            <div class="cea-tiles">
                <button
                    v-for="fursuit in fursuits"
                    :key="fursuit.id"
                    class="cea-tile"
                    :class="{ current: fromFursuit === fursuit.id }"
                    :style="{ '--cea-tone': fursuit.rarity.color }"
                    :title="`${fursuit.name} · ${fursuit.rarity.label}`"
                    @click="lightbox = fursuit"
                >
                    <FursuitPhoto :src="fursuit.image" :name="fursuit.name" :tone="fursuit.rarity.color" />
                    <span class="name">{{ fursuit.name }}</span>
                </button>
            </div>
        </template>

        <!-- own profile: colour, about and links -->
        <template v-if="canEdit">
            <h3 class="cea-sec">
                <span><b class="cea-tick" />Profile colour</span>
                <span class="meta">{{ form.colour }}</span>
            </h3>
            <div class="cea-swatches">
                <button
                    v-for="(hexValue, key) in palette"
                    :key="key"
                    class="cea-swatch"
                    :class="{ on: form.colour === key }"
                    :style="{ '--cea-sw': hexValue }"
                    :title="key"
                    :aria-label="key"
                    @click="form.colour = key; save()"
                />
            </div>

            <h3 class="cea-sec"><span><b class="cea-tick" />About</span></h3>
            <template v-if="editing">
                <textarea v-model="form.description" class="cea-bio" rows="3" maxlength="255" />
                <div class="cea-hint" style="text-align: right; margin-top: 6px">
                    {{ form.description.length }} / 255
                </div>
            </template>
            <p v-else class="cea-hint" style="font-size: 14px; color: #c9d3e2">
                {{ profile.description || 'Nothing yet.' }}
            </p>

            <h3 class="cea-sec">
                <span><b class="cea-tick" />Links</span>
                <span class="meta">{{ form.links.length }} of 10</span>
            </h3>
            <template v-if="editing">
                <div v-for="(link, index) in form.links" :key="index" class="cea-link">
                    <LinkIcon :size="16" style="color: var(--cea-muted)" />
                    <input v-model="form.links[index]" class="cea-linkinput" placeholder="https://" />
                    <button class="cea-iconbtn" aria-label="Remove link" @click="form.links.splice(index, 1)">
                        <Trash2 :size="16" />
                    </button>
                </div>
                <div v-if="form.errors.links" class="cea-note warn" style="margin-top: 10px">
                    {{ form.errors.links }}
                </div>
                <button class="cea-btn ghost sm" style="margin-top: 10px" :disabled="form.links.length >= 10" @click="addLink">
                    Add link
                </button>
            </template>
            <template v-else>
                <div v-for="link in profile.links" :key="link" class="cea-link">
                    <LinkIcon :size="16" style="color: var(--cea-muted)" />
                    <a :href="link" target="_blank" rel="noopener nofollow">{{ link }}</a>
                </div>
                <p v-if="!profile.links.length" class="cea-hint">No links yet.</p>
            </template>

            <div class="cea-note" style="margin-top: 16px">
                Your picture comes from your Eurofurence identity account. Change it there.
            </div>

            <button v-if="!editing" class="cea-btn" style="margin-top: 14px" @click="editing = true">
                Edit profile
            </button>
            <template v-else>
                <button class="cea-btn" style="margin-top: 14px" :disabled="form.processing" @click="save">
                    Save profile
                </button>
                <button class="cea-btn ghost sm" style="margin-top: 8px" @click="editing = false; form.reset()">
                    Cancel
                </button>
            </template>
        </template>

        <!-- someone else's profile -->
        <template v-else>
            <template v-if="profile.description">
                <h3 class="cea-sec"><span><b class="cea-tick" />About</span></h3>
                <p style="margin: 0; font-size: 13px; color: #c9d3e2">{{ profile.description }}</p>
            </template>
            <template v-if="profile.links.length">
                <h3 class="cea-sec"><span><b class="cea-tick" />Links</span></h3>
                <div class="cea-note" style="margin-bottom: 10px">
                    Written by the attendee. These lead off Eurofurence sites.
                </div>
                <div v-for="link in profile.links" :key="link" class="cea-link">
                    <LinkIcon :size="16" style="color: var(--cea-muted)" />
                    <a :href="link" target="_blank" rel="noopener nofollow">{{ link }}</a>
                </div>
            </template>
        </template>

        <Teleport to="body">
            <Transition name="cea-fade">
                <div v-if="lightbox" class="cea-lightbox" @click.self="lightbox = null">
                    <div>
                        <div class="art">
                            <FursuitPhoto
                                :src="lightbox.image"
                                :name="lightbox.name"
                                :tone="lightbox.rarity.color"
                            />
                        </div>
                        <div style="text-align: center; margin-top: 14px">
                            <div class="cea-name">{{ lightbox.name }}</div>
                            <div class="cea-sub">
                                {{ lightbox.species }} ·
                                <span :style="{ color: lightbox.rarity.color }">{{ lightbox.rarity.label }}</span>
                                <template v-if="lightbox.caught">
                                    · caught {{ lightbox.caught }} time{{ lightbox.caught === 1 ? '' : 's' }}
                                </template>
                            </div>
                        </div>
                        <button class="cea-btn ghost sm" style="margin-top: 16px" @click="lightbox = null">
                            <X :size="16" /> Close
                        </button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </CatchEmAllLayout>
</template>

<style scoped>
.cea-linkinput {
    flex: 1;
    min-width: 0;
    background: var(--cea-page);
    border: 1px solid var(--cea-line-soft);
    border-radius: 10px;
    color: var(--cea-ink);
    font-size: 13px;
    padding: 9px 10px;
    outline: none;
}
.cea-linkinput:focus { border-color: var(--cea-accent-hi); }
.cea-iconbtn {
    background: none;
    border: 0;
    color: var(--cea-muted);
    cursor: pointer;
    padding: 6px;
}
.cea-link a { color: var(--cea-ink); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
