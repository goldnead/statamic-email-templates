<?php

namespace Goldnead\EmailTemplates\Support;

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintInstance;

/**
 * The blueprint for the shared `email_templates` collection.
 *
 * Per Adrian's decision the body is a `bard` field: comfortable authoring in
 * the CP, with the Bard -> email-HTML render path (BardHtmlRenderer) turning the
 * stored ProseMirror value into HTML at send/preview time. `plain_text` is the
 * optional text/plain multipart alternative; the slug is the stable
 * cross-addon reference and is never rewritten.
 */
class EmailTemplateBlueprint
{
    public const HANDLE = 'email_template';

    // A Statamic collection's blueprints live under `collections.<handle>`, so
    // derive the namespace from the single handle constant — never hard-code it.
    public const NAMESPACE = 'collections.'.EmailTemplateCollectionManager::HANDLE;

    public static function make(): BlueprintInstance
    {
        return Blueprint::make(self::HANDLE)
            ->setNamespace(self::NAMESPACE)
            ->setContents([
                'title' => __('email-templates::email_templates.blueprint_title'),
                'tabs' => [
                    'main' => [
                        'display' => __('email-templates::email_templates.tab_content'),
                        'sections' => [
                            [
                                'fields' => [
                                    [
                                        'handle' => 'title',
                                        'field' => [
                                            'type' => 'text',
                                            'required' => true,
                                            'validate' => ['required'],
                                            'display' => __('email-templates::email_templates.field_title'),
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'subject',
                                        'field' => [
                                            'type' => 'text',
                                            'display' => __('email-templates::email_templates.field_subject'),
                                            'instructions' => __('email-templates::email_templates.field_subject_instructions'),
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'body',
                                        'field' => [
                                            'type' => 'bard',
                                            'display' => __('email-templates::email_templates.field_body'),
                                            'instructions' => __('email-templates::email_templates.field_body_instructions'),
                                            'buttons' => [
                                                'h2', 'h3', 'bold', 'italic', 'underline',
                                                'unorderedlist', 'orderedlist', 'quote',
                                                'anchor', 'image', 'table', 'horizontalrule',
                                                'removeformat',
                                            ],
                                            'save_html' => false,
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'plain_text',
                                        'field' => [
                                            'type' => 'textarea',
                                            'display' => __('email-templates::email_templates.field_plain_text'),
                                            'instructions' => __('email-templates::email_templates.field_plain_text_instructions'),
                                            'localizable' => true,
                                        ],
                                    ],
                                    [
                                        'handle' => 'description',
                                        'field' => [
                                            'type' => 'textarea',
                                            'display' => __('email-templates::email_templates.field_description'),
                                            'instructions' => __('email-templates::email_templates.field_description_instructions'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
