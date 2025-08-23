<script setup>
import { computed } from 'vue';
import Badge from 'primevue/badge';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    qzStatus: {
        type: Object,
        default: () => ({
            qz_status: 'disconnected',
            is_connected: false,
            pending_jobs: 0,
            last_seen: null
        })
    },
    showPendingJobs: {
        type: Boolean,
        default: true
    }
});

const page = usePage();
const machine = computed(() => page.props.auth.machine);

// Use the status from props instead of local state
const status = computed(() => props.qzStatus);

const statusText = computed(() => {
    return status.value.is_connected ? 'QZ Connected' : 'QZ Disconnected';
});

// No need for polling anymore since we get updates via props from parent
</script>

<template>
    <!-- Only show the entire component if this machine should discover printers -->
    <div v-if="machine?.should_discover_printers" class="flex items-center space-x-3">
        <!-- QZ Connection Status -->
        <div class="flex items-center space-x-2">
            <div 
                :class="[
                    'w-3 h-3 rounded-full transition-all duration-300',
                    status.is_connected ? 'bg-green-500 shadow-green-500/50 shadow-lg' : 'bg-red-500 shadow-red-500/50 shadow-lg'
                ]"
            ></div>
            <span class="text-sm font-medium text-slate-700">
                {{ statusText }}
            </span>
        </div>

        <!-- Pending Jobs Count -->
        <div 
            v-if="showPendingJobs && status.pending_jobs > 0"
            class="flex items-center space-x-1"
        >
            <i class="pi pi-print text-slate-500"></i>
            <Badge 
                :value="status.pending_jobs" 
                :severity="status.pending_jobs > 5 ? 'danger' : status.pending_jobs > 0 ? 'warning' : 'success'"
                class="text-xs"
            />
            <span class="text-xs text-slate-600">pending</span>
        </div>

        <!-- Last Seen Indicator (for debugging) -->
        <div 
            v-if="status.last_seen && !status.is_connected"
            class="text-xs text-slate-400"
            :title="`Last seen: ${new Date(status.last_seen).toLocaleString()}`"
        >
            <i class="pi pi-clock"></i>
            {{ formatLastSeen(status.last_seen) }}
        </div>
    </div>
</template>

<script>
export default {
    methods: {
        formatLastSeen(dateString) {
            if (!dateString) return '';
            
            const lastSeen = new Date(dateString);
            const now = new Date();
            const diffMs = now - lastSeen;
            const diffMins = Math.floor(diffMs / 60000);
            
            if (diffMins < 1) return 'now';
            if (diffMins < 60) return `${diffMins}m ago`;
            
            const diffHours = Math.floor(diffMins / 60);
            if (diffHours < 24) return `${diffHours}h ago`;
            
            return `${Math.floor(diffHours / 24)}d ago`;
        }
    }
}
</script>

<style scoped>
.shadow-green-500\/50 {
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.5);
}

.shadow-red-500\/50 {
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
}
</style>