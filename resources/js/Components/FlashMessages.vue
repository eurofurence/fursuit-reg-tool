<script setup lang="ts">
  import {usePage} from "@inertiajs/vue3";
  import {computed} from "vue";
  import Message from "@/Components/UI/UiMessage.vue";

  const props = defineProps<{
    flash?: { message?: string | null, error?: string | null, success?: string | null }
  }>()
  const page = usePage()

  const message = computed(() => props.flash?.message ?? page.props.flash?.message)
  const error = computed(() => props.flash?.error ?? page.props.flash?.error)
  // this read flash.message, so an info message rendered twice: once as info,
  // once as a green success
  const success = computed(() => props.flash?.success ?? page.props.flash?.success)
</script>

<template>
  <Message
      v-if="error"
      severity="error"
      :closable="true">
    {{ error }}
  </Message>
  <Message
      v-if="message"
      severity="info"
      :closable="true">
    {{ message }}
  </Message>
  <Message
      v-if="success"
      severity="success"
      :closable="true">
    {{ success }}
  </Message>
</template>
