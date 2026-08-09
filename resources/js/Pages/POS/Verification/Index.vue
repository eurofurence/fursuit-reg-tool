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

// Counters read as one line of text rather than as the dashboard's stat tiles.
// The two screens are one keystroke apart and both are a number field over a
// keypad, so this one deliberately does not look like the other: typing an
// attendee number into the wrong one silently checks a card off.
const counters = computed(() => [
    { label: "checked off", value: props.stats?.verified ?? 0 },
    { label: "still missing", value: props.stats?.missing ?? 0, bad: true },
    { label: `printed · ${rangeLabel.value}`, value: props.stats?.printed ?? 0 },
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

    <!-- Nothing on this screen is shaped like the dashboard: the banner runs the
         full width, the list is the big half, and the entry column sits on the
         right with a keypad half the size. The two screens are one keystroke
         apart and a number typed into the wrong one checks a card off silently. -->
    <div class="w-full flex-1 flex flex-col gap-2">
        <div class="rounded-pos border-2 border-pos-warn bg-pos-warn/10 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-base font-bold tracking-wide uppercase text-pos-warn">
                    Verification mode
                </h1>
                <p class="text-xs text-pos-muted">
                    Reading numbers off the cards in the box. Nothing is handed out and no attendee is looked up here.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <div v-for="counter in counters" :key="counter.label" class="text-right">
                    <span
                        class="block pos-num text-2xl font-bold leading-none"
                        :class="counter.bad && counter.value > 0 ? 'text-pos-bad' : ''"
                    >{{ counter.value }}</span>
                    <span class="block text-[0.68rem] uppercase tracking-wide text-pos-muted">{{ counter.label }}</span>
                </div>
            </div>
        </div>

        <div class="flex-1 grid grid-cols-1 lg:grid-cols-5 gap-2">
            <section class="lg:col-span-3 pos-card flex flex-col">
                <div class="pos-card__head">
                    <h2>Checked off in this box</h2>
                    <span class="text-xs text-pos-muted">
                        {{ recent.length ? `last ${recent.length}` : 'nothing yet' }}
                    </span>
                </div>

                <p v-if="!recent.length" class="text-sm text-pos-muted px-1 py-4">
                    Type the number printed on the first card to start. Every card you check off
                    lands here, newest first, with an Undo beside it.
                </p>

                <div v-else class="pos-block pos-block--rows w-full flex-1 max-h-[34rem] overflow-y-auto">
                    <div v-for="row in recent" :key="row.id" class="pos-row">
                        <div class="pos-row__body">
                            <span class="pos-row__title pos-num text-lg">{{ row.custom_id }}</span>
                            <span class="pos-row__sub">
                                {{ verifiedTime(row) }}
                                · {{ row.fursuit_name || 'unknown fursuit' }}
                                <template v-if="row.owner_name"> · {{ row.owner_name }}</template>
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

            <section class="lg:col-span-2 pos-card flex flex-col self-start w-full">
                <div class="pos-card__head">
                    <h2>Card number</h2>
                    <div class="flex items-center gap-2 text-xs text-pos-muted">
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
                <p v-else class="mb-2 text-xs text-pos-muted">
                    A bare number is copy 1: 1234 checks off 1234-1. A second copy needs
                    1234-2 typed in full.
                </p>

                <form class="flex flex-col gap-2" @submit.prevent="submit">
                    <input
                        ref="badgeIdInput"
                        v-model="form.badge_id"
                        class="pos-field text-center"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="0000-0"
                        maxlength="12"
                    />
                    <button
                        type="submit"
                        class="pos-btn pos-btn--primary pos-btn--commit"
                        :disabled="!form.badge_id || form.processing"
                    >
                        Check off <span class="pos-kcap">Enter</span>
                    </button>
                </form>

                <!-- Half the dashboard keypad's size: the desk numpad does the typing,
                     and this is the fallback for a touchscreen. -->
                <div class="pos-keypad w-full max-w-[15rem] mx-auto mt-3">
                    <button v-for="n in ['7','8','9','4','5','6','1','2','3']" :key="n"
                            type="button" class="pos-key" @click="keyPress(n)">{{ n }}</button>
                    <button type="button" class="pos-key" @click="keyPress('-')">-</button>
                    <button type="button" class="pos-key" @click="keyPress('0')">0</button>
                    <button type="button" class="pos-key pos-key--wide" @click="keyPress('delete')">Delete</button>
                </div>
            </section>
        </div>
    </div>
</template>
