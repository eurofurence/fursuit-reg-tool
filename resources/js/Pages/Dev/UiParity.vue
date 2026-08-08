<script setup>
/*
 * TEMPORARY. Renders each PrimeVue widget beside its Components/UI replacement
 * so the two can be compared pixel for pixel. Delete with the /dev-ui-parity
 * route once the migration is done.
 */
import { ref } from 'vue';

import PvButton from 'primevue/button';
import PvCard from 'primevue/card';
import PvTag from 'primevue/tag';
import PvMessage from 'primevue/message';
import PvDialog from 'primevue/dialog';

import UiButton from '@/Components/UI/UiButton.vue';
import UiCard from '@/Components/UI/UiCard.vue';
import UiTag from '@/Components/UI/UiTag.vue';
import UiMessage from '@/Components/UI/UiMessage.vue';
import UiDialog from '@/Components/UI/UiDialog.vue';

const SEVERITIES = [null, 'secondary', 'success', 'info', 'warning', 'help', 'danger', 'contrast'];
const MESSAGE_SEVERITIES = ['info', 'success', 'warn', 'error'];

const pvDialog = ref(false);
const uiDialog = ref(false);
</script>

<template>
    <div class="p-8 space-y-10 bg-surface-100 min-h-screen font-main">
        <h1 class="text-2xl font-bold">UI parity — PrimeVue (left) vs Components/UI (right)</h1>

        <section>
            <h2 class="text-lg font-bold mb-3">Button — solid</h2>
            <div class="grid grid-cols-2 gap-8">
                <div class="flex flex-wrap gap-2 items-end" data-probe="pv-button">
                    <PvButton v-for="s in SEVERITIES" :key="`pb-${s}`" :label="s ?? 'primary'" :severity="s" />
                </div>
                <div class="flex flex-wrap gap-2 items-end" data-probe="ui-button">
                    <UiButton v-for="s in SEVERITIES" :key="`ub-${s}`" :label="s ?? 'primary'" :severity="s" />
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-bold mb-3">Button — sizes, icons, states</h2>
            <div class="grid grid-cols-2 gap-8">
                <div class="flex flex-wrap gap-2 items-end">
                    <PvButton label="small" size="small" />
                    <PvButton label="default" />
                    <PvButton label="large" size="large" />
                    <PvButton label="Print" icon="pi pi-print" />
                    <PvButton label="Next" icon="pi pi-arrow-right" iconPos="right" />
                    <PvButton icon="pi pi-cog" />
                    <PvButton label="Saving" :loading="true" />
                    <PvButton label="Off" :disabled="true" />
                    <PvButton label="text" text severity="danger" />
                    <PvButton label="outlined" outlined severity="success" />
                    <PvButton label="rounded" rounded />
                </div>
                <div class="flex flex-wrap gap-2 items-end">
                    <UiButton label="small" size="small" />
                    <UiButton label="default" />
                    <UiButton label="large" size="large" />
                    <UiButton label="Print" icon="pi pi-print" />
                    <UiButton label="Next" icon="pi pi-arrow-right" iconPos="right" />
                    <UiButton icon="pi pi-cog" />
                    <UiButton label="Saving" :loading="true" />
                    <UiButton label="Off" :disabled="true" />
                    <UiButton label="text" text severity="danger" />
                    <UiButton label="outlined" outlined severity="success" />
                    <UiButton label="rounded" rounded />
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-bold mb-3">Tag</h2>
            <div class="grid grid-cols-2 gap-8">
                <div class="flex flex-wrap gap-2 items-center">
                    <PvTag v-for="s in SEVERITIES" :key="`pt-${s}`" :value="s ?? 'primary'" :severity="s" />
                    <PvTag value="rounded" rounded />
                    <PvTag value="icon" icon="pi pi-check" severity="success" />
                </div>
                <div class="flex flex-wrap gap-2 items-center">
                    <UiTag v-for="s in SEVERITIES" :key="`ut-${s}`" :value="s ?? 'primary'" :severity="s" />
                    <UiTag value="rounded" rounded />
                    <UiTag value="icon" icon="pi pi-check" severity="success" />
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-bold mb-3">Message</h2>
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <PvMessage v-for="s in MESSAGE_SEVERITIES" :key="`pm-${s}`" :severity="s" :closable="false">
                        Severity {{ s }}
                    </PvMessage>
                    <PvMessage severity="info">Closable</PvMessage>
                </div>
                <div>
                    <UiMessage v-for="s in MESSAGE_SEVERITIES" :key="`um-${s}`" :severity="s" :closable="false">
                        Severity {{ s }}
                    </UiMessage>
                    <UiMessage severity="info">Closable</UiMessage>
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-bold mb-3">Card</h2>
            <div class="grid grid-cols-2 gap-8">
                <PvCard>
                    <template #title>Badge #EF29-0042</template>
                    <template #subtitle>Ready for pickup</template>
                    <template #content>
                        <p>A card with title, subtitle, content and footer.</p>
                    </template>
                    <template #footer>
                        <PvButton label="Hand out" />
                    </template>
                </PvCard>
                <UiCard>
                    <template #title>Badge #EF29-0042</template>
                    <template #subtitle>Ready for pickup</template>
                    <template #content>
                        <p>A card with title, subtitle, content and footer.</p>
                    </template>
                    <template #footer>
                        <UiButton label="Hand out" />
                    </template>
                </UiCard>
            </div>
        </section>

        <section>
            <h2 class="text-lg font-bold mb-3">Dialog</h2>
            <div class="grid grid-cols-2 gap-8">
                <div>
                    <PvButton label="Open PrimeVue dialog" @click="pvDialog = true" />
                </div>
                <div>
                    <UiButton label="Open Ui dialog" data-probe="open-ui-dialog" @click="uiDialog = true" />
                </div>
            </div>

            <PvDialog v-model:visible="pvDialog" modal header="Delete Badge" :style="{ width: '28rem' }">
                <p class="mb-5">Are you sure you want to delete your badge?</p>
                <template #footer>
                    <PvButton label="Cancel" severity="secondary" @click="pvDialog = false" />
                    <PvButton label="Delete" severity="danger" @click="pvDialog = false" />
                </template>
            </PvDialog>

            <UiDialog v-model:visible="uiDialog" modal header="Delete Badge" :style="{ width: '28rem' }">
                <p class="mb-5">Are you sure you want to delete your badge?</p>
                <template #footer>
                    <UiButton label="Cancel" severity="secondary" @click="uiDialog = false" />
                    <UiButton label="Delete" severity="danger" @click="uiDialog = false" />
                </template>
            </UiDialog>
        </section>
    </div>
</template>
