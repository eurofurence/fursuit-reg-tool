<script setup lang="ts">
/**
 * Flash messages as toasts.
 *
 * They used to be message panels rendered in the page flow, which pushed the
 * whole screen down when one arrived and stayed until the next navigation. A
 * catch result, a rate-limit warning and "profile updated" are all transient, so
 * they sit above the nav, stack, fade after a few seconds, and never move the
 * page under your thumb.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Check, Info, TriangleAlert, X } from 'lucide-vue-next'

const props = defineProps<{
    flash?: { message?: string | null; error?: string | null; success?: string | null } | null
}>()

type Kind = 'success' | 'error' | 'info'
type Toast = { id: number; kind: Kind; text: string }

const page = usePage()
const toasts = ref<Toast[]>([])
const timers = new Map<number, number>()
let nextId = 1

const current = computed(() => {
    const shared = (page.props.flash ?? {}) as Record<string, string | null>
    return {
        error: props.flash?.error ?? shared.error ?? null,
        success: props.flash?.success ?? shared.success ?? null,
        message: props.flash?.message ?? shared.message ?? null,
    }
})

function push(kind: Kind, text: string) {
    /* the same message twice in a row is one event, not two toasts */
    if (toasts.value.some(t => t.text === text && t.kind === kind)) return
    const id = nextId++
    toasts.value.push({ id, kind, text })
    timers.set(id, window.setTimeout(() => dismiss(id), 4200))
}

function dismiss(id: number) {
    const timer = timers.get(id)
    if (timer) window.clearTimeout(timer)
    timers.delete(id)
    toasts.value = toasts.value.filter(t => t.id !== id)
}

watch(current, value => {
    if (value.error) push('error', value.error)
    if (value.success) push('success', value.success)
    if (value.message && value.message !== value.success) push('info', value.message)
}, { immediate: true, deep: true })

onBeforeUnmount(() => {
    timers.forEach(timer => window.clearTimeout(timer))
    timers.clear()
})

const ICONS = { success: Check, error: TriangleAlert, info: Info }
</script>

<template>
    <Teleport to="body">
        <div class="cea-toasts" role="status" aria-live="polite">
            <TransitionGroup name="cea-toast">
                <div v-for="toast in toasts" :key="toast.id" class="cea-toast" :class="toast.kind">
                    <component :is="ICONS[toast.kind]" :size="17" class="icon" />
                    <span>{{ toast.text }}</span>
                    <button class="close" aria-label="Dismiss" @click="dismiss(toast.id)">
                        <X :size="15" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<style scoped>
.cea-toasts {
    position: fixed;
    left: 12px;
    right: 12px;
    bottom: calc(84px + env(safe-area-inset-bottom, 0px));
    z-index: 45;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}
.cea-toast {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 12px;
    background: var(--cea-panel-2);
    border: 1px solid var(--cea-line-soft);
    color: var(--cea-ink);
    font-size: 14px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .45);
    pointer-events: auto;
}
.cea-toast span { flex: 1; min-width: 0; }
.cea-toast .icon { flex: 0 0 auto; color: var(--cea-muted); }
.cea-toast.success { border-color: var(--cea-accent); background: #0d1f1d; }
.cea-toast.success .icon { color: var(--cea-accent-bright); }
.cea-toast.error { border-color: #6d3040; background: #1e1319; }
.cea-toast.error .icon { color: #eb9aa6; }
.cea-toast .close {
    background: none;
    border: 0;
    color: var(--cea-muted);
    cursor: pointer;
    padding: 4px;
    flex: 0 0 auto;
}
.cea-toast-enter-active, .cea-toast-leave-active { transition: opacity .2s ease, transform .2s ease; }
.cea-toast-enter-from, .cea-toast-leave-to { opacity: 0; transform: translateY(8px); }
</style>
