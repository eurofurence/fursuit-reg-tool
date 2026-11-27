<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import dayjs from 'dayjs';
import UiTag from '@/Components/UI/UiTag.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/UI/table';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/Components/UI/pagination';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    printJobs: Object,
});

function markAsPrinted(printJobId) {
    useForm('post', route('pos.print-queue.mark-printed', { printJob: printJobId }), {})
        .submit({
            onSuccess: () => {
                toast.success(`Print job #${printJobId} marked as printed`, { duration: 3000 });
            },
            onError: () => {
                toast.error(`Failed to mark print job #${printJobId} as printed`, { duration: 5000 });
            }
        });
}

function retryPrintJob(printJobId) {
    useForm('post', route('pos.print-queue.retry', { printJob: printJobId }), {})
        .submit({
            onSuccess: () => {
                toast.info('Retry Queued', { description: `Print job #${printJobId} queued for retry`, duration: 3000 });
            },
            onError: () => {
                toast.error('Retry Failed', { description: `Failed to retry print job #${printJobId}`, duration: 5000 });
            }
        });
}

function deletePrintJob(printJobId) {
    if (confirm('Are you sure you want to delete this print job?')) {
        useForm('delete', route('pos.print-queue.delete', { printJob: printJobId }), {})
            .submit({
                onSuccess: () => {
                    toast.success('Deleted', { description: `Print job #${printJobId} deleted successfully`, duration: 3000 });
                },
                onError: () => {
                    toast.error('Delete Failed', { description: `Failed to delete print job #${printJobId}`, duration: 5000 });
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

function onPageChange(page) {
    router.get(route('pos.print-queue.index'), {
        page,
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

    <div class="w-full flex flex-col gap-2">
        <div class="pos-card flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold leading-tight">Print Queue</h1>
                <span class="text-sm text-pos-muted pos-num">{{ printJobs.total }} job(s)</span>
            </div>
            <Link :href="route('pos.dashboard')" class="pos-btn">
                Dashboard <span class="pos-kcap">−</span>
            </Link>
        </div>

        <div class="pos-card">
            <Table class="min-w-[50rem]">
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[80px]">ID</TableHead>
                        <TableHead class="w-[100px]">Type</TableHead>
                        <TableHead>Item</TableHead>
                        <TableHead>Printer</TableHead>
                        <TableHead class="w-[120px]">Status</TableHead>
                        <TableHead class="w-[120px]">Queued</TableHead>
                        <TableHead class="w-[120px]">Printed</TableHead>
                        <TableHead class="w-[200px]">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="job in printJobs.data" :key="job.id">
                        <TableCell>
                            <span class="font-mono text-sm">#{{ job.id }}</span>
                        </TableCell>
                        <TableCell>
                            <UiTag :value="job.type" :severity="getTypeSeverity(job.type)" class="text-xs" />
                        </TableCell>
                        <TableCell>
                            <div v-if="job.printable_type === 'App\\Models\\Badge\\Badge'">
                                <div class="font-semibold">Badge #{{ job.printable?.custom_id }}</div>
                                <div class="text-sm text-pos-muted">{{ job.printable?.fursuit?.name }}</div>
                            </div>
                            <div v-else-if="job.printable_type === 'App\\Domain\\Checkout\\Models\\Checkout\\Checkout'">
                                <div class="font-semibold">Receipt #{{ job.printable?.id }}</div>
                                <div class="text-sm text-pos-muted">Checkout receipt</div>
                            </div>
                            <div v-else class="text-pos-muted">Unknown item</div>
                        </TableCell>
                        <TableCell>
                            <div class="font-semibold">{{ job.printer?.name || 'Unknown' }}</div>
                            <div class="text-sm text-pos-muted">{{ job.printer?.type }}</div>
                        </TableCell>
                        <TableCell>
                            <UiTag :value="job.status" :severity="getStatusSeverity(job.status)" />
                        </TableCell>
                        <TableCell>
                            <div class="text-sm">
                                <div>{{ dayjs(job.created_at).format('DD.MM.YY') }}</div>
                                <div class="text-pos-muted">{{ dayjs(job.created_at).format('HH:mm') }}</div>
                            </div>
                        </TableCell>
                        <TableCell>
                            <div v-if="job.printed_at" class="text-sm">
                                <div>{{ dayjs(job.printed_at).format('DD.MM.YY') }}</div>
                                <div class="text-pos-muted">{{ dayjs(job.printed_at).format('HH:mm') }}</div>
                            </div>
                            <span v-else class="text-pos-muted">-</span>
                        </TableCell>
                        <TableCell>
                            <div class="flex gap-2">
                                <button
                                    v-if="job.status === 'pending' || job.status === 'queued'"
                                    type="button"
                                    class="pos-btn pos-btn--sm pos-btn--primary"
                                    @click="markAsPrinted(job.id)"
                                >
                                    Mark Printed
                                </button>
                                <button
                                    v-if="job.status === 'failed' || job.status === 'printed'"
                                    type="button"
                                    class="pos-btn pos-btn--sm"
                                    @click="retryPrintJob(job.id)"
                                >
                                    Retry
                                </button>
                                <button
                                    v-if="job.status !== 'cancelled' && job.status !== 'printed'"
                                    type="button"
                                    class="pos-btn pos-btn--sm pos-btn--danger"
                                    @click="deletePrintJob(job.id)"
                                >
                                    Delete
                                </button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <Pagination
                v-if="printJobs.total > printJobs.per_page"
                v-slot="{ page }"
                :page="printJobs.current_page"
                :items-per-page="printJobs.per_page"
                :total="printJobs.total"
                class="mt-2"
                @update:page="onPageChange"
            >
                <PaginationContent v-slot="{ items }">
                    <PaginationPrevious class="h-12" />
                    <template v-for="(item, index) in items" :key="index">
                        <PaginationItem v-if="item.type === 'page'" :value="item.value" :is-active="item.value === page" class="size-12 text-base">
                            {{ item.value }}
                        </PaginationItem>
                        <PaginationEllipsis v-else />
                    </template>
                    <PaginationNext class="h-12" />
                </PaginationContent>
            </Pagination>
        </div>
    </div>
</template>
