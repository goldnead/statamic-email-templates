<?php

/*
 * Stand-ins for the brand-context symbols this package names but does not require.
 *
 * `goldnead/statamic-brand-context` is a suggest here, not a requirement — this
 * addon works on a single-brand install without it, and `Support\Brands` is
 * built around exactly that. But a class cannot implement an interface that is
 * not there, so without this file the settings declaration could not even be
 * loaded in the test suite.
 *
 * Only the shapes, and only when the real package is absent. Nothing here
 * fakes the settings *layer* — the store, the screen and the config override
 * belong to brand-context, and a fake of them would prove that the fake works.
 * That half is verified against the real package in the playground.
 *
 * The shapes must stay identical to the originals; where they drift, the
 * analysis lies instead of the code failing. Sources:
 * `statamic-brand-context/src/Contracts/SenderIdentityResolver.php` and
 * `src/Sending/SenderIdentity.php`.
 */

namespace Goldnead\BrandContext\Sending {
    if (! class_exists(SenderIdentity::class)) {
        /** Only the two fields `Support\MergeVariables::previewSender()` reads. */
        class SenderIdentity
        {
            public function __construct(
                public readonly ?string $fromAddress = null,
                public readonly ?string $fromName = null,
            ) {}
        }
    }
}

namespace Goldnead\BrandContext\Contracts {

    use Goldnead\BrandContext\Sending\SenderIdentity;

    if (! interface_exists(SenderIdentityResolver::class)) {
        interface SenderIdentityResolver
        {
            public function resolve(?int $brandId): SenderIdentity;
        }
    }

    if (! interface_exists(ProvidesSettings::class)) {
        interface ProvidesSettings
        {
            public static function settingsNamespace(): string;

            public static function settingsConfigPath(): string;

            public static function settingsPermission(): string;

            /** @return array<int, array<string, mixed>> */
            public static function settingsGroups(): array;
        }
    }
}
