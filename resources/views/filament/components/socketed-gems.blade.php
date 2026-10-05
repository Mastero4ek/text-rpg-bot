@php
    use App\Filament\Support\GemSocketSlots;
    use App\Filament\Support\ResourceSvg;
    use App\Models\Inventory;

    $record = $getRecord();
    $slots = $record instanceof Inventory ? GemSocketSlots::forInventory($record) : [];
    $size = '40px';
@endphp

@if ($slots === [])
    <div class="fi-ta-image fi-align-center">
        <p class="fi-ta-placeholder">-</p>
    </div>
@else
    <div class="fi-ta-image fi-circular fi-stacked fi-ta-image-overlap-2 fi-align-center">
        @foreach ($slots as $slot)
            @if ($slot['kind'] === 'image')
                <img
                    src="{{ $slot['url'] }}"
                    alt=""
                    style="height: {{ $size }}; width: {{ $size }};"
                    @if (! empty($slot['tooltip']))
                        x-tooltip='{ content: @js($slot['tooltip']), theme: $store.theme }'
                    @endif
                />
            @elseif ($slot['kind'] === 'camera')
                <div
                    class="fi-ta-appearance-placeholder"
                    style="height: {{ $size }}; width: {{ $size }}; flex-shrink: 0;"
                    @if (! empty($slot['tooltip']))
                        x-tooltip='{ content: @js($slot['tooltip']), theme: $store.theme }'
                    @endif
                >
                    {!! ResourceSvg::markup('appearance-camera.svg') !!}
                </div>
            @else
                <div class="fi-ta-appearance-placeholder" style="height: {{ $size }}; width: {{ $size }}; flex-shrink: 0;">
                    {!! ResourceSvg::markup('gem-sparkles.svg') !!}
                </div>
            @endif
        @endforeach
    </div>
@endif
