<?php

return [
    'collection_title' => 'Email Templates',
    'blueprint_title' => 'Email Template',
    'tab_content' => 'Content',
    'field_title' => 'Title',
    'field_subject' => 'Subject',
    'field_subject_instructions' => 'The email subject line. Supports merge variables such as {{ contact.first_name }}.',
    'field_preview' => 'Preview text',
    'field_preview_instructions' => 'Preheader: short preview text mail clients show next to the subject. Supports merge variables such as {{ contact.first_name }}.',
    'field_layout' => 'Layout',
    'field_layout_instructions' => 'Which layout wraps this email. Empty = default layout.',
    'field_layout_default' => 'Default layout',
    'field_body' => 'Body',
    'field_body_instructions' => 'The email body, authored as rich text. Rendered to HTML for sending and preview.',
    'field_plain_text' => 'Plain text',
    'field_plain_text_instructions' => 'Optional plain-text alternative for clients that do not render HTML.',
    'field_description' => 'Description',
    'field_description_instructions' => 'Internal note. Not sent to recipients.',
    'live_preview_target' => 'Email',
    'live_preview_empty' => 'No live preview available. Open the preview while editing a template.',
];
