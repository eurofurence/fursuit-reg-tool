<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import FursuitPhoto from "@/Components/CatchEmAll/FursuitPhoto.vue";
import * as QrCodeStylingModule from "qr-code-styling/lib/qr-code-styling.common.js";
import {
    ArrowLeft,
    Link as LinkIcon,
    QrCode,
    Star,
    Ticket,
    Trash2,
    X,
} from "lucide-vue-next";

type Fursuit = {
    id: number;
    name: string;
    species: string | null;
    image: string | null;
    caught: number;
    rank: number | null;
    ranking: { level: string; label: string; color: string; icon: string };
};

type Achievement = {
    id: number;
    title: string;
    maxProgress: number;
    isOptional: boolean;
    earnedAt: string | null;
};

type SpecialCodeLink = {
    typeName: string | null;
    code: string;
    url: string;
};

const props = defineProps<{
    profile: {
        uuid: string;
        name: string;
        avatar: string | null;
        description: string | null;
        colour: string | null;
        colourHex: string;
        links: string[];
        status: string | null;
        rejection_reason: string | null;
        specialCodes: SpecialCodeLink[];
    };
    fursuits: Fursuit[];
    stats: { caught: number; rank: number | null } | null;
    achievements: Achievement[];
    palette: Record<string, string>;
    fromFursuit: number | null;
    canEdit: boolean;
    flash?: any;
}>();

const editing = ref(false);
const lightbox = ref<Fursuit | null>(null);
const specialCodesOpen = ref(false);
const activeSpecialCode = ref<SpecialCodeLink | null>(null);
const qrMount = ref<HTMLElement | null>(null);
const qrError = ref<string | null>(null);
const QrCodeStylingCtor =
    (QrCodeStylingModule as Record<string, any>).QRCodeStyling ??
    (QrCodeStylingModule as Record<string, any>).default?.QRCodeStyling ??
    (QrCodeStylingModule as Record<string, any>).default;

const groupedSpecialCodes = computed(() => {
    const groups = new Map<string, SpecialCodeLink[]>();

    for (const specialCode of props.profile.specialCodes) {
        const key = specialCode.typeName ?? "Unknown type";
        const bucket = groups.get(key) ?? [];
        bucket.push(specialCode);
        groups.set(key, bucket);
    }

    return Array.from(groups.entries())
        .map(([typeName, codes]) => ({
            typeName,
            codes: [...codes].sort((a, b) =>
                a.code.localeCompare(b.code, undefined, {
                    sensitivity: "base",
                }),
            ),
        }))
        .sort((a, b) =>
            a.typeName.localeCompare(b.typeName, undefined, {
                sensitivity: "base",
            }),
        );
});

function openSpecialCodes() {
    specialCodesOpen.value = true;
    activeSpecialCode.value = null;
}

function closeSpecialCodes() {
    specialCodesOpen.value = false;
    activeSpecialCode.value = null;
}

function showQrForCode(specialCode: SpecialCodeLink) {
    qrError.value = null;
    activeSpecialCode.value = specialCode;
}

function backToSpecialCodeList() {
    qrError.value = null;
    activeSpecialCode.value = null;
}

watch(
    [activeSpecialCode, qrMount],
    async ([specialCode, mount]) => {
        if (!specialCode || !mount) {
            return;
        }

        await nextTick();
        mount.innerHTML = "";
        qrError.value = null;

        try {
            if (typeof QrCodeStylingCtor !== "function") {
                throw new Error(
                    "Could not resolve qr-code-styling constructor",
                );
            }

            const size = Math.min(300, Math.max(180, mount.clientWidth || 300));

            const qrConfig: any = {
                type: "canvas",
                shape: "square",
                width: size,
                height: size,
                data: specialCode.url,
                margin: 0,
                qrOptions: {
                    typeNumber: 0,
                    mode: "Byte",
                    errorCorrectionLevel: "Q",
                },
                imageOptions: {
                    saveAsBlob: true,
                    hideBackgroundDots: true,
                    imageSize: 0.4,
                    margin: 0,
                },
                dotsOptions: {
                    type: "extra-rounded",
                    color: "#f3f4f6",
                    roundSize: true,
                },
                backgroundOptions: {
                    round: 0,
                    color: "transparent",
                },
                image: null,
                dotsOptionsHelper: {
                    colorType: { single: true, gradient: false },
                    gradient: {
                        linear: true,
                        radial: false,
                        color1: "#6a1a4c",
                        color2: "#6a1a4c",
                        rotation: "0",
                    },
                },
                cornersSquareOptions: {
                    type: "extra-rounded",
                    color: "#ffffff",
                },
                cornersSquareOptionsHelper: {
                    colorType: { single: true, gradient: false },
                    gradient: {
                        linear: true,
                        radial: false,
                        color1: "#000000",
                        color2: "#000000",
                        rotation: "0",
                    },
                },
                cornersDotOptions: {
                    type: "dot",
                    color: "#e5e7eb",
                },
                cornersDotOptionsHelper: {
                    colorType: { single: true, gradient: false },
                    gradient: {
                        linear: true,
                        radial: false,
                        color1: "#000000",
                        color2: "#000000",
                        rotation: "0",
                    },
                },
                backgroundOptionsHelper: {
                    colorType: { single: true, gradient: false },
                    gradient: {
                        linear: true,
                        radial: false,
                        color1: "#ffffff",
                        color2: "#ffffff",
                        rotation: "0",
                    },
                },
            };

            const qrCode = new QrCodeStylingCtor(qrConfig);
            qrCode.append(mount);
        } catch (error) {
            qrError.value =
                error instanceof Error
                    ? error.message
                    : "QR code could not be rendered.";
        }
    },
    { flush: "post" },
);

const form = useForm({
    description: props.profile.description ?? "",
    colour: props.profile.colour,
    links: [...props.profile.links],
});

/* every profile carries its own colour, picked at random when it is created */
const hue = computed(
    () => props.palette[form.colour ?? ""] ?? props.profile.colourHex,
);

const STATUS: Record<string, string> = {
    approved: "Visible to other players",
    pending: "Hidden until a reviewer approves it",
    rejected: "Hidden, see the reason below",
};

function tier(a: Achievement) {
    if (a.isOptional) return "var(--cea-tier-team)";
    if (a.maxProgress >= 50) return "var(--cea-tier-3)";
    if (a.maxProgress >= 10) return "var(--cea-tier-2)";
    return "var(--cea-tier-1)";
}

function addLink() {
    if (form.links.length >= 10) return;
    form.links.push("");
}

function save() {
    form.transform((data) => ({
        ...data,
        links: data.links.filter((url) => url.trim() !== ""),
    })).put(route("catch-em-all.profiles.update", props.profile.uuid), {
        preserveScroll: true,
        onSuccess: () => (editing.value = false),
    });
}

const caughtHere = computed(
    () => props.fursuits.filter((f) => f.caught > 0).length,
);
</script>

<template>
    <CatchEmAllLayout
        :title="canEdit ? 'Your profile' : profile.name"
        :subtitle="
            canEdit
                ? (STATUS[profile.status ?? 'approved'] ?? '')
                : 'Player profile'
        "
        :count="canEdit ? (stats?.caught ?? 0) : null"
        :hue="hue"
        :flash="flash"
    >
        <div class="cea-showcase" :style="{ background: hue }">
            <svg
                viewBox="0 0 390 196"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <circle cx="52" cy="34" r="92" fill="#ffffff" opacity=".08" />
                <circle
                    cx="340"
                    cy="164"
                    r="104"
                    fill="#ffffff"
                    opacity=".06"
                />
                <circle cx="300" cy="30" r="38" fill="#000000" opacity=".08" />
            </svg>
        </div>

        <!-- no picture, no empty box: the banner runs straight into the name -->
        <div class="cea-profhead" :class="{ 'no-avatar': !profile.avatar }">
            <div v-if="profile.avatar" class="cea-avatar">
                <img :src="profile.avatar" :alt="profile.name" />
            </div>
            <div class="cea-name">{{ profile.name }}</div>
            <div class="cea-sub">
                {{
                    canEdit
                        ? STATUS[profile.status ?? "approved"]
                        : "Player at Eurofurence 30"
                }}
            </div>
        </div>

        <div
            v-if="profile.rejection_reason"
            class="cea-note warn"
            style="margin-top: 14px"
        >
            A reviewer hid this profile: {{ profile.rejection_reason }}
        </div>

        <div class="cea-stats" style="margin-top: 16px">
            <div class="cea-stat" style="--cea-tone: var(--cea-accent-bright)">
                <b>{{ stats?.caught ?? 0 }}</b
                ><small>caught</small>
            </div>
            <div
                class="cea-stat"
                :style="{ '--cea-tone': hue ?? 'var(--cea-line-soft)' }"
            >
                <b>{{ fursuits.length }}</b
                ><small>fursuit{{ fursuits.length === 1 ? "" : "s" }}</small>
            </div>
            <div class="cea-stat" style="--cea-tone: var(--cea-gold)">
                <b>{{ stats?.rank ? `#${stats.rank}` : "—" }}</b
                ><small>rank</small>
            </div>
        </div>

        <div
            v-if="canEdit && profile.specialCodes.length"
            style="display: flex; justify-content: flex-end; margin-top: 12px"
        >
            <button class="cea-btn ghost sm" @click="openSpecialCodes">
                <Ticket :size="16" /> Special codes
            </button>
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
                        {{
                            new Date(item.earnedAt).toLocaleDateString(
                                undefined,
                                { day: "numeric", month: "short" },
                            )
                        }}
                    </small>
                </div>
            </div>
        </template>

        <template v-if="fursuits.length">
            <h3 class="cea-sec">
                <span><b class="cea-tick" />Fursuits</span>
                <span class="meta"
                    >{{ caughtHere }} of {{ fursuits.length }} caught by
                    someone</span
                >
            </h3>
            <div class="cea-tiles">
                <button
                    v-for="fursuit in fursuits"
                    :key="fursuit.id"
                    class="cea-tile"
                    :class="{ current: fromFursuit === fursuit.id }"
                    :style="{ '--cea-tone': fursuit.ranking.color }"
                    :title="`${fursuit.name} · ${fursuit.ranking.label}`"
                    @click="lightbox = fursuit"
                >
                    <FursuitPhoto
                        :src="fursuit.image"
                        :name="fursuit.name"
                        :tone="fursuit.ranking.color"
                    />
                    <span class="name">{{ fursuit.name }}</span>
                </button>
            </div>
        </template>

        <!-- own profile: colour, about and links -->
        <template v-if="canEdit">
            <h3 v-if="editing" class="cea-sec">
                <span><b class="cea-tick" />Profile colour</span>
                <span class="meta">{{ form.colour }}</span>
            </h3>
            <div v-if="editing" class="cea-swatches">
                <button
                    v-for="(hexValue, key) in palette"
                    :key="key"
                    class="cea-swatch"
                    :class="{ on: form.colour === key }"
                    :style="{ '--cea-sw': hexValue }"
                    :title="key"
                    :aria-label="key"
                    @click="form.colour = key"
                />
            </div>

            <h3 class="cea-sec">
                <span><b class="cea-tick" />About</span>
            </h3>
            <template v-if="editing">
                <textarea
                    v-model="form.description"
                    class="cea-bio"
                    rows="3"
                    maxlength="255"
                />
                <div
                    class="cea-hint"
                    style="text-align: right; margin-top: 6px"
                >
                    {{ form.description.length }} / 255
                </div>
            </template>
            <p v-else class="cea-hint" style="font-size: 14px; color: #c9d3e2">
                {{ profile.description || "Nothing yet." }}
            </p>

            <h3 class="cea-sec">
                <span><b class="cea-tick" />Links</span>
                <span class="meta">{{ form.links.length }} of 10</span>
            </h3>
            <template v-if="editing">
                <div
                    v-for="(link, index) in form.links"
                    :key="index"
                    class="cea-link"
                >
                    <LinkIcon :size="16" style="color: var(--cea-muted)" />
                    <input
                        v-model="form.links[index]"
                        class="cea-linkinput"
                        placeholder="https://"
                    />
                    <button
                        class="cea-iconbtn"
                        aria-label="Remove link"
                        @click="form.links.splice(index, 1)"
                    >
                        <Trash2 :size="16" />
                    </button>
                </div>
                <div
                    v-if="form.errors.links"
                    class="cea-note warn"
                    style="margin-top: 10px"
                >
                    {{ form.errors.links }}
                </div>
                <button
                    class="cea-btn ghost sm"
                    style="margin-top: 10px"
                    :disabled="form.links.length >= 10"
                    @click="addLink"
                >
                    Add link
                </button>
            </template>
            <template v-else>
                <div v-for="link in profile.links" :key="link" class="cea-link">
                    <LinkIcon :size="16" style="color: var(--cea-muted)" />
                    <a :href="link" target="_blank" rel="noopener nofollow">{{
                        link
                    }}</a>
                </div>
                <p v-if="!profile.links.length" class="cea-hint">
                    No links yet.
                </p>
            </template>

            <div class="cea-note" style="margin-top: 16px">
                Your picture comes from your Eurofurence identity account.
                Change it there.
            </div>

            <button
                v-if="!editing"
                class="cea-btn"
                style="margin-top: 14px"
                @click="editing = true"
            >
                Edit profile
            </button>
            <template v-else>
                <button
                    class="cea-btn"
                    style="margin-top: 14px"
                    :disabled="form.processing"
                    @click="save"
                >
                    Save profile
                </button>
                <button
                    class="cea-btn ghost sm"
                    style="margin-top: 8px"
                    @click="
                        editing = false;
                        form.reset();
                    "
                >
                    Cancel
                </button>
            </template>
        </template>

        <!-- someone else's profile -->
        <template v-else>
            <template v-if="profile.description">
                <h3 class="cea-sec">
                    <span><b class="cea-tick" />About</span>
                </h3>
                <p style="margin: 0; font-size: 13px; color: #c9d3e2">
                    {{ profile.description }}
                </p>
            </template>
            <template v-if="profile.links.length">
                <h3 class="cea-sec">
                    <span><b class="cea-tick" />Links</span>
                </h3>
                <div class="cea-note" style="margin-bottom: 10px">
                    Written by the attendee. These lead off Eurofurence sites.
                </div>
                <div v-for="link in profile.links" :key="link" class="cea-link">
                    <LinkIcon :size="16" style="color: var(--cea-muted)" />
                    <a :href="link" target="_blank" rel="noopener nofollow">{{
                        link
                    }}</a>
                </div>
            </template>
        </template>

        <Teleport to="body">
            <Transition name="cea-fade">
                <div
                    v-if="lightbox"
                    class="cea-lightbox"
                    @click.self="lightbox = null"
                >
                    <div>
                        <div class="art">
                            <FursuitPhoto
                                :src="lightbox.image"
                                :name="lightbox.name"
                                :tone="lightbox.ranking.color"
                            />
                        </div>
                        <div style="text-align: center; margin-top: 14px">
                            <div class="cea-name">{{ lightbox.name }}</div>
                            <div class="cea-sub">
                                {{ lightbox.species }} ·
                                <span
                                    :style="{ color: lightbox.ranking.color }"
                                    >{{ lightbox.ranking.label }}</span
                                >
                                <template v-if="lightbox.caught">
                                    · caught {{ lightbox.caught }} time{{
                                        lightbox.caught === 1 ? "" : "s"
                                    }}
                                </template>
                            </div>
                        </div>
                        <button
                            class="cea-btn ghost sm"
                            style="margin-top: 16px"
                            @click="lightbox = null"
                        >
                            <X :size="16" /> Close
                        </button>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <Teleport to="body">
            <Transition name="cea-fade">
                <div
                    v-if="specialCodesOpen"
                    class="cea-lightbox"
                    @click.self="closeSpecialCodes"
                >
                    <div class="cea-sc-modal">
                        <div class="cea-sc-head">
                            <div class="cea-name" style="font-size: 20px">
                                <QrCode :size="18" />
                                <span>Special codes</span>
                            </div>
                            <button
                                class="cea-btn ghost sm"
                                @click="closeSpecialCodes"
                            >
                                <X :size="16" /> Close
                            </button>
                        </div>

                        <template v-if="!activeSpecialCode">
                            <p class="cea-note" style="margin-bottom: 12px">
                                Choose a code to open its QR.
                            </p>

                            <div
                                v-for="group in groupedSpecialCodes"
                                :key="group.typeName"
                                class="cea-sc-group"
                            >
                                <h4 class="cea-sec" style="margin-top: 10px">
                                    <span
                                        ><b class="cea-tick" />{{
                                            group.typeName
                                        }}</span
                                    >
                                    <span class="meta">{{
                                        group.codes.length
                                    }}</span>
                                </h4>

                                <div class="cea-sc-grid">
                                    <button
                                        v-for="specialCode in group.codes"
                                        :key="specialCode.url"
                                        class="cea-sc-tile"
                                        @click="showQrForCode(specialCode)"
                                    >
                                        <strong>{{ specialCode.code }}</strong>
                                        <small>{{ specialCode.url }}</small>
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div
                                style="
                                    display: flex;
                                    gap: 8px;
                                    margin-bottom: 12px;
                                "
                            >
                                <button
                                    class="cea-btn ghost sm"
                                    @click="backToSpecialCodeList"
                                >
                                    <ArrowLeft :size="16" /> Back
                                </button>
                            </div>

                            <div class="cea-note" style="margin-bottom: 12px">
                                <strong>{{
                                    activeSpecialCode.typeName ?? "Unknown type"
                                }}</strong>
                                <span> · {{ activeSpecialCode.code }}</span>
                            </div>

                            <div class="cea-sc-qrwrap">
                                <div ref="qrMount" class="cea-sc-qr" />
                            </div>

                            <div
                                v-if="qrError"
                                class="cea-note warn"
                                style="margin-top: 10px"
                            >
                                QR could not be rendered: {{ qrError }}
                            </div>

                            <div class="cea-note" style="margin-top: 10px">
                                <a
                                    :href="activeSpecialCode.url"
                                    target="_blank"
                                    rel="noopener nofollow"
                                >
                                    {{ activeSpecialCode.url }}
                                </a>
                            </div>
                        </template>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </CatchEmAllLayout>
</template>

<style scoped>
.cea-sc-modal {
    width: min(920px, 94vw);
    max-height: 90vh;
    overflow: auto;
    border-radius: 16px;
    background: #0f1724;
    border: 1px solid var(--cea-line-soft);
    padding: 14px;
}
.cea-sc-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.cea-sc-group {
    margin-bottom: 10px;
}
.cea-sc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 8px;
}
.cea-sc-tile {
    text-align: left;
    border: 1px solid var(--cea-line-soft);
    background: var(--cea-page);
    color: var(--cea-ink);
    border-radius: 12px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    cursor: pointer;
}
.cea-sc-tile:hover {
    border-color: var(--cea-accent-hi);
}
.cea-sc-tile small {
    color: var(--cea-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cea-sc-qrwrap {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 12px;
    border: 1px solid var(--cea-line-soft);
    border-radius: 14px;
    overflow: hidden;
    background:
        linear-gradient(45deg, rgba(255, 255, 255, 0.06) 25%, transparent 25%) 0
            0 / 14px 14px,
        linear-gradient(-45deg, rgba(255, 255, 255, 0.06) 25%, transparent 25%)
            0 0 / 14px 14px,
        var(--cea-page);
}
.cea-sc-qr {
    width: min(300px, 72vw);
    aspect-ratio: 1 / 1;
    display: flex;
    justify-content: center;
    align-items: center;
}
.cea-sc-qr :deep(canvas),
.cea-sc-qr :deep(svg) {
    max-width: 100%;
    max-height: 100%;
    width: 100% !important;
    height: 100% !important;
    display: block;
}
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
.cea-linkinput:focus {
    border-color: var(--cea-accent-hi);
}
.cea-iconbtn {
    background: none;
    border: 0;
    color: var(--cea-muted);
    cursor: pointer;
    padding: 6px;
}
.cea-link a {
    color: var(--cea-ink);
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
