<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodeQuality\Rector\Ternary\ArrayKeyExistsTernaryThenValueToCoalescingRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\FunctionLike\NarrowWideUnionReturnTypeRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withSkip([
        // Project style: empty array checks must use empty() (.ai/guidelines/code-style.md).
        SimplifyEmptyCheckOnEmptyArrayRector::class,
        // Project style: avoid the ternary and ?? operators.
        ArrayKeyExistsTernaryThenValueToCoalescingRector::class,
        // Project style: methods not using object state must stay static.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // Project style: actions and services are resolved via app().
        AppToResolveRector::class,
        // Never narrow Filament/Livewire return types.
        NarrowWideUnionReturnTypeRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    );
