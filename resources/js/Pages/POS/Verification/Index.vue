<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import POSLayout from "@/Layouts/POSLayout.vue";
import { usePosKeyboard } from "@/composables/usePosKeyboard";

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    stats: Object,
    badgeRange: Object,
    recent: {
        type: Array,
        default: () => [],
    },
    result: {
        type: Object,
        default: null,
    },
});

const badgeIdInput = ref(null);

const form = useForm({
    badge_id: "",
});

function focusInput() {
    nextTick(() => badgeIdInput.value?.focus());
}

function submit() {
    if (! form.badge_id || form.processing) {
        return;
    }

    form.post(route("pos.verification.store"), {
        preserveScroll: true,
        onFinish: () => {
            // Cleared either way: a rejected number stays visible in the banner,
            // and leaving it in the field means the next card gets typed onto
            // the end of it.
            form.badge_id = "";
            focusInput();
        },
    });
}

// The numpad has a minus key, so a second copy can be typed as 1234-2 without
// leaving the keypad. A bare number is the first copy; the server decides that.
function keyPress(key) {
    if (key === "delete") {
        form.badge_id = form.badge_id.slice(0, -1);
    } else if (key === "enter") {
        submit();
    } else {
        form.badge_id += key;
    }
    focusInput();
}

function revert(badge) {
    router.post(route("pos.verification.revert", { badge: badge.id }), {}, {
        preserveScroll: true,
        onFinish: focusInput,
    });
}

// Scanners and the keypad emit stray characters; digits and one dash are the
// whole alphabet of a badge number.
watch(() => form.badge_id, (value) => {
    const cleaned = (value || "").replace(/[^\d-]/g, "").slice(0, 12);
    if (cleaned !== value) {
        form.badge_id = cleaned;
    }
});

// Numpad "/" is "start over" here rather than "go to the dashboard": the field
// on this screen is the one the clerk is already typing into.
usePosKeyboard({
    onNumpadDivide: () => {
        form.badge_id = "";
        focusInput();
    },
});

/* --- Keeping the screen alive -------------------------------------------- */

// Working a crate means long silences: read a card, put it on the pile, pick up
// the next one. The auto-lock is suspended for this route (InactivityTimer), and
// this poll is the other half of it - it keeps the session from expiring under
// the same silence, and refreshes the list when a second desk works the same
// crate. Nothing typed is held in the browser, so a reload loses nothing.
let keepAliveTimer = null;

onMounted(() => {
    focusInput();
    keepAliveTimer = setInterval(() => {
        router.reload({
            only: ["stats", "recent"],
            preserveState: true,
            preserveScroll: true,
        });
    }, 60000);
});

onUnmounted(() => clearInterval(keepAliveTimer));

/* --- Presentation --------------------------------------------------------- */

const rangeLabel = computed(() => {
    const min = props.badgeRange?.min;
    const max = props.badgeRange?.max;

    if (min !== null && min !== undefined && max !== null && max !== undefined) {
        return `attendee ${min} to ${max}`;
    }
    if (min !== null && min !== undefined) {
        return `attendee ${min} and up`;
    }
    if (max !== null && max !== undefined) {
        return `attendee up to ${max}`;
    }

    return "every badge of the event";
});

const statTiles = computed(() => [
    {
        label: "Checked off",
        value: props.stats?.verified ?? 0,
        sub: `of ${props.stats?.printed ?? 0} printed`,
        primary: true,
        progress: props.stats?.printed
            ? Math.round((props.stats.verified / props.stats.printed) * 100)
            : 0,
    },
    {
        label: "Still missing",
        value: props.stats?.missing ?? 0,
        sub: "printed, never seen at the desk",
    },
    {
        label: "This desk counts",
        value: props.stats?.printed ?? 0,
        sub: rangeLabel.value,
    },
]);

const resultClass = computed(() => ({
    ok: "border-pos-good text-pos-good",
    duplicate: "border-pos-warn text-pos-warn",
    reverted: "border-pos-line text-pos-muted",
    error: "border-pos-bad text-pos-bad",
}[props.result?.status] ?? "border-pos-line text-pos-muted"));

function verifiedTime(row) {
    if (! row.verified_at) {
        return "";
    }

    return new Date(row.verified_at).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    });
}
</script>

<template>
    <Head>
        <title>POS - Badge Verification</title>
    </Head>

    <div class="w-full flex-1 flex flex-col gap-2">
        <div class="pos-block pos-block--cols">
            <div
                v-for="tile in statTiles"
                :key="tile.label"
                class="pos-stat"
                :class="tile.primary ? 'pos-stat--primary' : ''"
            >
                <span class="pos-stat__k">{{ tile.label }}</span>
                <span class="pos-stat__v">{{ tile.value }}</span>
                <span class="pos-stat__sub">{{ tile.sub }}</span>
                <span v-if="tile.progress !== undefined" class="pos-meter" :aria-label="`${tile.progress}% checked off`">
                    <span class="pos-meter__fill" :style="{ width: `${Math.min(100, tile.progress)}%` }"></span>
                </span>
            </div>
        </div>

        <div class="flex-1 grid grid-cols-1 lg:grid-cols-3 gap-2">
            <section class="lg:col-span-2 pos-card flex flex-col">
                <div class="pos-card__head">
                    <h1>Check the box</h1>
                    <div class="flex items-center gap-3 text-xs text-pos-muted">
                        <span><span class="pos-kcap mr-1">0-9</span>badge</span>
                        <span><span class="pos-kcap mr-1">-</span>copy</span>
                        <span><span class="pos-kcap mr-1">Enter</span>check off</span>
                        <span><span class="pos-kcap mr-1">/</span>clear</span>
                    </div>
                </div>

                <p
                    v-if="result"
                    class="mb-2 px-3 py-2 rounded-pos border text-sm font-semibold"
                    :class="resultClass"
                    aria-live="polite"
                >
                    {{ result.message }}
                </p>
                <p v-else class="mb-2 px-3 py-2 text-sm text-pos-muted">
                    Type the number on the card and press Enter. A bare number is copy 1,
                    so 1234 checks off 1234-1; a second copy needs 1234-2 in full.
                </p>

                <form class="flex gap-2" @submit.prevent="submit">
                    <input
                        ref="badgeIdInput"
                        v-model="form.badge_id"
                        class="pos-field"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="Badge number"
                        maxlength="12"
                    />
                    <button
                        type="submit"
                        class="pos-btn pos-btn--primary pos-btn--commit px-8"
                        :disabled="!form.badge_id || form.processing"
                    >
                        Check off <span class="pos-kcap">Enter</span>
                    </button>
                </form>

                <div class="flex-1 flex items-center justify-center mt-2">
                    <div class="pos-keypad w-full max-w-md h-full max-h-[24rem]">
                        <button v-for="n in ['7','8','9','4','5','6','1','2','3']" :key="n"
                                type="button" class="pos-key" @click="keyPress(n)">{{ n }}</button>
                        <button type="button" class="pos-key" @click="keyPress('-')">-</button>
                        <button type="button" class="pos-key" @click="keyPress('0')">0</button>
                        <button type="button" class="pos-key pos-key--wide" @click="keyPress('delete')">Delete</button>
                        <button type="button" class="pos-key pos-key--go col-span-3" @click="keyPress('enter')">Check off</button>
                    </div>
                </div>
            </section>

            <section class="pos-card flex flex-col self-start w-full">
                <div class="pos-card__head">
                    <h2>Just checked off</h2>
                    <span class="text-xs text-pos-muted">{{ recent.length }} shown</span>
                </div>

                <p v-if="!recent.length" class="text-sm text-pos-muted px-1 py-4">
                    Nothing checked off yet.
                </p>

                <div v-else class="pos-block pos-block--rows w-full max-h-[32rem] overflow-y-auto">
                    <div v-for="row in recent" :key="row.id" class="pos-row">
                        <div class="pos-row__body">
                            <span class="pos-row__title">{{ row.custom_id }}</span>
                            <span class="pos-row__sub">
                                {{ row.fursuit_name || 'unknown fursuit' }}
                                <template v-if="row.owner_name"> · {{ row.owner_name }}</template>
                                · {{ verifiedTime(row) }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="pos-btn pos-btn--quiet"
                            @click="revert(row)"
                        >
                            Undo
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
