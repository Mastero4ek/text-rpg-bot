@php
    use App\Filament\Support\ResourceSvg;

    $url = $getState();
    $size = '40px';
@endphp

<div class="fi-ta-image fi-circular fi-align-center">
    @if (is_string($url) && $url !== '')
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
