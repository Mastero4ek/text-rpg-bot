@props([
    'current',
    'max',
    'editable' => false,
])

<div class="fi-backpack-capacity">
    <x-filament::input.wrapper
        disabled
        class="fi-backpack-capacity-input"
    >
        <x-filament::input
            type="number"
            :value="$current"
            disabled
            :aria-label="__('admin.labels.backpack_filled')"
        />
    </x-filament::input.wrapper>

    <span class="fi-backpack-capacity-sep">
        {{ __('admin.labels.backpack_capacity_of') }}
    </span>

    @if ($editable)
        <x-filament::input.wrapper class="fi-backpack-capacity-input">
            <x-filament::input
                type="number"
                min="1"
                step="1"
                wire:model.blur="inventoryMaxRows"
                :aria-label="__('admin.labels.backpack_capacity')"
            />
        </x-filament::input.wrapper>
    @else
        <x-filament::input.wrapper
            disabled
            class="fi-backpack-capacity-input"
        >
            <x-filament::input
                type="number"
                :value="$max"
                disabled
                :aria-label="__('admin.labels.backpack_capacity')"
            />
        </x-filament::input.wrapper>
    @endif
</div>
