<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Cache Management</x-slot>
        <x-slot name="description">
            Lists Catch Em All cache keys from AchievementRegister and non-eventUser-bound GameController keys.
            You can remove individual entries or clear all listed keys.
        </x-slot>

        <div class="mb-4 flex gap-3">
            <x-filament::button
                icon="heroicon-o-trash"
                color="danger"
                wire:click="forgetAllListed"
                wire:confirm="Delete all listed cache keys?"
                wire:loading.attr="disabled"
            >
                Forget all listed keys
            </x-filament::button>

            <x-filament::button
                icon="heroicon-o-arrow-path"
                color="gray"
                wire:click="reloadRows"
                wire:loading.attr="disabled"
            >
                Refresh
            </x-filament::button>
        </div>

        @if ($warning)
            <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-500/10 dark:text-warning-200">
                {{ $warning }}
            </div>
        @endif

        <div class="overflow-x-auto rounded-lg border border-gray-300 dark:border-gray-700">
            <table class="w-full divide-y divide-gray-300 text-sm dark:divide-gray-700">
                <thead class="bg-gray-100 dark:bg-gray-800">
                    <tr class="text-left text-white">
                        <th class="px-3 py-2 font-medium">Key</th>
                        <th class="px-3 py-2 font-medium">Source</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Remaining</th>
                        <th class="px-3 py-2 font-medium">Expires at</th>
                        <th class="px-3 py-2 font-medium">Created / Updated at</th>
                        <th class="px-3 py-2 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-300 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        <tr class="text-white">
                            <td class="px-3 py-2 font-mono text-xs">{{ $row['key'] }}</td>
                            <td class="px-3 py-2">{{ $row['source'] }}</td>
                            <td class="px-3 py-2">
                                @if ($row['exists'])
                                    <span class="rounded bg-success-100 px-2 py-1 text-xs font-medium text-success-700 dark:bg-success-500/20 dark:text-success-300">
                                        cached
                                    </span>
                                @else
                                    <span class="rounded bg-gray-700 px-2 py-1 text-xs font-medium text-white dark:bg-gray-700 dark:text-white">
                                        missing
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                @if (is_int($row['remaining_seconds']))
                                    {{ max(0, $row['remaining_seconds']) }}s
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $row['expires_at'] ?? '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($row['estimated_created_at'])
                                    {{ $row['estimated_created_at'] }} (estimated)
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <x-filament::button
                                    size="xs"
                                    color="danger"
                                    icon="heroicon-o-x-circle"
                                    wire:click="forgetKeyByIndex({{ $loop->index }})"
                                    wire:confirm="Delete this cache key?"
                                    wire:loading.attr="disabled"
                                >
                                    Forget
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-3 py-4 text-sm text-white" colspan="7">
                                No cache keys found for the selected sources.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
