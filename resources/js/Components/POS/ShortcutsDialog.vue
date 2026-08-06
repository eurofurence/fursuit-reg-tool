<template>
  <Dialog v-model:visible="visible" modal header="Keyboard Shortcuts" :style="{ width: '500px' }" :pt="posDialogPt">
    <div class="space-y-3">
      <h4 class="pos-label mb-2">Numpad</h4>
      <div><b>Attendee ID:</b> <kbd>0</kbd>–<kbd>9</kbd> (always types into the lookup field)</div>
      <div><b>Search / confirm:</b> <kbd>Enter</kbd></div>
      <div><b>Search Attendee:</b> <kbd>/</kbd> (clears the field on the dashboard)</div>
      <div><b>Start Card Payment:</b> <kbd>/</kbd> (on attendee &amp; payment screens)</div>
      <div><b>Handout All Items:</b> <kbd>*</kbd></div>
      <div><b>Go Back:</b> <kbd>Backspace</kbd> (on attendee page)</div>

      <h4 class="pos-label mb-2 mt-4">Function keys</h4>
      <div><b>Cash Register:</b> <kbd>F2</kbd></div>
      <div><b>Badge Management:</b> <kbd>F3</kbd></div>
      <div><b>Print Queue:</b> <kbd>F4</kbd></div>
      <div><b>Statistics:</b> <kbd>F6</kbd></div>
      <div><b>This dialog:</b> <kbd>F1</kbd></div>

      <h4 class="pos-label mb-2 mt-4">Keyboard</h4>
      <div><b>Search Attendee:</b> <kbd>Ctrl</kbd> + <kbd>K</kbd></div>
      <div><b>Start Payment for All:</b> <kbd>Ctrl</kbd> + <kbd>P</kbd></div>
      <div><b>Handout All Items:</b> <kbd>Ctrl</kbd> + <kbd>H</kbd></div>
      <div><b>Log Out:</b> <kbd>Backspace</kbd> ×5</div>
    </div>
    <template #footer>
      <button type="button" class="pos-btn pos-btn--primary" autofocus @click="visible = false">
        Close <span class="pos-kcap">Esc</span>
      </button>
    </template>
  </Dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import { posDialogPt } from '@/Components/POS/posDialog.js';
const visible = defineModel('visible', { type: Boolean, default: false });
</script>

<style scoped>
kbd {
  background: rgb(var(--pos-panel-2));
  border-radius: var(--pos-radius);
  border: 1px solid rgb(var(--pos-line-strong));
  color: rgb(var(--pos-text));
  padding: 2px 6px;
  font-size: 0.95em;
  font-family: var(--pos-num);
}
</style>
