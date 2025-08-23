<script setup>
import { Head, Link, usePoll, router } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Card from 'primevue/card';
import { useForm } from 'laravel-precognition-vue-inertia';
import { useToast } from 'primevue/usetoast';
import { ref, computed } from 'vue';
import dayjs from 'dayjs';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    printerStates: Array,
});

const toast = useToast();

// Use Inertia's poll helper to automatically refresh page data every 4 seconds
usePoll(4000);

// Access printers from props (will be updated automatically by usePoll)
const printers = computed(() => props.printerStates || []);

// Manual refresh function using Inertia router
function refreshPrinterStates() {
    router.reload({ only: ['printerStates'] });
}

function getStatusSeverity(status) {
    switch (status) {
        case 'idle': return 'success';
        case 'working': return 'info';
        case 'processing': return 'info';
        case 'paused': return 'warning';
        case 'offline': return 'danger';
        default: return 'secondary';
    }
}

function getStatusLabel(status) {
    switch (status) {
        case 'idle': return 'READY';
        case 'working': return 'WORKING';
        case 'processing': return 'PROCESSING';
        case 'paused': return 'PAUSED';
        case 'offline': return 'OFFLINE';
        default: return 'UNKNOWN';
    }
}

function getStatusIcon(status) {
    switch (status) {
        case 'idle': return 'pi pi-check-circle';
        case 'working': return 'pi pi-spin pi-spinner';
        case 'processing': return 'pi pi-spin pi-spinner';
        case 'paused': return 'pi pi-pause-circle';
        case 'offline': return 'pi pi-exclamation-triangle';
        default: return 'pi pi-question-circle';
    }
}

// Computed stats
const printerStats = computed(() => {
    const stats = {
        total: printers.value.length,
        idle: 0,
        working: 0,
        paused: 0
    };
    
    printers.value.forEach(printer => {
        stats[printer.status] = (stats[printer.status] || 0) + 1;
    });
    
    return stats;
});

async function retryPrinter(printerName) {
    try {
        const response = await fetch(route('pos.printers.retry', { printerName }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            toast.add({
                severity: 'success',
                summary: 'Retry Started',
                detail: `Retrying job on printer ${printerName}`,
                life: 3000
            });
            await refreshPrinterStates();
        } else {
            throw new Error('Failed to retry printer');
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Retry Failed',
            detail: `Failed to retry printer ${printerName}`,
            life: 5000
        });
    }
}

async function skipPrinter(printerName) {
    if (!confirm(`Are you sure you want to skip the current job on ${printerName}?`)) {
        return;
    }
    
    try {
        const response = await fetch(route('pos.printers.skip', { printerName }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            toast.add({
                severity: 'warn',
                summary: 'Job Skipped',
                detail: `Job skipped on printer ${printerName}`,
                life: 3000
            });
            await refreshPrinterStates();
        } else {
            throw new Error('Failed to skip printer job');
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Skip Failed',
            detail: `Failed to skip job on printer ${printerName}`,
            life: 5000
        });
    }
}

async function clearError(printerName) {
    try {
        const response = await fetch(route('pos.printers.clear', { printerName }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            toast.add({
                severity: 'success',
                summary: 'Error Cleared',
                detail: `Error cleared for printer ${printerName}`,
                life: 3000
            });
            await refreshPrinterStates();
        } else {
            throw new Error('Failed to clear printer error');
        }
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Clear Failed',
            detail: `Failed to clear error for printer ${printerName}`,
            life: 5000
        });
    }
}
</script>

<template>
    <Head>
        <title>POS - Printer Management</title>
    </Head>
    
    <div class="p-4">
        <!-- Header -->
        <div class="mb-6">
            <Card class="shadow-lg border-0 bg-gradient-to-r from-blue-600 to-blue-700 text-white">
                <template #content>
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Printer Management</h1>
                                <p class="text-blue-100 text-lg">
                                    Monitor and control all printers across workstations
                                </p>
                            </div>
                            <div class="text-right">
                                <Button 
                                    label="Refresh" 
                                    icon="pi pi-refresh" 
                                    @click="refreshPrinterStates"
                                    severity="secondary"
                                    outlined
                                    class="mb-4"
                                />
                                <div class="text-6xl opacity-20">
                                    <i class="pi pi-print"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-gray-600 text-2xl font-bold">
                            {{ printerStats.total }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">Total Printers</div>
                    </div>
                </template>
            </Card>
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-green-600 text-2xl font-bold">
                            {{ printerStats.idle }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">Idle</div>
                    </div>
                </template>
            </Card>
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-blue-600 text-2xl font-bold">
                            {{ printerStats.working }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">Working</div>
                    </div>
                </template>
            </Card>
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-red-600 text-2xl font-bold">
                            {{ printerStats.paused }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">Paused</div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Printers Table -->
        <Card>
            <template #content>
                <DataTable 
                    :value="printers" 
                    class="p-datatable-sm"
                    :paginator="false"
                    emptyMessage="No printers found"
                    tableStyle="min-width: 50rem"
                >
                    <Column field="name" header="Printer Name" style="width: 200px">
                        <template #body="slotProps">
                            <div class="flex items-center space-x-2">
                                <i :class="getStatusIcon(slotProps.data.status)" 
                                   :style="{ color: slotProps.data.status === 'working' ? '#3b82f6' : slotProps.data.status === 'paused' ? '#dc2626' : '#6b7280' }"></i>
                                <span class="font-semibold">{{ slotProps.data.name }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="status" header="Status" style="width: 120px">
                        <template #body="slotProps">
                            <Tag 
                                :value="getStatusLabel(slotProps.data.status)" 
                                :severity="getStatusSeverity(slotProps.data.status)"
                            />
                        </template>
                    </Column>

                    <Column field="current_job" header="Current Job">
                        <template #body="slotProps">
                            <span v-if="slotProps.data.current_job_id" class="font-mono">
                                #{{ slotProps.data.current_job_id }}
                            </span>
                            <span v-else class="text-gray-400">None</span>
                        </template>
                    </Column>

                    <Column field="machine.name" header="Machine">
                        <template #body="slotProps">
                            <span v-if="slotProps.data.machine?.name" class="text-sm">
                                {{ slotProps.data.machine.name }}
                            </span>
                            <span v-else class="text-gray-400">Unknown</span>
                        </template>
                    </Column>

                    <Column field="last_error_message" header="Last Error">
                        <template #body="slotProps">
                            <span v-if="slotProps.data.last_error_message" 
                                  class="text-red-600 text-sm truncate max-w-xs block" 
                                  :title="slotProps.data.last_error_message">
                                {{ slotProps.data.last_error_message }}
                            </span>
                            <span v-else class="text-gray-400">None</span>
                        </template>
                    </Column>

                    <Column field="last_state_update" header="Last Update" style="width: 140px">
                        <template #body="slotProps">
                            <span class="text-sm text-gray-600">
                                {{ slotProps.data.last_state_update ? dayjs(slotProps.data.last_state_update).format('DD.MM HH:mm:ss') : 'Never' }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Actions" style="width: 200px">
                        <template #body="slotProps">
                            <div v-if="slotProps.data.status === 'paused' || slotProps.data.status === 'offline'" class="flex gap-2">
                                <!-- Only show Retry/Skip if there's a current job -->
                                <template v-if="slotProps.data.current_job_id">
                                    <Button 
                                        label="Retry" 
                                        size="small" 
                                        severity="success"
                                        icon="pi pi-refresh"
                                        @click="retryPrinter(slotProps.data.name)"
                                    />
                                    <Button 
                                        label="Skip" 
                                        size="small" 
                                        severity="warning"
                                        icon="pi pi-step-forward"
                                        outlined
                                        @click="skipPrinter(slotProps.data.name)"
                                    />
                                </template>
                                <!-- Always show Clear for debugging -->
                                <Button 
                                    label="Clear" 
                                    size="small" 
                                    severity="secondary"
                                    icon="pi pi-times"
                                    outlined
                                    @click="clearError(slotProps.data.name)"
                                />
                            </div>
                            <div v-else-if="slotProps.data.status === 'working'" 
                                 class="text-blue-600 text-sm font-medium">
                                <i class="pi pi-spin pi-spinner mr-1"></i>
                                Processing...
                            </div>
                            <div v-else class="text-green-600 text-sm font-medium">
                                <i class="pi pi-check-circle mr-1"></i>
                                Ready
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <!-- Help Section -->
        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start space-x-3">
                <i class="pi pi-info-circle text-blue-600 mt-1"></i>
                <div class="text-sm text-blue-800">
                    <div class="font-semibold mb-2">Printer Actions:</div>
                    <ul class="space-y-1">
                        <li><strong>Retry:</strong> Attempt to print the failed job again (unpauses printer)</li>
                        <li><strong>Skip:</strong> Skip the current failed job and continue with next job (unpauses printer)</li>
                        <li><strong>Clear:</strong> Simply clear the error state without affecting the job (unpauses printer)</li>
                        <li><strong>Auto-refresh:</strong> This page updates every 5 seconds to show real-time status</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div class="mt-6 flex justify-center">
            <Link :href="route('pos.dashboard')">
                <Button 
                    label="Back to Dashboard" 
                    icon="pi pi-arrow-left" 
                    severity="secondary"
                />
            </Link>
        </div>
    </div>
</template>

<style scoped>
.truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>