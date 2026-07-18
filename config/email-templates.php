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
