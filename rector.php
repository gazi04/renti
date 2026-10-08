<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveReturnTagIncompatibleWithNativeTypeRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use RectorLaravel\Rector\If_\ThrowIfRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withComposerBased(laravel: true)
    ->withSets([
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_LEGACY_FACTORIES_TO_CLASSES,
    ])
    ->withImportNames(
        removeUnusedImports: true,
    )
    ->withComposerBased(laravel: true)
    ->withCache(
        cacheDirectory: '/tmp/rector',
        cacheClass: FileCacheStorage::class,
    )
    // `tests/` is deliberately absent: Pest's closure DSL reads worse under the
    // coding-style/early-return rules (they split `||` guards into two `if`s and
    // sprintf-ify readable interpolation). `resources/` is absent too — Rector's
    // import handling does not reach the <?php block of a Livewire SFC, so it
    // rewrites imported classes as inline FQNs there.
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/public',
        __DIR__.'/routes',
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class,
        // Same call as the methods rule above: the codebase does not use
        // #[Override]. The property variant only started firing on PHP 8.5 and
        // would otherwise churn ~60 Filament `protected static string $resource`
        // declarations for no behavioural gain.
        AddOverrideAttributeToOverriddenPropertiesRector::class,
        MakeInheritedMethodVisibilitySameAsParentRector::class,
        // resolvePromo()'s guards are compound conditions; as throw_if() arguments
        // they spill across three lines and read worse than an explicit if. The
        // single-line guards elsewhere in the file keep the helper.
        ThrowIfRector::class => [
            __DIR__.'/app/Services/BookingService.php',
        ],
        // Rector reads `@return view-string` as contradicting the native `string`
        // return and deletes it, but Larastan needs it: routes/web.php passes
        // view() into Route::view(), whose $view parameter is a view-string.
        RemoveReturnTagIncompatibleWithNativeTypeRector::class => [
            __DIR__.'/app/Enums/MarketingPage.php',
        ],
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        codingStyle: true,
    )
    ->withPhpSets();
