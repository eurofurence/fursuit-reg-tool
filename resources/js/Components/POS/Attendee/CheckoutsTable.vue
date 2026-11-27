<script setup>
import dayjs from "dayjs";
import { Badge } from "@/Components/UI/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/UI/table";

defineProps({
    checkouts: Array
})
</script>

<template>
    <div class="max-h-[400px] overflow-auto">
        <Table class="min-w-[50rem] text-base">
            <TableHeader class="bg-pos-panel-2">
                <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Method</TableHead>
                    <TableHead>Items</TableHead>
                    <TableHead>Total</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="checkout in checkouts" :key="checkout.id">
                    <TableCell>{{ checkout.created_at ? dayjs(checkout.created_at).format('DD.MM.YY HH:mm') : '-' }}</TableCell>
                    <TableCell>{{ checkout.status }}</TableCell>
                    <TableCell>{{ checkout.payment_method }}</TableCell>
                    <TableCell class="whitespace-normal">
                        <div class="flex flex-wrap gap-2">
                            <Badge v-for="item in checkout.items" :key="item.id ?? item.name" variant="secondary" class="text-sm">{{ item.name }}</Badge>
                        </div>
                    </TableCell>
                    <TableCell class="pos-num">{{ (parseInt(checkout.total) / 100.0).toFixed(2) }} €</TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
