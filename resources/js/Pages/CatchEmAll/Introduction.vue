<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import CatchEmAllLayout from '@/Layouts/CatchEmAllLayout.vue'

defineProps<{ flash?: any }>()

const form = useForm({})

function start() {
    form.post(route('catch-em-all.introduction.complete'))
}
</script>

<template>
    <CatchEmAllLayout title="Welcome" subtitle="Eurofurence 30" :count="null" :flash="flash">
        <div class="cea-card">
            <div class="cea-name" style="font-size: 22px">Collect the fursuiters you meet</div>
            <p class="intro">
                Every fursuit badge here carries a five-character code. Ask for it, type it in, and that
                suiter joins your collection.
            </p>
            <p class="intro">
                Ask before you photograph or approach anyone. A suiter with a handler, or one holding a
                closed pose, is busy.
            </p>
            <p class="intro">
                Codes also work straight from the QR on the badge, and staff hand out a few special ones.
            </p>
            <button class="cea-btn" style="margin-top: 18px" :disabled="form.processing" @click="start">
                Start collecting
            </button>
        </div>
        <p class="cea-hint" style="margin-top: 14px">Shown once per event.</p>
    </CatchEmAllLayout>
</template>

<style scoped>
.intro {
    color: #c8d1e2;
    font-size: 14px;
    margin: 12px 0 0;
}
</style>
