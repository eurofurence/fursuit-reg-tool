<script setup lang="ts">
import { CirclePlus } from 'lucide-vue-next'

defineProps<{
    title?: string
    subtitle?: string
}>()
</script>

<template>
    <!-- Header -->
    <div class="bg-gray-800 shadow-sm border-b border-gray-700">
        <div class="px-4 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                    <CirclePlus class="w-5 h-5 text-white" />
                </div>
                <div>
                    <h1 class="text-lg font-bold text-gray-100">
                        {{ title || 'Fursuit Catch em All' }}
                    </h1>
                    <p class="text-xs text-gray-400">
                        {{ subtitle || 'Catch them all!' }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
