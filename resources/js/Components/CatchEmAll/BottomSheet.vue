<script setup lang="ts">
/**
 * Bottom sheet you can throw away: drag it down past 110px, or flick it, and it
 * dismisses; anything shorter springs back. Escape and a tap on the scrim also
 * close it, so it is dismissable without knowing the gesture.
 */
import { ref, watch, onBeforeUnmount } from 'vue'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const sheet = ref<HTMLElement | null>(null)
const dragging = ref(false)
const scrimOpacity = ref<number | null>(null)

let startY: number | null = null
let lastY = 0
let lastAt = 0
let velocity = 0

function down(event: PointerEvent) {
    if ((event.target as HTMLElement).closest('button, a, input, textarea')) return
    startY = event.clientY
    lastY = event.clientY
    lastAt = performance.now()
    velocity = 0
    dragging.value = true
    sheet.value?.setPointerCapture(event.pointerId)
}

function move(event: PointerEvent) {
    if (startY === null || !sheet.value) return
    const dy = Math.max(0, event.clientY - startY)
    const now = performance.now()
    if (now > lastAt) {
        velocity = (event.clientY - lastY) / (now - lastAt)
        lastAt = now
        lastY = event.clientY
    }
    sheet.value.style.transform = `translateY(${dy}px)`
    scrimOpacity.value = Math.max(0, 1 - dy / 320)
}

function up(event: PointerEvent) {
    if (startY === null || !sheet.value) return
    const dy = Math.max(0, event.clientY - startY)
    startY = null
    dragging.value = false
    scrimOpacity.value = null
    if (dy > 110 || velocity > 0.65) {
        emit('close')
    } else {
        sheet.value.style.transform = ''
    }
}

function reset() {
    if (sheet.value) sheet.value.style.transform = ''
    scrimOpacity.value = null
}
watch(() => props.open, reset)

function onKey(event: KeyboardEvent) {
    if (event.key === 'Escape' && props.open) emit('close')
}
window.addEventListener('keydown', onKey)
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
    <Teleport to="body">
        <Transition name="cea-fade">
            <div
                v-if="open"
                class="cea-scrim"
                :style="scrimOpacity === null ? undefined : { opacity: scrimOpacity }"
                @click.self="emit('close')"
            >
                <Transition name="cea-sheet" appear>
                    <div
                        v-if="open"
                        ref="sheet"
                        class="cea-sheet"
                        :style="dragging ? { transition: 'none' } : undefined"
                        @pointerdown="down"
                        @pointermove="move"
                        @pointerup="up"
                        @pointercancel="up"
                        @wheel.passive="(e) => e.deltaY > 24 && emit('close')"
                    >
                        <div class="cea-grab" />
                        <slot />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
