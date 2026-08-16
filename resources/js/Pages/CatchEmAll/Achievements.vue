<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import CatchEmAllLayout from "@/Layouts/CatchEmAllLayout.vue";
import { Check, ChevronDown, Flag, Lock } from "lucide-vue-next";

type Achievement = {
    id: number;
    /** null while the achievement is hidden: the server does not send it */
    title: string | null;
    description: string | null;
    task: string | null;
    completed: boolean;
    progress: number;
    maxProgress: number;
    progressPercentage: number;
    earnedAt?: string | null;
    isOptional: boolean;
    isLocked: boolean;
    hiddenByLock: boolean;
    progressDetail?: {
        totalProgress: string[];
        currentProgress: string[];
    } | null;
};

type Stats = {
    total: number;
    earned: number;
    earnedOptional: number;
};

const props = defineProps<{
    achievements: Achievement[];
    flash?: any;
    caughtTotal: number;
    stats: Stats;
}>();

const SNAPSHOT_KEY = "cea:achievements:snapshot:v3";

const filter = ref<"all" | "earned" | "progress" | "locked">("all");
const open = ref<number[]>([]);
/** earned since the last visit */
const freshlyEarned = ref<number[]>([]);
/** progressed since the last visit, id => where the bar was */
const moved = ref<Record<number, number>>({});
/** bars start at last visit's value so the growth is visible, then fill */
const barWidth = ref<Record<number, number>>({});

onMounted(() => {
    let before: Record<number, { done: boolean; pct: number }> = {};
    try {
        before = JSON.parse(localStorage.getItem(SNAPSHOT_KEY) ?? "{}");
    } catch {
        before = {};
    }

    for (const item of props.achievements) {
        const was = before[item.id];
        if (item.completed && was && !was.done)
            freshlyEarned.value.push(item.id);
        if (!item.completed && was && item.progressPercentage > was.pct) {
            moved.value[item.id] = was.pct;
            barWidth.value[item.id] = was.pct;
        }
    }

    /* let the old width paint first, then animate to the new one */
    requestAnimationFrame(() =>
        requestAnimationFrame(() => {
            for (const id of Object.keys(moved.value)) barWidth.value[+id] = -1;
        }),
    );

    try {
        localStorage.setItem(
            SNAPSHOT_KEY,
            JSON.stringify(
                Object.fromEntries(
                    props.achievements.map((a) => [
                        a.id,
                        { done: a.completed, pct: a.progressPercentage },
                    ]),
                ),
            ),
        );
    } catch {
        // private mode: the markers are a nicety, not a feature
    }
});

/**
 * Tier by how much work an achievement is, so the list reads at a glance.
 * The optional ones are the staff and team codes, which get their own colour.
 */
function tier(a: Achievement) {
    if (a.isOptional) return "var(--cea-tier-team)";
    if (a.maxProgress >= 50) return "var(--cea-tier-3)";
    if (a.maxProgress >= 10) return "var(--cea-tier-2)";
    return "var(--cea-tier-1)";
}

/** A locked achievement is not "in progress": you cannot work on it yet. */
const state = (a: Achievement) =>
    a.completed ? "earned" : a.isLocked ? "locked" : "progress";

/* Earned first, newest at the top. Then what you are closest to finishing,
   which is the only ordering that answers "what should I do next". Locked ones
   last, because nothing you do moves them yet. */
const sorted = computed(() =>
    [...props.achievements].sort((a, b) => {
        const rank = { earned: 0, progress: 1, locked: 2 };
        const byState = rank[state(a)] - rank[state(b)];
        if (byState !== 0) return byState;

        if (state(a) === "earned") {
            return (
                new Date(b.earnedAt ?? 0).getTime() -
                new Date(a.earnedAt ?? 0).getTime()
            );
        }
        if (state(a) === "progress") {
            return b.progressPercentage - a.progressPercentage;
        }
        return (a.title ?? "zz").localeCompare(b.title ?? "zz");
    }),
);

const counts = computed(() => ({
    earned: props.achievements.filter((a) => state(a) === "earned").length,
    progress: props.achievements.filter((a) => state(a) === "progress").length,
    locked: props.achievements.filter((a) => state(a) === "locked").length,
}));

const shown = computed(() =>
    sorted.value.filter(
        (a) => filter.value === "all" || state(a) === filter.value,
    ),
);

/** A hidden achievement gives nothing away until it is earned. */
const masked = (a: Achievement) => a.hiddenByLock && !a.completed;

function toggle(a: Achievement) {
    if (masked(a)) return;
    open.value = open.value.includes(a.id)
        ? open.value.filter((id) => id !== a.id)
        : [...open.value, a.id];
}

function earnedOn(a: Achievement) {
    if (!a.earnedAt) return "Earned";
    return `Earned ${new Date(a.earnedAt).toLocaleDateString(undefined, { day: "numeric", month: "short" })}`;
}

/** Every named sub-goal, found ones first, each alphabetical within its half. */
function subgoals(a: Achievement) {
    const found = a.progressDetail?.currentProgress ?? [];
    return [...(a.progressDetail?.totalProgress ?? [])]
        .map((name) => ({ name, found: found.includes(name) }))
        .sort(
            (x, y) =>
                Number(y.found) - Number(x.found) ||
                x.name.localeCompare(y.name),
        );
}

function width(a: Achievement) {
    const held = barWidth.value[a.id];
    return `${held === undefined || held === -1 ? a.progressPercentage : held}%`;
}

const statsPercent = computed(() =>
    props.stats.total ? (props.stats.earned / props.stats.total) * 100 : 0,
);
</script>

<template>
    <CatchEmAllLayout
        title="Achievements"
        :subtitle="`${counts.earned} of ${achievements.length} earned`"
        :count="caughtTotal"
        hue="var(--cea-tier-2)"
        :flash="flash"
    >
        <div class="cea-progress" style="margin-bottom: 14px">
            <div class="cea-bar">
                <i
                    :style="{
                        width: `${Math.max(statsPercent, 1.5)}%`,
                        background: 'var(--cea-tier-2)',
                    }"
                />
            </div>
            <div class="cea-progress-meta">
                <span class="frac">{{ stats.earned }}/{{ stats.total }}</span>
                <span v-if="stats.earnedOptional" class="opt"
                    >+{{ stats.earnedOptional }} optional</span
                >
            </div>
        </div>

        <div class="cea-seg" style="margin-bottom: 14px">
            <button :class="{ on: filter === 'all' }" @click="filter = 'all'">
                All <span>{{ achievements.length }}</span>
            </button>
            <button
                :class="{ on: filter === 'earned' }"
                @click="filter = 'earned'"
            >
                Earned <span>{{ counts.earned }}</span>
            </button>
            <button
                :class="{ on: filter === 'progress' }"
                @click="filter = 'progress'"
            >
                In progress <span>{{ counts.progress }}</span>
            </button>
            <button
                :class="{ on: filter === 'locked' }"
                @click="filter = 'locked'"
            >
                Locked <span>{{ counts.locked }}</span>
            </button>
        </div>

        <div class="cea-two">
            <div
                v-for="item in shown"
                :key="item.id"
                class="cea-ach"
                :class="{
                    earned: item.completed,
                    fresh: freshlyEarned.includes(item.id),
                    open: open.includes(item.id),
                    masked: masked(item),
                }"
                :style="{ '--cea-tone': tier(item) }"
                @click="toggle(item)"
            >
                <span class="disc" :class="{ locked: !item.completed }">
                    <Check v-if="item.completed" :size="20" />
                    <Lock v-else-if="item.isLocked" :size="20" />
                    <Flag v-else :size="20" />
                </span>
                <div class="body">
                    <div class="line">
                        <b>{{
                            masked(item) ? "Hidden achievement" : item.title
                        }}</b>
                        <span
                            v-if="freshlyEarned.includes(item.id)"
                            class="cea-new"
                            >new</span
                        >
                        <span
                            v-else-if="moved[item.id] !== undefined"
                            class="cea-new moved"
                        >
                            +{{
                                Math.round(
                                    item.progressPercentage - moved[item.id],
                                )
                            }}%
                        </span>
                        <ChevronDown
                            v-if="!masked(item)"
                            class="chev"
                            :size="16"
                        />
                    </div>

                    <small v-if="masked(item)"
                        >Find it to see what it was</small
                    >
                    <small v-else-if="item.completed">{{
                        earnedOn(item)
                    }}</small>
                    <small v-else-if="item.isLocked"
                        >Locked until you finish the one before it</small
                    >
                    <small v-else
                        >{{ item.progress }} of {{ item.maxProgress }}</small
                    >

                    <div
                        v-if="!item.completed && !item.isLocked"
                        class="cea-bar"
                        style="margin-top: 9px"
                    >
                        <i
                            :style="{
                                width: width(item),
                                background: tier(item),
                            }"
                        />
                    </div>

                    <template v-if="open.includes(item.id) && !masked(item)">
                        <p class="desc" v-if="item.completed">
                            {{ item.description }}
                        </p>
                        <p
                            v-if="item.task"
                            class="desc"
                            :class="{ task: item.completed }"
                        >
                            {{ item.task }}
                        </p>
                        <!-- named sub-goals: the team members, the poster locations.
                             One tag each, filled once you have found it, so the list
                             doubles as the checklist of what is left. -->
                        <template
                            v-if="item.progressDetail?.totalProgress?.length"
                        >
                            <div class="subgoals-head">
                                Found
                                {{ item.progressDetail.currentProgress.length }}
                                of
                                {{ item.progressDetail.totalProgress.length }}
                            </div>
                            <div class="subgoals">
                                <span
                                    v-for="goal in subgoals(item)"
                                    :key="goal.name"
                                    class="subgoal"
                                    :class="{ found: goal.found }"
                                    :style="
                                        goal.found
                                            ? { '--cea-tone': tier(item) }
                                            : undefined
                                    "
                                >
                                    <Check v-if="goal.found" :size="12" />
                                    {{ goal.name }}
                                </span>
                            </div>
                        </template>
                    </template>
                </div>
            </div>
        </div>

        <p v-if="!shown.length" class="cea-hint">Nothing here yet.</p>
    </CatchEmAllLayout>
</template>

<style scoped>
.cea-seg {
    display: flex;
    flex-wrap: wrap;
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
.cea-seg button span {
    opacity: 0.6;
    margin-left: 4px;
    font-variant-numeric: tabular-nums;
}
.cea-seg button.on {
    background: var(--cea-accent);
    color: #fff;
}

.cea-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--cea-panel-2);
    border: 1px solid var(--cea-line-soft);
    border-radius: 10px;
}
.cea-progress .cea-bar {
    flex: 1;
}
.cea-progress-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
    font-size: 11.5px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.cea-progress-meta .frac {
    color: var(--cea-ink);
}
.cea-progress-meta .opt {
    color: var(--cea-muted);
}

.cea-ach {
    cursor: pointer;
}
.cea-ach.masked {
    cursor: default;
    opacity: 0.75;
}
.cea-ach .line {
    display: flex;
    align-items: center;
    gap: 8px;
}
.cea-ach .line b {
    flex: 1;
    min-width: 0;
}
.cea-ach .chev {
    color: var(--cea-muted);
    transition: transform 0.2s ease;
    flex: 0 0 auto;
}
.cea-ach.open .chev {
    transform: rotate(180deg);
}
.cea-ach .task {
    color: var(--cea-muted);
}

.subgoals-head {
    margin-top: 10px;
    font-weight: 700;
    font-size: 10.5px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--cea-muted);
}
.subgoals {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.subgoal {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 8px;
    padding: 5px 9px;
    font-weight: 600;
    font-size: 11.5px;
    /* not yet found: present but quiet, so the gaps are countable at a glance */
    border: 1px dashed var(--cea-line-soft);
    color: var(--cea-muted);
}
.subgoal.found {
    border: 1px solid color-mix(in srgb, var(--cea-tone) 55%, transparent);
    background: color-mix(in srgb, var(--cea-tone) 18%, var(--cea-panel));
    color: var(--cea-ink);
}

/* just earned: the row announces itself once */
.cea-ach.fresh {
    animation: cea-earned 0.9s ease 1;
}
.cea-new.moved {
    color: var(--cea-tier-2);
}
@keyframes cea-earned {
    0% {
        box-shadow: 0 0 0 0 color-mix(in srgb, var(--cea-tone) 70%, transparent);
    }
    40% {
        box-shadow: 0 0 0 6px
            color-mix(in srgb, var(--cea-tone) 30%, transparent);
    }
    100% {
        box-shadow: 0 0 0 0 color-mix(in srgb, var(--cea-tone) 0%, transparent);
    }
}
</style>
