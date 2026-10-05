@php
    /** @var list<array{index: int, gem_id: string, name: string, type: string, durability: string, mf: string}> $rows */
@endphp

<div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden dark:divide-white/10">
    <div class="overflow-x-auto">
        <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
            <thead class="divide-y divide-gray-200 dark:divide-white/5">
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.gem_pouch_index') }}
                    </th>
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.name') }}
                    </th>
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.item_type') }}
                    </th>
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.durability') }}
                    </th>
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.gem_pouch_mf') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr class="fi-ta-row">
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['index'] }}</td>
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['type'] }}</td>
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['durability'] }}</td>
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['mf'] }}</td>
                    </tr>
                @empty
                    <tr class="fi-ta-row">
                        <td colspan="5" class="fi-ta-cell px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            <div class="font-semibold text-gray-950 dark:text-white">
                                {{ __('admin.empty.bag.heading') }}
                            </div>
                            <div class="mt-1">
                                {{ __('admin.empty.bag.description') }}
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
