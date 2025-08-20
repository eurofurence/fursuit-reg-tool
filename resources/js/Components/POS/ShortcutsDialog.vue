<template>
  <Dialog v-model:visible="visible" modal header="Keyboard Shortcuts" :style="{ width: '400px' }">
    <div class="space-y-3">
      <div><b>Search Attendee:</b> <kbd>Ctrl</kbd> + <kbd>K</kbd></div>
      <div><b>Start Payment for All:</b> <kbd>Ctrl</kbd> + <kbd>P</kbd></div>
      <div><b>Handout All Items:</b> <kbd>Ctrl</kbd> + <kbd>H</kbd></div>
      <div><b>Confirm Dialogs:</b> <kbd>Enter</kbd></div>
    </div>
    <template #footer>
      <Button label="Close" @click="visible = false" autofocus />
    </template>
  </Dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
const visible = defineModel('visible', { type: Boolean, default: false });
</script>

<style scoped>
kbd {
  background: #f3f4f6;
  border-radius: 4px;
  border: 1px solid #d1d5db;
  padding: 2px 6px;
  font-size: 0.95em;
  font-family: monospace;
}
</style>
