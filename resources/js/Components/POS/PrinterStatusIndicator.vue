<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Badge, Receipt } from 'lucide-vue-next';

const page = usePage();

// Printer statuses with priorities: red (error) > blue (processing) > green (ready)
const badgePrinterStatus = ref({ status: 'idle', severity: 'success' });
const receiptPrinterStatus = ref({ status: 'idle', severity: 'success' });

// Get color class based on severity
const getStatusColor = (severity) => {
    switch (severity) {
        case 'danger': return 'text-red-500';
        case 'info': return 'text-blue-500';
        default: return 'text-green-500';
    }
};

// Setup Echo subscription for printer status updates
onMounted(() => {
    if (window.Echo) {
        window.Echo.channel('pos-printers')
            .listen('.printer.status.updated', (event) => {
                console.log('📡 Received printer status update:', event);
                
                const statusData = {
                    status: event.status,
                    severity: event.status_severity,
                    label: event.status_label,
                    error_message: event.error_message,
                    timestamp: event.timestamp
                };

                // Update the appropriate printer status
                if (event.printer_type === 'badge') {
                    badgePrinterStatus.value = statusData;
                } else if (event.printer_type === 'receipt') {
                    receiptPrinterStatus.value = statusData;
                }
            });
    }
});

// Clean up Echo subscription
onUnmounted(() => {
    if (window.Echo) {
        window.Echo.leaveChannel('pos-printers');
    }
});
</script>

<template>
    <div class="flex items-center space-x-3">
        <!-- Badge Printer Status -->
        <div :title="`Badge Printer: ${badgePrinterStatus.label || badgePrinterStatus.status}`">
            <Badge 
                :size="18"
                :class="[
                    'transition-colors duration-300',
                    getStatusColor(badgePrinterStatus.severity)
                ]"
            />
        </div>

        <!-- Receipt Printer Status -->
        <div :title="`Receipt Printer: ${receiptPrinterStatus.label || receiptPrinterStatus.status}`">
            <Receipt 
                :size="18"
                :class="[
                    'transition-colors duration-300',
                    getStatusColor(receiptPrinterStatus.severity)
                ]"
            />
        </div>
    </div>
</template>

<style scoped>
.shadow-green-500\/50 {
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.5);
}

.shadow-blue-500\/50 {
    box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
}

.shadow-red-500\/50 {
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
}
</style>