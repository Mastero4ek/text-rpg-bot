@props([
    'current',
    'max',
    'editable' => false,
    'filledLabel',
    'ofLabel',
    'capacityLabel',
    'maxWireModel',
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
            :aria-label="$filledLabel"
        />
    </x-filament::input.wrapper>

    <span class="fi-backpack-capacity-sep">
        {{ $ofLabel }}
    </span>

    @if ($editable)
        <x-filament::input.wrapper class="fi-backpack-capacity-input">
            <x-filament::input
                type="number"
                min="1"
                step="1"
                wire:model.blur="{{ $maxWireModel }}"
                :aria-label="$capacityLabel"
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
                :aria-label="$capacityLabel"
            />
        </x-filament::input.wrapper>
    @endif
</div>
