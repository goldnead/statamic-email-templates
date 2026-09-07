<?php

/*
 * A stand-in for the one interface `Support\Settings` implements.
 *
 * `goldnead/statamic-brand-context` is a suggest here, not a requirement — this
 * addon works on a single-brand install without it, and `Support\Brands` is
 * built around exactly that. But a class cannot implement an interface that is
 * not there, so without this file the settings declaration could not even be
 * loaded in the test suite.
 *
 * Only the interface, and only when the real package is absent. Nothing here
 * fakes the settings *layer* — the store, the screen and the config override
 * belong to brand-context, and a fake of them would prove that the fake works.
 * That half is verified against the real package in the playground.
 */

namespace Goldnead\BrandContext\Contracts {
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
