<script setup>
import { Head, router } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Card from 'primevue/card';
import { useForm } from 'laravel-precognition-vue-inertia';
import { useToast } from 'primevue/usetoast';
import dayjs from 'dayjs';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    printJobs: Object,
});

const toast = useToast();

function markAsPrinted(printJobId) {
    useForm('POST', route('pos.print-queue.mark-printed', { printJob: printJobId }), {})
        .submit({
            onSuccess: () => {
                toast.add({
                    severity: 'success', 
                    summary: 'Success', 
                    detail: `Print job #${printJobId} marked as printed`, 
                    life: 3000
                });
            },
            onError: () => {
                toast.add({
                    severity: 'error', 
                    summary: 'Error', 
                    detail: `Failed to mark print job #${printJobId} as printed`, 
                    life: 5000
                });
            }
        });
}

function retryPrintJob(printJobId) {
    useForm('POST', route('pos.print-queue.retry', { printJob: printJobId }), {})
        .submit({
            onSuccess: () => {
                toast.add({
                    severity: 'info', 
                    summary: 'Retry Queued', 
                    detail: `Print job #${printJobId} queued for retry`, 
                    life: 3000
                });
            },
            onError: () => {
                toast.add({
                    severity: 'error', 
                    summary: 'Retry Failed', 
                    detail: `Failed to retry print job #${printJobId}`, 
                    life: 5000
                });
            }
        });
}

function deletePrintJob(printJobId) {
    if (confirm('Are you sure you want to delete this print job?')) {
        useForm('DELETE', route('pos.print-queue.delete', { printJob: printJobId }), {})
            .submit({
                onSuccess: () => {
                    toast.add({
                        severity: 'success', 
                        summary: 'Deleted', 
                        detail: `Print job #${printJobId} deleted successfully`, 
                        life: 3000
                    });
                },
                onError: () => {
                    toast.add({
                        severity: 'error', 
                        summary: 'Delete Failed', 
                        detail: `Failed to delete print job #${printJobId}`, 
                        life: 5000
                    });
                }
            });
    }
}

function getStatusSeverity(status) {
    switch (status) {
        case 'pending': return 'secondary';
        case 'queued': return 'warning';
        case 'printing': return 'info';
        case 'printed': return 'success';
        case 'failed': return 'danger';
        case 'cancelled': return 'secondary';
        case 'retrying': return 'warning';
        default: return 'info';
    }
}

function getTypeSeverity(type) {
    switch (type) {
        case 'badge': return 'primary';
        case 'receipt': return 'secondary';
        default: return 'info';
    }
}

function onPageChange(event) {
    router.get(route('pos.print-queue.index'), {
        page: event.page + 1  // PrimeVue pages are 0-indexed, Laravel expects 1-indexed
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true
    });
}
</script>

<template>
    <Head>
        <title>POS - Print Queue</title>
    </Head>
    
    <div class="p-4">
        <!-- Back Button at Top -->
        <div class="mb-6">
            <Button 
                label="Back to Dashboard" 
                icon="pi pi-arrow-left" 
                severity="secondary"
                @click="router.visit(route('pos.dashboard'))"
                class="mb-4"
            />
        </div>

        <!-- Header -->
        <div class="mb-6">
            <Card class="shadow-lg border-0 bg-gradient-to-r from-purple-600 to-purple-700 text-white">
                <template #content>
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Print Queue Management</h1>
                                <p class="text-purple-100 text-lg">
                                    Manage and monitor print jobs
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-6xl opacity-20">
                                    <i class="pi pi-print"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Print Jobs Table -->
        <Card>
            <template #content>
                <DataTable 
                    :value="printJobs.data" 
                    :paginator="true" 
                    :rows="printJobs.per_page"
                    :totalRecords="printJobs.total"
                    :lazy="true"
                    :first="(printJobs.current_page - 1) * printJobs.per_page"
                    @page="onPageChange"
                    tableStyle="min-width: 50rem"
                    class="p-datatable-sm"
                >
                    <Column field="id" header="ID" style="width: 80px">
                        <template #body="slotProps">
                            <span class="font-mono text-sm">#{{ slotProps.data.id }}</span>
                        </template>
                    </Column>
                    
                    <Column field="type" header="Type" style="width: 100px">
                        <template #body="slotProps">
                            <Tag 
                                :value="slotProps.data.type" 
                                :severity="getTypeSeverity(slotProps.data.type)"
                                class="text-xs"
                            />
                        </template>
                    </Column>
                    
                    <Column field="printable" header="Item">
                        <template #body="slotProps">
                            <div v-if="slotProps.data.printable_type === 'App\\Models\\Badge\\Badge'">
                                <div class="font-semibold">Badge #{{ slotProps.data.printable?.custom_id }}</div>
                                <div class="text-sm text-gray-600">{{ slotProps.data.printable?.fursuit?.name }}</div>
                            </div>
                            <div v-else-if="slotProps.data.printable_type === 'App\\Domain\\Checkout\\Models\\Checkout\\Checkout'">
                                <div class="font-semibold">Receipt #{{ slotProps.data.printable?.id }}</div>
                                <div class="text-sm text-gray-600">Checkout receipt</div>
                            </div>
                            <div v-else class="text-gray-500">Unknown item</div>
                        </template>
                    </Column>
                    
                    <Column field="printer.name" header="Printer">
                        <template #body="slotProps">
                            <div>
                                <div class="font-semibold">{{ slotProps.data.printer?.name || 'Unknown' }}</div>
                                <div class="text-sm text-gray-600">{{ slotProps.data.printer?.type }}</div>
                            </div>
                        </template>
                    </Column>
                    
                    <Column field="status" header="Status" style="width: 120px">
                        <template #body="slotProps">
                            <Tag 
                                :value="slotProps.data.status" 
                                :severity="getStatusSeverity(slotProps.data.status)"
                            />
                        </template>
                    </Column>
                    
                    <Column field="created_at" header="Queued" style="width: 120px">
                        <template #body="slotProps">
                            <div class="text-sm">
                                <div>{{ dayjs(slotProps.data.created_at).format('DD.MM.YY') }}</div>
                                <div class="text-gray-600">{{ dayjs(slotProps.data.created_at).format('HH:mm') }}</div>
                            </div>
                        </template>
                    </Column>
                    
                    <Column field="printed_at" header="Printed" style="width: 120px">
                        <template #body="slotProps">
                            <div v-if="slotProps.data.printed_at" class="text-sm">
                                <div>{{ dayjs(slotProps.data.printed_at).format('DD.MM.YY') }}</div>
                                <div class="text-gray-600">{{ dayjs(slotProps.data.printed_at).format('HH:mm') }}</div>
                            </div>
                            <span v-else class="text-gray-400">-</span>
                        </template>
                    </Column>
                    
                    <Column header="Actions" style="width: 200px">
                        <template #body="slotProps">
                            <div class="flex gap-2">
                                <Button 
                                    v-if="slotProps.data.status === 'pending' || slotProps.data.status === 'queued'"
                                    label="Mark Printed" 
                                    size="small" 
                                    severity="success"
                                    @click="markAsPrinted(slotProps.data.id)"
                                />
                                <Button 
                                    v-if="slotProps.data.status === 'failed' || slotProps.data.status === 'printed'"
                                    label="Retry" 
                                    size="small" 
                                    severity="warning"
                                    @click="retryPrintJob(slotProps.data.id)"
                                />
                                <Button 
                                    v-if="slotProps.data.status !== 'cancelled' && slotProps.data.status !== 'printed'"
                                    label="Delete" 
                                    size="small" 
                                    severity="danger"
                                    outlined
                                    @click="deletePrintJob(slotProps.data.id)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <!-- Back Button -->
        <div class="mt-6 flex justify-center">
            <Button 
                label="Back to Dashboard" 
                icon="pi pi-arrow-left" 
                severity="secondary"
                @click="router.visit(route('pos.dashboard'))"
            />
        </div>
    </div>
</template>