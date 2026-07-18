<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When false the addon does not ensure the collection or
    | register its CP navigation entry. The import command and resolver stay
    | callable (they operate on the collection directly).
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Branded layout
    |--------------------------------------------------------------------------
    |
    | Optional. The name of a host-app Blade layout that wraps every rendered
    | template body in the app's branded email shell (header / footer / styling).
    | The layout must expose a `@yield('content')` section; the rendered body is
    | injected there and the subject is passed as `$title`.
    |
    | Default `null` keeps the addon marketplace-generic: bodies render raw, as
    | before. A host app opts in by pointing this at one of its own layouts,
    | e.g. adriangoldner.com sets it to `emails.layout`. Applies to BOTH the CP
    | Live Preview and every real send (they share one render path).
    |
    */

    'branded_layout' => null,

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    |
    | The CP preview renders a template with placeholder recipient data so it
    | can be viewed without a real contact. `sample_data` is the documented
    | default merge-variable set; it is deep-merged over the built-in defaults
    | and can be extended or overridden here. Values are referenced in
    | templates as `{{ dotted.key }}`, e.g. `{{ contact.first_name }}`.
    |
    */

    'preview' => [
        'sample_data' => [
            'contact' => [
                'first_name' => 'Maria',
                'last_name' => 'Beispiel',
                'full_name' => 'Maria Beispiel',
                'email' => 'maria.beispiel@example.com',
                'salutation' => 'Hallo Maria',
            ],
            'unsubscribe_url' => 'https://example.com/newsletter/abmelden',
        ],
    ],

];
