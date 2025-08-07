<script setup>
import {Head, Link} from "@inertiajs/vue3";
import Card from 'primevue/card';
import Button from 'primevue/button';
import { capitalize } from 'vue';

const props = defineProps({
    overview: Object,
    badges: Object,
    fursuits: Object,
    fcea: Object,
    species: Object,
    users: Object,
    timeline: Object,
    currentEvent: String,
});

</script>

<template>
    <Head>
        <title>Fursuit Statistics</title>
    </Head>

    <div class="p-4">
        <!-- Header -->
        <div class="mb-6">
            <Card class="shadow-lg border-0 bg-gradient-to-r from-green-600 to-green-700 text-white">
                <template #content>
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Fursuit Statistics</h1>
                                <p class="text-green-100 text-lg">
                                    Event: {{ currentEvent }}
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

        <!-- Overview Stats -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-blue-600">{{ overview.total_users }}</div>
                        <div class="text-sm text-gray-600">Total Users</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-purple-600">{{ overview.total_badges }}</div>
                        <div class="text-sm text-gray-600">Total Badges</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-green-600">{{ overview.total_fursuits }}</div>
                        <div class="text-sm text-gray-600">Total Fursuits</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-orange-600">{{ overview.total_catches }}</div>
                        <div class="text-sm text-gray-600">Total Catches</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-red-600">{{ overview.total_events }}</div>
                        <div class="text-sm text-gray-600">Total Events</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-teal-600">{{ overview.current_event_badges }}</div>
                        <div class="text-sm text-gray-600">Current Event Badges</div>
                    </div>
                </template>
            </Card>

            <Card class="text-center">
                <template #content>
                    <div class="p-4">
                        <div class="text-2xl font-bold text-indigo-600">{{ overview.current_event_participants }}</div>
                        <div class="text-sm text-gray-600">Current Event Participants</div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Charts and Tables Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Badges -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Badges of {{ currentEvent }}</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Badges</span>
                            <span class="font-semibold text-blue-600">{{ badges.total }}</span>
                        </div>
                        <div v-for="(value, key) in badges.by_state" class="flex justify-between">
                            <span>State: {{ capitalize(key) }}</span>
                            <span class="font-semibold text-blue-600">{{ value }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Double-Sided</span>
                            <span class="font-semibold text-blue-600">{{ badges.upgrades.double_sided }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Spare Copies</span>
                            <span class="font-semibold text-blue-600">{{ badges.upgrades.spare_copies }}</span>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Fursuits -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Fursuits of {{ currentEvent }}</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Fursuits</span>
                            <span class="font-semibold text-purple-600">{{ fursuits.total }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Unique Fursuits</span>
                            <span class="font-semibold text-purple-600">{{ fursuits.unique_fursuiters }}</span>
                        </div>
                        <div v-for="(value, key) in fursuits.by_state" class="flex justify-between">
                            <span>State: {{ capitalize(key) }}</span>
                            <span class="font-semibold text-purple-600">{{ value }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Published Fursuits</span>
                            <span class="font-semibold text-purple-600">{{ fursuits.published }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Catch-Em-All Fursuits</span>
                            <span class="font-semibold text-purple-600">{{ fursuits.catch_em_all_enabled }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Approval Rate</span>
                            <span class="font-semibold text-purple-600">{{ fursuits.approval_rate }}%</span>
                        </div>
                        <div v-for="(value, key) in fursuits.top_owners" class="flex justify-between">
                            <span>#{{ key + 1 }} Most Fursuit Owner: {{ value.name }}</span>
                            <span class="font-semibold text-red-600">{{ value.fursuits_count }}</span>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- fcea -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Catch-Em-All of {{ currentEvent }}</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Catches</span>
                            <span class="font-semibold text-orange-600">{{ fcea.total_catches }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Total Players</span>
                            <span class="font-semibold text-orange-600">{{ fcea.total_players }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Catchable Fursuits</span>
                            <span class="font-semibold text-orange-600">{{ fcea.catchable_fursuits }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Average Catches per Player</span>
                            <span class="font-semibold text-orange-600">{{ fcea.average_catches_per_player }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Most Active Day</span>
                            <span class="font-semibold text-orange-600">{{ fcea.most_active_day }}</span>
                        </div>
                        <div v-for="(value, key) in fcea.top_catchers" class="flex justify-between">
                            <span>#{{ key + 1 }} Top Catcher: {{ value.name }} </span>
                            <span class="font-semibold text-purple-600">{{ value.catches }}</span>
                        </div>
                        <div v-for="(value, key) in fcea.most_caught_fursuits" class="flex justify-between">
                            <span>#{{ key + 1 }} Most Caught Fursuit: {{ value.name }} {{ value.species }} of {{ value.owner }} </span>
                            <span class="font-semibold text-blue-600">{{ value.catches }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Average Completion</span>
                            <span class="font-semibold text-orange-600">{{ fcea.completion_stats.average_completion }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Average Completion</span>
                            <span class="font-semibold text-orange-600">{{ fcea.completion_stats.median_completion }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Players with 100% compleation</span>
                            <span class="font-semibold text-orange-600">{{ fcea.completion_stats.players_with_100_percent }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Players with 50%</span>
                            <span class="font-semibold text-orange-600">{{ fcea.completion_stats.players_with_50_percent }}</span>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- species -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Species of {{ currentEvent }}</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Species</span>
                            <span class="font-semibold text-teal-600">{{ species.total }}</span>
                        </div>
                        <div v-for="(value, key) in species.most_popular" class="flex justify-between">
                            <span>#{{ key + 1 }} Species: {{ value.name }}</span>
                            <span class="font-semibold text-blue-600">Fursuits: {{ value.fursuits_count }}<br>Badges: {{ value.badges_count }}</span>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Users -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Users of {{ currentEvent }}</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>Total Users</span>
                            <span class="font-semibold text-green-600">{{ users.total }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Participating in current Event</span>
                            <span class="font-semibold text-green-600">{{ users.participating_in_current_event }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Average Badges per User</span>
                            <span class="font-semibold text-green-600">{{ users.average_badges_per_user }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>New Users this Month</span>
                            <span class="font-semibold text-green-600">{{ users.new_users_this_month }}</span>
                        </div>
                        <div v-for="(value, key) in users.most_active_users" class="flex justify-between">
                            <span>#{{ key + 1 }} Most Badges: {{ value.name }}</span>
                            <span class="font-semibold text-blue-600">{{ value.badges }}</span>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Timeline -->
            <Card>
                <template #title>
                    <h3 class="text-lg font-semibold">Timeline of {{ currentEvent }}</h3>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div v-for="(value, key) in timeline.daily_catches" class="flex justify-between">
                            <span>#{{ key + 1 }} Daily Catches: {{ value.date }}</span>
                            <span class="font-semibold text-teal-600">{{ value.catches }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Peak Hour</span>
                            <span class="font-semibold text-red-600">{{ timeline.peak_activity.peak_hour }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Peak Day</span>
                            <span class="font-semibold text-red-600">{{ timeline.peak_activity.peak_day }}</span>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Back Button -->
        <div class="mt-6 flex justify-center">
            <Link :href="route('gallery.index')">
                <Button
                    label="Back to Gallery"
                    icon="pi pi-arrow-left"
                    severity="secondary"
                />
            </Link>
        </div>
    </div>
</template>
