<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns;

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
            $height = '2.5rem';
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
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" '
            . 'stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="14" y="20" width="36" height="28" rx="4"/>'
            . '<circle cx="32" cy="34" r="7"/>'
            . '<path d="M24 20l3-5h10l3 5"/>'
            . '</svg>'
            . '</div>'
            . '</div>';
    }
}
