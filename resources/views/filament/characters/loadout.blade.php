@php
    /** @var list<array{slot: string, item_name: string, item_type: string, durability: string}> $rows */
@endphp

<div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden dark:divide-white/10">
    <div class="overflow-x-auto">
        <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
            <thead class="divide-y divide-gray-200 dark:divide-white/5">
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.slot') }}
                    </th>
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.item_name') }}
                    </th>
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.item_type') }}
                    </th>
                    <th class="fi-ta-header-cell px-3 py-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('admin.labels.durability') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr class="fi-ta-row">
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['slot'] }}</td>
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['item_name'] }}</td>
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['item_type'] }}</td>
                        <td class="fi-ta-cell px-3 py-2 text-sm text-gray-950 dark:text-white">{{ $row['durability'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
