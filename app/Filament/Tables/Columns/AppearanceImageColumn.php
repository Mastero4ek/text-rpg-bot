<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns;

use App\Filament\Support\ResourceSvg;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;

final class AppearanceImageColumn extends SpatieMediaLibraryImageColumn
{
    public function getImageUrl(?string $state = null): ?string
    {
        $url = parent::getImageUrl($state);

        if (! is_string($url) || $url === '') {
            return $url;
        }

        $appUrl = mb_rtrim((string) config('app.url'), '/');

        if (str_starts_with($url, $appUrl . '/')) {
            return mb_substr($url, mb_strlen($appUrl));
        }

        return $url;
    }

    public function toEmbeddedHtml(): string
    {
        if ($this->getState() !== []) {
            return parent::toEmbeddedHtml();
        }

        if (filled($this->getDefaultImageUrl())) {
            return parent::toEmbeddedHtml();
        }

        return $this->toEmptyAppearanceHtml();
    }

    private function toEmptyAppearanceHtml(): string
    {
        $alignment = $this->getAlignment();

        if ($alignment instanceof Alignment) {
            $alignmentClass = 'fi-align-' . $alignment->value;
        } elseif (is_string($alignment)) {
            $alignmentClass = $alignment;
        } else {
            $alignmentClass = '';
        }

        $height = $this->getImageHeight();

        if ($height === null) {
            $height = '40px';
        }

        $width = $this->getImageWidth();

        if ($width === null && ($this->isCircular() || $this->isSquare())) {
            $width = $height;
        }

        $sizeStyle = 'height: ' . e($height) . ';';

        if ($width !== null) {
            $sizeStyle .= ' width: ' . e($width) . ';';
        }

        $circularClass = '';

        if ($this->isCircular()) {
            $circularClass = 'fi-circular';
        }

        return '<div class="fi-ta-image ' . e($circularClass) . ' ' . e($alignmentClass) . '">'
            . '<div class="fi-ta-appearance-placeholder" style="' . $sizeStyle . '">'
            . ResourceSvg::markup('appearance-camera.svg')
            . '</div>'
            . '</div>';
    }
}
