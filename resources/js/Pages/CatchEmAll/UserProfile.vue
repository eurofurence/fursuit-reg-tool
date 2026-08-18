<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import FursuitPhoto from "@/Components/CatchEmAll/FursuitPhoto.vue";
import * as QrCodeStylingModule from "qr-code-styling/lib/qr-code-styling.common.js";
import {
    ArrowLeft,
    ChevronDown,
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
    tier: number;
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
    achievementStats: {
        total: number;
        earned: number;
        earnedOptional: number;
        totalWithOptional: number;
    };
    palette: Record<string, string>;
    fromFursuit: number | null;
    canEdit: boolean;
    flash?: any;
    isEventActive: boolean;
}>();

// TODO: add rarety tiers to achievements, and sort by tier first, then title
props.achievements.sort((a, b) => {
    return a.title.localeCompare(b.title, undefined, { sensitivity: "base" });
});

const editing = ref(false);
const achievementsExpanded = ref(false);
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
    switch (a.tier) {
        case 0:
        case 69:
            return "var(--cea-tier-0)";
        case 2:
            return "var(--cea-tier-2)";
        case 3:
            return "var(--cea-tier-3)";
        case 4:
            return "var(--cea-tier-4)";
        case 5:
            return "var(--cea-tier-5)";
        case 1:
        default:
            return "var(--cea-tier-1)";
    }
}

const achievementProgress = computed(() => {
        const total = props.achievementStats.total;
        const earned = props.achievementStats.earned;
        const percentage = total > 0 ? Math.min(100, (earned / total) * 100) : 0;

        return {
                percentage,
                tone:
                        percentage >= 100
                                ? "var(--cea-tier-69)"
                                : percentage >= 80
                                    ? "var(--cea-tier-5)"
                                    : percentage >= 60
                                        ? "var(--cea-tier-4)"
                                        : percentage >= 40
                                            ? "var(--cea-tier-3)"
                                            : percentage >= 20
                                                ? "var(--cea-tier-2)"
                                                : "var(--cea-tier-1)",
        };
});

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
        :isEventActive="isEventActive"
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
            <div class="cea-progress cea-profile-progress">
                <div class="cea-bar">
                    <i
                        :style="{
                            width: `${achievementProgress.percentage}%`,
                            background: achievementProgress.tone,
                        }"
                    />
                </div>
            </div>
            <div class="cea-case">
                <div
                    v-for="item in achievementsExpanded
                        ? achievements
                        : achievements.slice(0, 3)"
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
            <button
                v-if="achievements.length > 3"
                class="cea-btn ghost sm cea-ach-toggle"
                :aria-expanded="achievementsExpanded"
                @click="achievementsExpanded = !achievementsExpanded"
            >
                <ChevronDown
                    :size="16"
                    :class="{ 'is-expanded': achievementsExpanded }"
                />
                {{ achievementsExpanded ? "Show fewer" : "Show all" }}
                <span class="meta"
                    >({{ achievementsExpanded ? 3 : achievements.length }})</span
                >
            </button>
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
                            <div class="cea-sc-titlewrap">
                                <QrCode :size="18" />
                                <div>
                                    <div class="cea-sc-title">
                                        Special codes
                                    </div>
                                </div>
                            </div>
                            <button
                                class="cea-btn ghost sm cea-sc-close"
                                @click="closeSpecialCodes"
                            >
                                <X :size="12" /> Close
                            </button>
                        </div>

                        <div class="cea-sc-layout">
                            <div
                                class="cea-sc-browser"
                                :class="{
                                    'cea-sc-mobile-hidden': activeSpecialCode,
                                }"
                            >
                                <div
                                    v-for="group in groupedSpecialCodes"
                                    :key="group.typeName"
                                    class="cea-sc-group"
                                >
                                    <h4
                                        class="cea-sec"
                                        style="margin-top: 10px"
                                    >
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
                                            :class="{
                                                active:
                                                    activeSpecialCode?.url ===
                                                    specialCode.url,
                                            }"
                                            @click="showQrForCode(specialCode)"
                                        >
                                            <strong>{{
                                                specialCode.code
                                            }}</strong>
                                            <small>{{
                                                specialCode.typeName ??
                                                "Unknown type"
                                            }}</small>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="cea-sc-preview"
                                :class="{
                                    'cea-sc-mobile-hidden': !activeSpecialCode,
                                }"
                            >
                                <template v-if="activeSpecialCode">
                                    <div class="cea-sc-previewhead">
                                        <div class="cea-sc-previewtitle">
                                            <QrCode :size="16" />
                                            <div>
                                                <strong>{{
                                                    activeSpecialCode.typeName ??
                                                    "Unknown type"
                                                }}</strong>
                                                <div class="cea-sc-previewcode">
                                                    {{ activeSpecialCode.code }}
                                                </div>
                                            </div>
                                        </div>
                                        <button
                                            class="w-[25%] cea-btn ghost sm cea-sc-back"
                                            @click="backToSpecialCodeList"
                                        >
                                            <ArrowLeft :size="16" /> Back
                                        </button>
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

                                    <div
                                        class="cea-note"
                                        style="margin-top: 10px"
                                    >
                                        <a
                                            :href="activeSpecialCode.url"
                                            target="_blank"
                                            rel="noopener nofollow"
                                        >
                                            {{ activeSpecialCode.url }}
                                        </a>
                                    </div>
                                </template>

                                <template v-else>
                                    <div class="cea-sc-empty">
                                        <QrCode :size="22" />
                                        <strong>No code selected</strong>
                                        <span>
                                            Pick a tile on the left to render
                                            its QR code here.
                                        </span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </CatchEmAllLayout>
</template>

<style scoped>
.cea-sc-modal {
    width: min(1240px, 96vw);
    max-width: min(1240px, 96vw);
    max-height: 92vh;
    overflow: auto;
    border-radius: 20px;
    background: #0f1724;
    border: 1px solid var(--cea-line-soft);
    padding: 18px;
}
.cea-lightbox > .cea-sc-modal {
    width: min(1240px, 96vw);
    max-width: min(1240px, 96vw);
}
.cea-sc-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 2rem;
    position: relative;
    padding-right: 70px;
}
.cea-sc-titlewrap {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.cea-sc-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 20px;
    font-weight: 700;
    color: var(--cea-ink);
}
.cea-sc-close {
    position: absolute;
    top: 0;
    right: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 8rem;
    min-width: 0;
    padding: 5px 8px;
}
.cea-sc-layout {
    display: grid;
    grid-template-columns: minmax(320px, 1.1fr) minmax(360px, 0.9fr);
    gap: 16px;
    align-items: start;
}
.cea-sc-browser,
.cea-sc-preview {
    min-height: 100%;
}
.cea-sc-browser {
    padding-right: 4px;
}
.cea-sc-group {
    margin-bottom: 10px;
}
.cea-sc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 8px;
}
.cea-sc-tile {
    text-align: left;
    border: 1px solid var(--cea-line-soft);
    background: rgba(15, 23, 36, 0.8);
    color: var(--cea-ink);
    border-radius: 12px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    cursor: pointer;
    transition:
        border-color 0.18s ease,
        background-color 0.18s ease,
        transform 0.18s ease;
}
.cea-sc-tile:hover {
    border-color: var(--cea-accent-hi);
    transform: translateY(-1px);
}
.cea-sc-tile.active {
    border-color: var(--cea-accent-hi);
    background: rgba(255, 255, 255, 0.08);
}
.cea-sc-tile small {
    color: var(--cea-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cea-sc-preview {
    border: 1px solid var(--cea-line-soft);
    border-radius: 16px;
    padding: 14px;
    background:
        radial-gradient(
            circle at top right,
            rgba(255, 255, 255, 0.06),
            transparent 35%
        ),
        rgba(255, 255, 255, 0.02);
    position: sticky;
    top: 0;
}
.cea-sc-previewhead {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.cea-sc-previewtitle {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--cea-ink);
}
.cea-sc-previewcode {
    margin-top: 2px;
    font-size: 12px;
    color: var(--cea-muted);
}
.cea-sc-back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 8rem;
    min-width: 0;
    padding: 5px 8px;
}
.cea-sc-qrwrap {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 420px;
    padding: 16px;
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
    width: min(360px, 72vw);
    aspect-ratio: 1 / 1;
    display: flex;
    justify-content: center;
    align-items: center;
}
.cea-sc-empty {
    min-height: 420px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 8px;
    text-align: center;
    color: var(--cea-muted);
    border: 1px dashed var(--cea-line-soft);
    border-radius: 14px;
    padding: 20px;
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
.cea-ach-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
}
.cea-profile-progress {
    margin-bottom: 10px;
}
.cea-ach-toggle svg {
    transition: transform 0.18s ease;
}
.cea-ach-toggle svg.is-expanded {
    transform: rotate(180deg);
}

@media (max-width: 899px) {
    .cea-sc-modal {
        width: min(760px, 96vw);
        max-width: min(760px, 96vw);
        padding: 14px;
    }
    .cea-sc-head {
        align-items: center;
    }
    .cea-sc-layout {
        display: block;
    }
    .cea-sc-mobile-hidden {
        display: none;
    }
    .cea-sc-browser,
    .cea-sc-preview {
        min-height: 0;
        width: 100%;
    }
    .cea-sc-preview {
        position: static;
        padding: 0;
        border: 0;
        background: transparent;
    }
    .cea-sc-browser {
        display: block;
    }
    .cea-sc-previewhead {
        align-items: center;
    }
    .cea-sc-back {
        display: inline-flex;
    }
    .cea-sc-grid {
        grid-template-columns: 1fr;
    }
    .cea-sc-qrwrap,
    .cea-sc-empty {
        min-height: 0;
    }
    .cea-sc-qr {
        width: min(300px, 72vw);
    }
}
</style>
