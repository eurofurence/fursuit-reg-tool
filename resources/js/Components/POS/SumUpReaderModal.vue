<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import RadioButton from 'primevue/radiobutton';
import { posDialogPt } from '@/Components/POS/posDialog.js';

const props = defineProps({
    visible: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:visible']);

const page = usePage();
const machine = computed(() => page.props.auth.machine);
const currentReaderId = computed(() => machine.value?.sumup_reader?.id ?? null);

/*
 * The list is fetched when the dialog opens, not carried on every POS page. It
 * is a handful of rows that change about once a convention, and the header only
 * ever needs the name of the one already selected.
 */
const readers = ref([]);
const loading = ref(false);
const loadError = ref(null);

const form = useForm({
    sumup_reader_id: null,
});

async function loadReaders() {
    loading.value = true;
    loadError.value = null;

    try {
        const response = await fetch(route('pos.machine.sumup-readers'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (! response.ok) {
            throw new Error(response.status);
        }

        const data = await response.json();
        readers.value = data.readers ?? [];
        form.sumup_reader_id = data.current_id ?? null;
    } catch (e) {
        loadError.value = 'Could not load the reader list. Check the network and try again.';
    } finally {
        loading.value = false;
    }
}

const localVisible = ref(false);

watch(() => props.visible, (open) => {
    localVisible.value = open;

    if (open) {
        form.clearErrors();
        form.sumup_reader_id = currentReaderId.value;
        loadReaders();
    }
});

watch(localVisible, (open) => emit('update:visible', open));

const hasChanges = computed(() => form.sumup_reader_id !== currentReaderId.value);

function save() {
    form.put(route('pos.machine.sumup-reader'), {
        preserveScroll: true,
        onSuccess: () => {
            localVisible.value = false;
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="localVisible"
        modal
        :closable="true"
        class="mx-4"
        :style="{ width: '28rem' }"
        header="Card Reader"
        :draggable="false"
        :pt="posDialogPt"
    >
        <div class="flex flex-col gap-3">
            <p class="text-sm text-pos-muted">
                Which SumUp terminal this till sends card payments to.
            </p>

            <div v-if="loading" class="flex items-center gap-2 px-3 py-6 text-pos-muted">
                <i class="pi pi-spin pi-spinner"></i> Loading readers...
            </div>

            <div
                v-else-if="loadError"
                class="px-3 py-2 rounded-pos border border-pos-bad text-pos-bad text-sm font-semibold"
            >
                {{ loadError }}
            </div>

            <div v-else class="pos-block pos-block--rows max-h-80 overflow-y-auto">
                <div
                    v-for="reader in readers"
                    :key="reader.id"
                    class="flex items-center gap-3 px-3 min-h-pos-touch"
                >
                    <RadioButton
                        v-model="form.sumup_reader_id"
                        :inputId="`reader_${reader.id}`"
                        :value="reader.id"
                    />
                    <label
                        :for="`reader_${reader.id}`"
                        class="flex-1 cursor-pointer font-medium text-pos-text py-2"
                    >
                        {{ reader.name }}
                        <!--
                            Named rather than blocked: taking over the neighbour's
                            terminal is a normal move when one dies mid-queue. The
                            clerk only has to know they are doing it.
                        -->
                        <span v-if="reader.in_use_by.length" class="block text-xs font-normal text-pos-warn">
                            also used by {{ reader.in_use_by.join(', ') }}
                        </span>
                    </label>
                    <span v-if="reader.id === currentReaderId" class="pos-pill">Current</span>
                </div>

                <div class="flex items-center gap-3 px-3 min-h-pos-touch">
                    <RadioButton
                        v-model="form.sumup_reader_id"
                        inputId="reader_none"
                        :value="null"
                    />
                    <label for="reader_none" class="flex-1 cursor-pointer font-medium text-pos-text py-2">
                        No card reader
                        <span class="block text-xs font-normal text-pos-muted">cash only at this till</span>
                    </label>
                    <span v-if="currentReaderId === null" class="pos-pill">Current</span>
                </div>
            </div>

            <div
                v-if="form.errors.sumup_reader_id"
                class="px-3 py-2 rounded-pos border border-pos-bad text-pos-bad text-sm font-semibold"
            >
                {{ form.errors.sumup_reader_id }}
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                class="pos-btn"
                :disabled="form.processing"
                @click="localVisible = false"
            >
                Cancel
            </button>
            <button
                type="button"
                class="pos-btn pos-btn--primary"
                :disabled="form.processing || loading || ! hasChanges"
                @click="save"
            >
                <i class="pi" :class="form.processing ? 'pi-spin pi-spinner' : 'pi-check'"></i>
                Save
            </button>
        </template>
    </Dialog>
</template>
