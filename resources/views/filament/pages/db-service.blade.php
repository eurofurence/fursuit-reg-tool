<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Fix free badges</x-slot>
        <x-slot name="description">
            Finds badges that were charged the badge fee even though the owner had unused prepaid /
            free badge entitlement for the current event, converts them to free and refunds the
            wrongly charged amount to the owner's wallet. The change is logged (activity log +
            wallet transaction).
        </x-slot>

        @if (! $reviewingFreeBadges && ! $freeBadgeResult)
            <x-filament::button
                wire:click="previewFreeBadgeFix"
                icon="heroicon-o-magnifying-glass"
                wire:loading.attr="disabled"
            >
                Fix free badges
            </x-filament::button>
        @endif

        {{-- ── Result summary ─────────────────────────────────────────────── --}}
        @if ($freeBadgeResult)
            @if ($freeBadgeResult['success'])
                <div class="rounded-lg border border-success-300 bg-success-50 p-4 dark:border-success-700 dark:bg-success-500/10">
                    <p class="font-semibold text-success-700 dark:text-success-400">
                        Fix applied successfully.
                    </p>
                    <ul class="mt-2 list-inside list-disc text-sm text-gray-700 dark:text-gray-300">
                        <li>Badges converted to free: <strong>{{ $freeBadgeResult['fixed_badge_count'] }}</strong></li>
                        <li>Users affected: <strong>{{ $freeBadgeResult['fixed_user_count'] }}</strong></li>
                        <li>Total refunded: <strong>{{ $this->formatEuro($freeBadgeResult['total_refunded_cents']) }}</strong></li>
                    </ul>
                </div>
            @else
                <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 dark:border-danger-700 dark:bg-danger-500/10">
                    <p class="font-semibold text-danger-700 dark:text-danger-400">Fix failed.</p>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $freeBadgeResult['error'] }}</p>
                </div>
            @endif

            <div class="mt-4">
                <x-filament::button color="gray" wire:click="resetFreeBadgeFix" icon="heroicon-o-arrow-path">
                    Run again
                </x-filament::button>
            </div>
        @endif

        {{-- ── Review / preview ───────────────────────────────────────────── --}}
        @if ($reviewingFreeBadges && $freeBadgeReport)
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Affected badges</p>
                        <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $freeBadgeReport['affected_badge_count'] }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Affected users</p>
                        <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $freeBadgeReport['affected_user_count'] }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total to refund</p>
                        <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $this->formatEuro($freeBadgeReport['total_refund_cents']) }}</p>
                    </div>
                </div>

                @if ($freeBadgeReport['affected_badge_count'] > 0)
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="px-3 py-2 font-medium">Image</th>
                                    <th class="px-3 py-2 font-medium">Fursuit</th>
                                    <th class="px-3 py-2 font-medium">Species</th>
                                    <th class="px-3 py-2 font-medium">Owner</th>
                                    <th class="px-3 py-2 text-right font-medium">Badges (event)</th>
                                    <th class="px-3 py-2 text-right font-medium">Should be free</th>
                                    <th class="px-3 py-2 text-right font-medium">Should be paid</th>
                                    <th class="px-3 py-2 text-right font-medium">Refund</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($freeBadgeReport['rows'] as $row)
                                    <tr class="text-gray-700 dark:text-gray-300">
                                        <td class="px-3 py-2">
                                            @php($img = $this->imageUrl($row['image']))
                                            <img
                                                src="{{ $img ?? asset('images/placeholder.png') }}"
                                                alt="badge"
                                                class="h-10 w-10 rounded-full object-cover"
                                            />
                                        </td>
                                        <td class="px-3 py-2">{{ $row['fursuit'] ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ $row['species'] ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ $row['owner'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['badges_total'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['should_be_free'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['should_be_paid'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $this->formatEuro($row['current_total']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="flex gap-3">
                    @if ($freeBadgeReport['affected_badge_count'] > 0)
                        <x-filament::button
                            color="success"
                            icon="heroicon-o-check"
                            wire:click="applyFreeBadgeFix"
                            wire:confirm="Convert {{ $freeBadgeReport['affected_badge_count'] }} badge(s) to free and refund {{ $this->formatEuro($freeBadgeReport['total_refund_cents']) }}? This cannot be undone automatically."
                            wire:loading.attr="disabled"
                        >
                            Confirm &amp; apply fix
                        </x-filament::button>
                    @endif
                    <x-filament::button color="gray" icon="heroicon-o-x-mark" wire:click="cancelFreeBadgeFix">
                        Cancel
                    </x-filament::button>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
