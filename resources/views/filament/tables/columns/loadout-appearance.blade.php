@php
    use App\Filament\Support\ResourceSvg;

    $record = $getRecord();
    $hasItem = is_array($record) && ($record['equipped'] ?? false);
    $url = $getState();
    $size = '40px';
@endphp

<div class="fi-ta-image fi-circular fi-align-center">
    @if (! $hasItem)
        <p class="fi-ta-placeholder">-</p>
    @elseif (is_string($url) && $url !== '')
        <img
            src="{{ $url }}"
            alt=""
            style="height: {{ $size }}; width: {{ $size }};"
        />
    @else
        <div class="fi-ta-appearance-placeholder" style="height: {{ $size }}; width: {{ $size }};">
            {!! ResourceSvg::markup('appearance-camera.svg') !!}
        </div>
    @endif
</div>
