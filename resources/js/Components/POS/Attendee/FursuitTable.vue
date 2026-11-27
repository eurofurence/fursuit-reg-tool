<script setup>
import { Checkbox } from "@/Components/UI/checkbox";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/UI/table";

defineProps({
    attendee: Object,
    fursuits: Array
})
</script>

<template>
    <div class="max-h-[400px] overflow-auto">
        <Table class="min-w-[50rem] text-base">
            <TableHeader class="bg-pos-panel-2">
                <TableRow>
                    <TableHead>Icon</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Species</TableHead>
                    <TableHead>Catch em all</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="fursuit in fursuits" :key="fursuit.id">
                    <TableCell>
                        <img
                            :src="fursuit.image_url"
                            :alt="`${fursuit.name} fursuit image`"
                            class="w-16 h-16 object-contain rounded-pos"
                            loading="lazy"
                        />
                    </TableCell>
                    <TableCell>{{ fursuit.name }}</TableCell>
                    <TableCell>{{ fursuit.status }}</TableCell>
                    <TableCell>{{ fursuit.species?.name }}</TableCell>
                    <TableCell>
                        <Checkbox :model-value="Boolean(fursuit.catch_em_all)" disabled class="size-5 disabled:opacity-100" />
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
