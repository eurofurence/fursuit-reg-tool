<script setup>
import { Head } from "@inertiajs/vue3";
import POSLayout from "@/Layouts/POSLayout.vue";
import Card from 'primevue/card';
import Chart from 'primevue/chart';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import { formatEuroFromCents } from '@/helpers.js';
import { computed, ref, onMounted } from 'vue';

defineOptions({
    layout: POSLayout,
});

const props = defineProps({
    overview: Object,
    badges: Object,
    printing: Object,
    sales: Object,
    daily: Object,
    financial: Object,
    currentEvent: Object,
});

const chartData = ref({});
const chartOptions = ref({});

onMounted(() => {
    setChartData();
    setChartOptions();
});

const setChartData = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    
    chartData.value = {
        labels: props.daily.event_days?.map(day => day.day_name) || [],
        datasets: [
            {
                label: 'Revenue',
                data: props.daily.event_days?.map(day => day.revenue / 100) || [], // Convert cents to euros
                fill: false,
                borderColor: documentStyle.getPropertyValue('--blue-500'),
                tension: 0.4
            },
            {
                label: 'Badges Created',
                data: props.daily.event_days?.map(day => day.badges_created) || [],
                fill: false,
                borderColor: documentStyle.getPropertyValue('--green-500'),
                tension: 0.4
            }
        ]
    };
};

const setChartOptions = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue('--text-color');
    const textColorSecondary = documentStyle.getPropertyValue('--text-color-secondary');
    const surfaceBorder = documentStyle.getPropertyValue('--surface-border');

    chartOptions.value = {
        maintainAspectRatio: false,
        aspectRatio: 0.6,
        plugins: {
            legend: {
                labels: {
                    color: textColor
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    color: textColorSecondary
                },
                grid: {
                    color: surfaceBorder
                }
            },
            y: {
                ticks: {
                    color: textColorSecondary
                },
                grid: {
                    color: surfaceBorder
                }
            }
        }
    };
};

const statusColors = {
    pending: 'warning',
    processing: 'info',
    ready_for_pickup: 'success',
    picked_up: 'secondary'
};
</script>

<template>
    <Head>
        <title>POS - Statistics</title>
    </Head>
    
    <div class="w-full p-4">
        <!-- Header -->
        <div class="mb-6">
            <Card class="shadow-lg border-0 bg-gradient-to-r from-green-600 to-green-700 text-white">
                <template #content>
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">POS Statistics</h1>
                                <p class="text-green-100 text-lg">
                                    Event: {{ currentEvent?.name || 'No Active Event' }}
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-6xl opacity-20">
                                    <i class="pi pi-chart-bar"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Daily Overview Stats -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-blue-600">{{ overview.badges_ordered_today }}</div>
                        <div class="text-sm text-gray-600">Badges Ordered Today</div>
                    </div>
                </template>
            </Card>
            
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-green-600">{{ formatEuroFromCents(overview.money_processed_today * 100) }}</div>
                        <div class="text-sm text-gray-600">Money Today (Total)</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-emerald-600">{{ formatEuroFromCents(overview.cash_processed_today * 100) }}</div>
                        <div class="text-sm text-gray-600">Cash Today</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-cyan-600">{{ formatEuroFromCents(overview.card_processed_today * 100) }}</div>
                        <div class="text-sm text-gray-600">Card Today</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-orange-600">{{ overview.badges_picked_up_today }}</div>
                        <div class="text-sm text-gray-600">Badges Picked Up Today</div>
                    </div>
                </template>
            </Card>
            
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-purple-600">{{ overview.badges_printed_today }}</div>
                        <div class="text-sm text-gray-600">Badges Printed Today</div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Financial Overview -->
        <div v-if="financial" class="mb-6">
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Financial Overview</h3>
                </template>
                <template #content>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="text-center p-4">
                            <div class="text-2xl font-bold text-green-600">€{{ financial.total_revenue.toFixed(2) }}</div>
                            <div class="text-sm text-gray-600">Total Revenue</div>
                            <div class="text-xs text-gray-500">Prepaid + Late</div>
                        </div>
                        <div class="text-center p-4">
                            <div class="text-2xl font-bold text-cyan-600">€{{ financial.actual_revenue.toFixed(2) }}</div>
                            <div class="text-sm text-gray-600">Actual Revenue</div>
                            <div class="text-xs text-gray-500">POS + Prepaid</div>
                        </div>
                        <div class="text-center p-4">
                            <div class="text-2xl font-bold text-blue-600">€{{ financial.prepaid_badge_revenue.toFixed(2) }}</div>
                            <div class="text-sm text-gray-600">Prepaid Badges</div>
                        </div>
                        <div class="text-center p-4">
                            <div class="text-2xl font-bold text-orange-600">€{{ financial.late_badge_revenue.toFixed(2) }}</div>
                            <div class="text-sm text-gray-600">Late Badges</div>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="text-center p-4">
                                <div class="text-2xl font-bold text-purple-600">€{{ financial.pos_badge_revenue.toFixed(2) }}</div>
                                <div class="text-sm text-gray-600">POS Badge Sales</div>
                            </div>
                        </div>
                    </div>
                    
                    <div v-if="financial.printing_cost" class="mt-4 pt-4 border-t border-gray-200">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="text-center p-4">
                                <div class="text-2xl font-bold text-red-600">€{{ financial.printing_cost.toFixed(2) }}</div>
                                <div class="text-sm text-gray-600">Printing Cost</div>
                            </div>
                            <div class="text-center p-4">
                                <div :class="['text-2xl font-bold', financial.profit_margin >= 0 ? 'text-green-600' : 'text-red-600']">
                                    €{{ financial.profit_margin.toFixed(2) }}
                                </div>
                                <div class="text-sm text-gray-600">Profit/Loss</div>
                            </div>
                            <div class="text-center p-4">
                                <div :class="['text-2xl font-bold', financial.money_needed_to_cover > 0 ? 'text-red-600' : 'text-green-600']">
                                    €{{ financial.money_needed_to_cover.toFixed(2) }}
                                </div>
                                <div class="text-sm text-gray-600">Still Needed</div>
                            </div>
                            <div class="text-center p-4">
                                <div :class="['text-2xl font-bold', financial.is_profitable ? 'text-green-600' : 'text-red-600']">
                                    {{ financial.is_profitable ? 'Covered' : 'Not Covered' }}
                                </div>
                                <div class="text-sm text-gray-600">Cost Status</div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Charts and Tables Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Event Days Chart -->
            <Card>
                <template #content>
                    <Chart type="line" :data="chartData" :options="chartOptions" class="h-64" />
                </template>
            </Card>

            <!-- Badge Status Breakdown -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Badge Status Overview</h3>
                </template>
                <template #content>
                    <div class="space-y-4">
                        <div>
                            <h4 class="font-medium mb-2">Payment Status</h4>
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm">Paid</span>
                                <span class="font-semibold">{{ badges.by_payment_status.paid }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Unpaid</span>
                                <span class="font-semibold text-red-600">{{ badges.by_payment_status.unpaid }}</span>
                            </div>
                        </div>
                        
                        <div>
                            <h4 class="font-medium mb-2">Fulfillment Status</h4>
                            <div class="space-y-1">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm">Pending</span>
                                    <span class="font-semibold text-yellow-600">{{ badges.by_fulfillment_status.pending }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm">Processing</span>
                                    <span class="font-semibold text-blue-600">{{ badges.by_fulfillment_status.processing }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm">Ready for Pickup</span>
                                    <span class="font-semibold text-green-600">{{ badges.by_fulfillment_status.ready_for_pickup }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm">Picked Up</span>
                                    <span class="font-semibold text-gray-600">{{ badges.by_fulfillment_status.picked_up }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Detailed Stats Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Printing Stats -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Printing Statistics</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Jobs</span>
                            <span class="font-semibold">{{ printing.total_jobs }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Pending</span>
                            <span class="font-semibold text-yellow-600">{{ printing.pending_jobs }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Completed</span>
                            <span class="font-semibold text-green-600">{{ printing.printed_jobs }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Jobs Today</span>
                            <span class="font-semibold">{{ printing.jobs_today }}</span>
                        </div>
                        <div v-if="printing.average_print_time" class="flex justify-between">
                            <span>Avg. Print Time</span>
                            <span class="font-semibold">{{ printing.average_print_time }}min</span>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Sales Stats -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Sales Statistics</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Revenue</span>
                            <span class="font-semibold text-green-600">{{ formatEuroFromCents(sales.total_revenue) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Today's Revenue</span>
                            <span class="font-semibold">{{ formatEuroFromCents(sales.today_revenue) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Avg. Order Value</span>
                            <span class="font-semibold">{{ formatEuroFromCents(sales.average_order_value) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Transactions Today</span>
                            <span class="font-semibold">{{ sales.transactions_today }}</span>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Badge Upgrades -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Badge Upgrades</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Badges</span>
                            <span class="font-semibold">{{ badges.total }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Double-Sided</span>
                            <span class="font-semibold text-blue-600">{{ badges.upgrades.double_sided }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Extra Copies</span>
                            <span class="font-semibold text-purple-600">{{ badges.upgrades.extra_copies }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Upgrade Rate</span>
                            <span class="font-semibold">{{ Math.round(((badges.upgrades.double_sided + badges.upgrades.extra_copies) / badges.total) * 100) }}%</span>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Event Days Breakdown -->
        <Card>
            <template #content>
                <DataTable :value="daily.event_days" class="p-datatable-sm">
                    <Column field="day_name" header="Day"></Column>
                    <Column field="badges_created" header="Created"></Column>
                    <Column field="badges_paid" header="Paid"></Column>
                    <Column field="revenue" header="Revenue">
                        <template #body="slotProps">
                            {{ formatEuroFromCents(slotProps.data.revenue) }}
                        </template>
                    </Column>
                    <Column field="print_jobs" header="Prints"></Column>
                </DataTable>
            </template>
        </Card>

        <!-- Back Button -->
        <div class="mt-6 flex justify-center">
            <Button 
                label="Back to Dashboard" 
                icon="pi pi-arrow-left" 
                severity="secondary"
                tag="a"
                :href="route('pos.dashboard')"
            />
        </div>
    </div>
</template>