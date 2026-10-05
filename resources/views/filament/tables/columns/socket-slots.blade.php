@php
    use App\Filament\Support\ResourceSvg;

    $slots = $getState();
    $size = '40px';
@endphp

@if (! is_array($slots) || $slots === [])
    <div class="fi-ta-image fi-align-center">
        <p class="fi-ta-placeholder">-</p>
    </div>
@else
    <div class="fi-ta-image fi-circular fi-stacked fi-ta-image-overlap-2 fi-align-center">
        @foreach ($slots as $slot)
            @if (($slot['kind'] ?? null) === 'image')
                <img
                    src="{{ $slot['url'] }}"
                    alt=""
                    style="height: {{ $size }}; width: {{ $size }};"
                    @if (! empty($slot['tooltip']))
                        x-tooltip='{ content: @js($slot['tooltip']), theme: $store.theme }'
                    @endif
                />
            @elseif (($slot['kind'] ?? null) === 'camera')
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
