<script setup>
import { Head, Link } from "@inertiajs/vue3";
import ManageLayout from "@/Layouts/ManageLayout.vue";
import PageHeader from "@/Components/Manage/PageHeader.vue";

const props = defineProps({
    rows: { type: Array, default: () => [] },
    cacheDriver: { type: String, default: "" },
    warning: { type: String, default: "" },
});

const formatSeconds = (value) => {
    if (value === null || value === undefined) {
        return "—";
    }

    if (value < 0) {
        return "expired";
    }

    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const seconds = value % 60;

    return (
        [
            hours ? `${hours}h` : null,
            minutes ? `${minutes}m` : null,
            seconds ? `${seconds}s` : null,
        ]
            .filter(Boolean)
            .join(" ") || "0s"
    );
};
</script>

<template>
    <Head title="Catch-Em-All Cache" />

    <ManageLayout>
        <PageHeader
            title="Catch-Em-All Cache"
            subtitle="Inspect and clear Catch-Em-All cache entries"
        />

        <div class="flex flex-col gap-4 p-4">
            <div
                class="flex flex-wrap items-center justify-between gap-3 rounded border border-hairline bg-mg-surface-1 p-3 text-[13px] text-fg-2"
            >
                <div>
                    <div>
                        <span class="font-medium text-fg-1">Cache driver:</span>
                        {{ cacheDriver }}
                    </div>
                    <div v-if="warning" class="mt-1 text-state-warning">
                        {{ warning }}
                    </div>
                </div>
                <Link
                    :href="route('admin.tools.catch-em-all-cache.forget-all')"
                    method="post"
                    as="button"
                    class="rounded border border-state-danger/40 px-3 py-1.5 text-[12px] font-medium text-state-danger transition-colors hover:bg-mg-surface-2"
                >
                    Clear all listed
                </Link>
            </div>

            <div class="overflow-hidden rounded border border-hairline">
                <table
                    class="min-w-full divide-y divide-hairline bg-mg-surface-1 text-[13px]"
                >
                    <thead class="bg-mg-surface-2 text-left text-fg-3">
                        <tr>
                            <th class="px-3 py-2">Key</th>
                            <th class="px-3 py-2">Source</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Remaining</th>
                            <th class="px-3 py-2">Expires</th>
                            <th class="px-3 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr
                            v-for="row in rows"
                            :key="row.key"
                            class="align-top"
                        >
                            <td
                                class="max-w-[320px] px-3 py-2 font-mono text-[12px] text-fg-1"
                            >
                                {{ row.key }}
                            </td>
                            <td class="px-3 py-2 text-fg-2">
                                {{ row.source }}
                            </td>
                            <td class="px-3 py-2">
                                <span v-if="row.exists" class="text-state-live"
                                    >Active</span
                                >
                                <span v-else class="text-state-danger"
                                    >Missing</span
                                >
                            </td>
                            <td class="px-3 py-2 text-fg-2">
                                {{ formatSeconds(row.remaining_seconds) }}
                            </td>
                            <td class="px-3 py-2 text-fg-2">
                                {{ row.expires_at ?? "—" }}
                            </td>
                            <td class="px-3 py-2">
                                <Link
                                    :href="
                                        route(
                                            'admin.tools.catch-em-all-cache.forget',
                                            row.key,
                                        )
                                    "
                                    method="post"
                                    as="button"
                                    class="text-[12px] text-state-danger underline underline-offset-2"
                                >
                                    Clear
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </ManageLayout>
</template>
