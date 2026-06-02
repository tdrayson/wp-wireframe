<?php

/**
 * Field Reference — kitchen sink demo of every field type.
 *
 * @package FieldReference
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

return [
    'title'    => __('Field Reference', 'field-reference'),
    'subtitle' => __('Every field type supported by WP Wireframe.', 'field-reference'),
    'tabs'     => [

        // ─── Example ──────────────────────────────────────
        //
        // A "realistic" settings page for a fictional newsletter / notifications
        // product. Groups fields the way a real plugin would, rather than by
        // field type, and touches most of the framework's field types in one go.
        [
            'id'       => 'example',
            'title'    => __('Example', 'field-reference'),
            'sections' => [

                // Branding ------------------------------------------------
                [
                    'id'          => 'ex_branding',
                    'title'       => __('Branding', 'field-reference'),
                    'description' => __('How your app appears to recipients.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ex_app_name',
                            'type'        => 'text',
                            'label'       => __('App name', 'field-reference'),
                            'description' => __('Shown in the header of every email.', 'field-reference'),
                            'default'     => 'Acme Newsletter',
                            'columns'     => 6,
                            'required'    => true,
                        ],
                        [
                            'id'          => 'ex_slug',
                            'type'        => 'text',
                            'label'       => __('Public slug', 'field-reference'),
                            'description' => __('Public URL: https://example.com/n/{ex_slug}', 'field-reference'),
                            'default'     => 'acme',
                            'columns'     => 6,
                            'args'        => ['placeholder' => 'acme'],
                        ],
                        [
                            'id'      => 'ex_logo',
                            'type'    => 'file',
                            'label'   => __('Logo', 'field-reference'),
                            'columns' => 6,
                            'args'    => ['mime_types' => 'image', 'media_title' => __('Choose a logo', 'field-reference'), 'media_button' => __('Use image', 'field-reference')],
                        ],
                        [
                            'id'          => 'ex_brand_color',
                            'type'        => 'color',
                            'label'       => __('Brand color', 'field-reference'),
                            'description' => __('Primary accent in buttons and links.', 'field-reference'),
                            'default'     => '#3858e9',
                            'columns'     => 6,
                        ],
                        [
                            'id'          => 'ex_tagline',
                            'type'        => 'textarea',
                            'label'       => __('Tagline', 'field-reference'),
                            'description' => __('Short sentence shown under the app name.', 'field-reference'),
                            'default'     => 'The friendliest newsletter tool on the internet.',
                            'args'        => ['rows' => 2, 'placeholder' => __('One sentence about your product.', 'field-reference')],
                        ],
                    ],
                ],

                // Sender --------------------------------------------------
                [
                    'id'          => 'ex_sender',
                    'title'       => __('Sender identity', 'field-reference'),
                    'description' => __('From: and Reply-To: headers for outgoing mail.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ex_from_name',
                            'type'        => 'text',
                            'label'       => __('From name', 'field-reference'),
                            'default'     => 'Acme Team',
                            'columns'     => 6,
                        ],
                        [
                            'id'          => 'ex_from_email',
                            'type'        => 'email',
                            'label'       => __('From email', 'field-reference'),
                            'description' => __('Shown as: {ex_from_name} <{ex_from_email}>', 'field-reference'),
                            'default'     => 'hello@example.com',
                            'columns'     => 6,
                            'required'    => true,
                        ],
                        [
                            'id'          => 'ex_reply_to',
                            'type'        => 'email',
                            'label'       => __('Reply-to', 'field-reference'),
                            'description' => __('Replies from recipients go here. Leave blank to reuse the From address.', 'field-reference'),
                            'columns'     => 12,
                        ],
                    ],
                ],

                // Integrations --------------------------------------------
                [
                    'id'          => 'ex_integrations',
                    'title'       => __('Integrations', 'field-reference'),
                    'description' => __('Talk to the rest of your stack.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ex_api_key',
                            'type'        => 'text',
                            'label'       => __('API key', 'field-reference'),
                            'description' => __('Paste into your client app. Treat as a secret.', 'field-reference'),
                            'default'     => 'demo_key_NOT_A_REAL_KEY_replace_me_in_production',
                            'columns'     => 12,
                            'args'        => ['readonly' => true, 'copyable' => true],
                        ],
                        [
                            'id'          => 'ex_webhook_url',
                            'type'        => 'url',
                            'label'       => __('Webhook endpoint', 'field-reference'),
                            'description' => __('We POST delivery events to this URL.', 'field-reference'),
                            'columns'     => 8,
                            'args'        => ['placeholder' => 'https://your-app.com/hooks/newsletter'],
                        ],
                        [
                            'id'          => 'ex_webhook_secret',
                            'type'        => 'password',
                            'label'       => __('Signing secret', 'field-reference'),
                            'description' => __('Used to verify request authenticity.', 'field-reference'),
                            'default'     => 'whsec_3sR7kLmN9pQ2vX4',
                            'columns'     => 4,
                            'args'        => ['copyable' => true],
                        ],
                        [
                            'id'   => 'ex_integration_status',
                            'type' => 'html',
                            'args' => [
                                'variant' => 'info',
                                'content' => __('<p><strong>Tip:</strong> Rotate your signing secret every 90 days. Check the <a href="#">integration logs</a> after rotating.</p>', 'field-reference'),
                            ],
                        ],
                    ],
                ],

                // Notifications -------------------------------------------
                [
                    'id'          => 'ex_notifications',
                    'title'       => __('Notifications', 'field-reference'),
                    'description' => __('Decide who gets pinged and how often.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'      => 'ex_notifications_enabled',
                            'type'    => 'toggle',
                            'label'   => __('Enable notifications', 'field-reference'),
                            'default' => true,
                            'columns' => 12,
                        ],
                        [
                            'id'          => 'ex_channels',
                            'type'        => 'checkboxes',
                            'label'       => __('Delivery channels', 'field-reference'),
                            'description' => __('Send each event to one or more channels.', 'field-reference'),
                            'default'     => ['email'],
                            'columns'     => 6,
                            'conditions'  => ['all' => [['field' => 'ex_notifications_enabled', 'operator' => 'truthy']]],
                            'args'        => [
                                'options' => [
                                    'email'   => __('Email', 'field-reference'),
                                    'slack'   => __('Slack', 'field-reference'),
                                    'webhook' => __('Webhook', 'field-reference'),
                                ],
                            ],
                        ],
                        [
                            'id'          => 'ex_digest',
                            'type'        => 'select',
                            'label'       => __('Digest frequency', 'field-reference'),
                            'description' => __('How often to bundle events into a digest.', 'field-reference'),
                            'default'     => 'weekly',
                            'columns'     => 6,
                            'conditions'  => ['all' => [['field' => 'ex_notifications_enabled', 'operator' => 'truthy']]],
                            'args'        => [
                                'options' => [
                                    'realtime' => __('Real-time', 'field-reference'),
                                    'daily'    => __('Daily', 'field-reference'),
                                    'weekly'   => __('Weekly', 'field-reference'),
                                    'monthly'  => __('Monthly', 'field-reference'),
                                ],
                            ],
                        ],
                        [
                            'id'          => 'ex_quiet_start',
                            'type'        => 'time',
                            'label'       => __('Quiet hours start', 'field-reference'),
                            'default'     => '22:00',
                            'columns'     => 6,
                            'conditions'  => ['all' => [['field' => 'ex_notifications_enabled', 'operator' => 'truthy']]],
                        ],
                        [
                            'id'          => 'ex_quiet_end',
                            'type'        => 'time',
                            'label'       => __('Quiet hours end', 'field-reference'),
                            'description' => __('Between {ex_quiet_start} and {ex_quiet_end}, only critical alerts are sent.', 'field-reference'),
                            'default'     => '07:00',
                            'columns'     => 6,
                            'conditions'  => ['all' => [['field' => 'ex_notifications_enabled', 'operator' => 'truthy']]],
                        ],
                    ],
                ],

                // Appearance ----------------------------------------------
                [
                    'id'          => 'ex_appearance',
                    'title'       => __('Appearance', 'field-reference'),
                    'description' => __('How the admin UI looks to your team.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'      => 'ex_theme',
                            'type'    => 'radio',
                            'label'   => __('Theme', 'field-reference'),
                            'default' => 'auto',
                            'columns' => 4,
                            'args'    => [
                                'options' => [
                                    'light' => __('Light', 'field-reference'),
                                    'dark'  => __('Dark', 'field-reference'),
                                    'auto'  => __('Match system', 'field-reference'),
                                ],
                            ],
                        ],
                        [
                            'id'          => 'ex_density',
                            'type'        => 'range',
                            'label'       => __('UI density', 'field-reference'),
                            'description' => __('Drag to tune how much breathing room the UI has.', 'field-reference'),
                            'default'     => 6,
                            'columns'     => 8,
                            'args'        => ['min' => 1, 'max' => 10, 'step' => 1],
                        ],
                        [
                            'id'          => 'ex_launch_date',
                            'type'        => 'date',
                            'label'       => __('Go-live date', 'field-reference'),
                            'description' => __('The first day recipients will receive emails.', 'field-reference'),
                            'columns'     => 6,
                        ],
                        [
                            'id'          => 'ex_daily_cap',
                            'type'        => 'number',
                            'label'       => __('Daily send cap', 'field-reference'),
                            'description' => __('Safety limit — we never send more than this per day.', 'field-reference'),
                            'default'     => 500,
                            'columns'     => 6,
                            'args'        => ['min' => 10, 'max' => 10000, 'step' => 10, 'integer' => true],
                        ],
                    ],
                ],

                // Email template ------------------------------------------
                [
                    'id'          => 'ex_template',
                    'title'       => __('Email template', 'field-reference'),
                    'description' => __('Edit the default welcome email.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ex_subject',
                            'type'        => 'text',
                            'label'       => __('Subject', 'field-reference'),
                            'description' => __('Plain text. Variables like {{first_name}} are substituted at send time.', 'field-reference'),
                            'default'     => 'Welcome to {{app_name}}!',
                            'columns'     => 12,
                        ],
                        [
                            'id'      => 'ex_body',
                            'type'    => 'editor',
                            'label'   => __('Body', 'field-reference'),
                            'default' => '<p>Hey {{first_name}}, thanks for subscribing.</p>',
                            'args'    => ['rows' => 8],
                        ],
                    ],
                ],

                // Advanced -------------------------------------------------
                [
                    'id'          => 'ex_advanced',
                    'title'       => __('Advanced', 'field-reference'),
                    'description' => __('Power-user options. Most teams won\'t need these.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'      => 'ex_test_mode',
                            'type'    => 'toggle',
                            'label'   => __('Test mode', 'field-reference'),
                            'default' => false,
                        ],
                        [
                            'id'          => 'ex_test_recipients',
                            'type'        => 'textarea',
                            'label'       => __('Test recipients', 'field-reference'),
                            'description' => __('One email per line. While test mode is on, mail only goes to these addresses.', 'field-reference'),
                            'conditions'  => ['all' => [['field' => 'ex_test_mode', 'operator' => 'truthy']]],
                            'args'        => ['rows' => 4, 'placeholder' => "dev@example.com\nqa@example.com"],
                        ],
                        [
                            'id'          => 'ex_custom_css',
                            'type'        => 'code_editor',
                            'label'       => __('Custom CSS', 'field-reference'),
                            'description' => __('Injected into every email. Keep it compatible with older mail clients.', 'field-reference'),
                            'args'        => ['mode' => 'css', 'rows' => 6],
                        ],
                        [
                            'id'    => 'ex_redirects',
                            'type'  => 'repeater',
                            'label' => __('Link redirects', 'field-reference'),
                            'args'  => [
                                'sortable'       => true,
                                'collapsible'    => true,
                                'duplicate_row'  => true,
                                'add_label'      => __('Add redirect', 'field-reference'),
                                'empty_message'  => __('No redirects configured.', 'field-reference'),
                                'title_template' => '{from} → {to}',
                                'subfields'      => [
                                    ['id' => 'from', 'type' => 'text', 'label' => __('From path', 'field-reference'), 'required' => true, 'columns' => 6, 'args' => ['placeholder' => '/old']],
                                    ['id' => 'to',   'type' => 'url',  'label' => __('Destination', 'field-reference'), 'columns' => 6, 'args' => ['placeholder' => 'https://example.com/new']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // ─── Text Fields ──────────────────────────────────
        [
            'id'       => 'text_fields',
            'title'    => __('Text', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'basic_text',
                    'title'       => __('Text inputs', 'field-reference'),
                    'description' => __('Standard text-based input fields.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ref_text',
                            'type'        => 'text',
                            'label'       => __('Text', 'field-reference'),
                            'description' => __('A basic single-line text input.', 'field-reference'),
                            'columns'     => 6,
                            'args'        => ['placeholder' => __('Enter some text', 'field-reference')],
                        ],
                        [
                            'id'          => 'ref_email',
                            'type'        => 'email',
                            'label'       => __('Email', 'field-reference'),
                            'description' => __('Validated as a valid email address.', 'field-reference'),
                            'columns'     => 6,
                            'args'        => ['placeholder' => __('name@example.com', 'field-reference')],
                        ],
                        [
                            'id'          => 'ref_url',
                            'type'        => 'url',
                            'label'       => __('URL', 'field-reference'),
                            'description' => __('Validated as a valid URL.', 'field-reference'),
                            'columns'     => 6,
                            'args'        => ['placeholder' => __('https://example.com', 'field-reference')],
                        ],
                        [
                            'id'          => 'ref_password',
                            'type'        => 'password',
                            'label'       => __('Password', 'field-reference'),
                            'description' => __('Masked input, stored as-is (not hashed).', 'field-reference'),
                            'columns'     => 6,
                        ],
                        [
                            'id'          => 'ref_textarea',
                            'type'        => 'textarea',
                            'label'       => __('Textarea', 'field-reference'),
                            'description' => __('Multi-line text input.', 'field-reference'),
                            'args'        => [
                                'rows'        => 4,
                                'placeholder' => __('Write something longer here...', 'field-reference'),
                            ],
                        ],
                        [
                            'id'      => 'ref_hidden',
                            'type'    => 'hidden',
                            'default' => 'hidden-value-1.0',
                        ],
                    ],
                ],
                [
                    'id'          => 'copy_readonly',
                    'title'       => __('Copy & read-only', 'field-reference'),
                    'description' => __('Set args.copyable to show an inline copy button. Set args.readonly to lock the value.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ref_copyable_text',
                            'type'        => 'text',
                            'label'       => __('Copyable text', 'field-reference'),
                            'description' => __('Click the icon on the right to copy.', 'field-reference'),
                            'default'     => 'copy-me-to-the-clipboard',
                            'columns'     => 6,
                            'args'        => ['copyable' => true],
                        ],
                        [
                            'id'          => 'ref_readonly_text',
                            'type'        => 'text',
                            'label'       => __('Read-only text', 'field-reference'),
                            'description' => __('Value is visible but cannot be edited.', 'field-reference'),
                            'default'     => 'generated-id-4f3a9b2c',
                            'columns'     => 6,
                            'args'        => ['readonly' => true],
                        ],
                        [
                            'id'          => 'ref_api_key',
                            'type'        => 'text',
                            'label'       => __('API key (read-only + copyable)', 'field-reference'),
                            'description' => __('Classic combo for tokens and generated keys.', 'field-reference'),
                            'default'     => 'demo_key_NOT_A_REAL_KEY_replace_me_in_production',
                            'columns'     => 12,
                            'args'        => ['readonly' => true, 'copyable' => true],
                        ],
                        [
                            'id'          => 'ref_copyable_textarea',
                            'type'        => 'textarea',
                            'label'       => __('Copyable textarea', 'field-reference'),
                            'description' => __('Useful for webhook URLs, config blobs, etc.', 'field-reference'),
                            'default'     => "line one\nline two\nline three",
                            'args'        => ['rows' => 4, 'copyable' => true, 'readonly' => true],
                        ],
                        [
                            'id'          => 'ref_copyable_number',
                            'type'        => 'number',
                            'label'       => __('Copyable number', 'field-reference'),
                            'description' => __('Copy + read-only also work on number fields.', 'field-reference'),
                            'default'     => 42,
                            'columns'     => 6,
                            'args'        => ['copyable' => true, 'readonly' => true],
                        ],
                    ],
                ],
            ],
        ],

        // ─── Choice Fields ────────────────────────────────
        [
            'id'       => 'choice_fields',
            'title'    => __('Choices', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'single_choice',
                    'title'       => __('Single choice', 'field-reference'),
                    'description' => __('Pick one value from a set of options.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ref_select',
                            'type'        => 'select',
                            'label'       => __('Select', 'field-reference'),
                            'description' => __('Dropdown menu. Single selection.', 'field-reference'),
                            'default'     => 'option_b',
                            'columns'     => 6,
                            'args'        => [
                                'options' => [
                                    'option_a' => __('Option A', 'field-reference'),
                                    'option_b' => __('Option B', 'field-reference'),
                                    'option_c' => __('Option C', 'field-reference'),
                                ],
                            ],
                        ],
                        [
                            'id'          => 'ref_radio',
                            'type'        => 'radio',
                            'label'       => __('Radio', 'field-reference'),
                            'description' => __('Vertical radio button group.', 'field-reference'),
                            'default'     => 'small',
                            'columns'     => 6,
                            'args'        => [
                                'options' => [
                                    'small'  => __('Small', 'field-reference'),
                                    'medium' => __('Medium', 'field-reference'),
                                    'large'  => __('Large', 'field-reference'),
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id'          => 'multi_choice',
                    'title'       => __('Multiple choice', 'field-reference'),
                    'description' => __('Select one or more values.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ref_multiselect',
                            'type'        => 'select',
                            'label'       => __('Multi-select', 'field-reference'),
                            'description' => __('Searchable tag input. Add "multiple" => true to any select.', 'field-reference'),
                            'default'     => ['posts'],
                            'columns'     => 6,
                            'args'        => [
                                'multiple' => true,
                                'options'  => [
                                    'posts'    => __('Posts', 'field-reference'),
                                    'pages'    => __('Pages', 'field-reference'),
                                    'products' => __('Products', 'field-reference'),
                                    'media'    => __('Media', 'field-reference'),
                                    'comments' => __('Comments', 'field-reference'),
                                ],
                            ],
                        ],
                        [
                            'id'          => 'ref_checkboxes',
                            'type'        => 'checkboxes',
                            'label'       => __('Checkboxes', 'field-reference'),
                            'description' => __('Stored as an array of selected keys.', 'field-reference'),
                            'default'     => ['posts'],
                            'columns'     => 6,
                            'args'        => [
                                'options' => [
                                    'posts'    => __('Posts', 'field-reference'),
                                    'pages'    => __('Pages', 'field-reference'),
                                    'products' => __('Products', 'field-reference'),
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id'          => 'booleans',
                    'title'       => __('Boolean fields', 'field-reference'),
                    'description' => __('On/off values stored as true or false.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ref_toggle',
                            'type'        => 'toggle',
                            'label'       => __('Toggle', 'field-reference'),
                            'description' => __('On/off switch.', 'field-reference'),
                            'default'     => true,
                            'columns'     => 6,
                        ],
                        [
                            'id'          => 'ref_checkbox',
                            'type'        => 'checkbox',
                            'label'       => __('Checkbox', 'field-reference'),
                            'description' => __('Single checkbox.', 'field-reference'),
                            'default'     => false,
                            'columns'     => 6,
                        ],
                    ],
                ],
            ],
        ],

        // ─── Numeric, Date & Color ────────────────────────
        [
            'id'       => 'numeric_fields',
            'title'    => __('Numbers & Date', 'field-reference'),
            'sections' => [
                [
                    'id'     => 'numeric',
                    'title'  => __('Numeric inputs', 'field-reference'),
                    'fields' => [
                        [
                            'id'          => 'ref_number',
                            'type'        => 'number',
                            'label'       => __('Number', 'field-reference'),
                            'description' => __('Integer with min/max bounds.', 'field-reference'),
                            'default'     => 10,
                            'columns'     => 6,
                            'args'        => ['min' => 1, 'max' => 100, 'step' => 1, 'integer' => true],
                        ],
                        [
                            'id'          => 'ref_range',
                            'type'        => 'range',
                            'label'       => __('Range slider', 'field-reference'),
                            'description' => __('Drag to select a value.', 'field-reference'),
                            'default'     => 50,
                            'columns'     => 6,
                            'args'        => ['min' => 0, 'max' => 100, 'step' => 5, 'suffix' => '%'],
                        ],
                    ],
                ],
                [
                    'id'     => 'date_time',
                    'title'  => __('Date & time', 'field-reference'),
                    'fields' => [
                        [
                            'id'          => 'ref_date',
                            'type'        => 'date',
                            'label'       => __('Date', 'field-reference'),
                            'description' => __('Stored as YYYY-MM-DD.', 'field-reference'),
                            'columns'     => 6,
                        ],
                        [
                            'id'          => 'ref_time',
                            'type'        => 'time',
                            'label'       => __('Time', 'field-reference'),
                            'description' => __('24-hour format (HH:MM).', 'field-reference'),
                            'default'     => '09:00',
                            'columns'     => 6,
                        ],
                    ],
                ],
                [
                    'id'     => 'colour',
                    'title'  => __('Color', 'field-reference'),
                    'fields' => [
                        [
                            'id'          => 'ref_color',
                            'type'        => 'color',
                            'label'       => __('Color picker', 'field-reference'),
                            'description' => __('Hex color value. Opens in a dropdown.', 'field-reference'),
                            'default'     => '#3858e9',
                            'columns'     => 6,
                        ],
                    ],
                ],
            ],
        ],

        // ─── Rich Content ─────────────────────────────────
        [
            'id'       => 'rich_content',
            'title'    => __('Rich content', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'editor',
                    'title'       => __('WYSIWYG editor', 'field-reference'),
                    'description' => __('TinyMCE editor. Output sanitised with wp_kses_post().', 'field-reference'),
                    'fields'      => [
                        ['id' => 'ref_editor', 'type' => 'editor', 'label' => __('Editor', 'field-reference'), 'args' => ['rows' => 8]],
                    ],
                ],
                [
                    'id'          => 'code',
                    'title'       => __('Code editor', 'field-reference'),
                    'description' => __('CodeMirror with syntax highlighting.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ref_code',
                            'type'        => 'code_editor',
                            'label'       => __('CSS code', 'field-reference'),
                            'description' => __('Mode set to CSS. Also supports js, html, php, json.', 'field-reference'),
                            'args'        => ['mode' => 'css', 'rows' => 8],
                        ],
                    ],
                ],
                [
                    'id'          => 'html_variants',
                    'title'       => __('HTML display', 'field-reference'),
                    'description' => __('Stateless display blocks. No stored value.', 'field-reference'),
                    'fields'      => [
                        ['id' => 'ref_html_info',    'type' => 'html', 'args' => ['variant' => 'info',    'content' => __('<p><strong>Info:</strong> Use for tips or docs links.</p>', 'field-reference')]],
                        ['id' => 'ref_html_success', 'type' => 'html', 'args' => ['variant' => 'success', 'content' => __('<p><strong>Success:</strong> Confirm a positive state.</p>', 'field-reference')]],
                        ['id' => 'ref_html_warning', 'type' => 'html', 'args' => ['variant' => 'warning', 'content' => __('<p><strong>Warning:</strong> Needs attention.</p>', 'field-reference')]],
                        ['id' => 'ref_html_error',   'type' => 'html', 'args' => ['variant' => 'error',   'content' => __('<p><strong>Error:</strong> Broken or blocked state.</p>', 'field-reference')]],
                    ],
                ],
            ],
        ],

        // ─── Media ────────────────────────────────────────
        [
            'id'       => 'media_fields',
            'title'    => __('Media', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'media',
                    'title'       => __('Media library', 'field-reference'),
                    'description' => __('Stored as an array of attachment IDs.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'          => 'ref_file_single',
                            'type'        => 'file',
                            'label'       => __('Single image', 'field-reference'),
                            'description' => __('Image-only MIME type filter.', 'field-reference'),
                            'columns'     => 6,
                            'args'        => ['mime_types' => 'image', 'media_title' => __('Choose an image', 'field-reference'), 'media_button' => __('Use image', 'field-reference')],
                        ],
                        [
                            'id'          => 'ref_file_multi',
                            'type'        => 'file',
                            'label'       => __('Multiple files', 'field-reference'),
                            'description' => __('Any MIME type, multi-select enabled.', 'field-reference'),
                            'columns'     => 6,
                            'args'        => ['multiple' => true, 'media_title' => __('Choose files', 'field-reference')],
                        ],
                    ],
                ],
            ],
        ],

        // ─── Advanced ─────────────────────────────────────
        [
            'id'       => 'advanced_fields',
            'title'    => __('Advanced', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'conditions',
                    'title'       => __('Conditional visibility', 'field-reference'),
                    'description' => __('Fields that show/hide based on other field values.', 'field-reference'),
                    'fields'      => [
                        ['id' => 'ref_gate', 'type' => 'toggle', 'label' => __('Enable advanced mode', 'field-reference'), 'default' => false],
                        [
                            'id'          => 'ref_conditional',
                            'type'        => 'text',
                            'label'       => __('Advanced setting', 'field-reference'),
                            'description' => __('Only visible when advanced mode is on.', 'field-reference'),
                            'conditions'  => ['all' => [['field' => 'ref_gate', 'operator' => 'truthy']]],
                        ],
                    ],
                ],
                [
                    'id'          => 'repeater',
                    'title'       => __('Repeater', 'field-reference'),
                    'description' => __('Add, remove, reorder, and duplicate rows.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'    => 'ref_repeater',
                            'type'  => 'repeater',
                            'label' => __('Key-value pairs', 'field-reference'),
                            'args'  => [
                                'sortable'       => true,
                                'collapsible'    => true,
                                'duplicate_row'  => true,
                                'max_rows'       => 5,
                                'add_label'      => __('Add pair', 'field-reference'),
                                'empty_message'  => __('No pairs added yet.', 'field-reference'),
                                'title_template' => '{key}',
                                'subfields'      => [
                                    ['id' => 'key',   'type' => 'text', 'label' => __('Key', 'field-reference'),   'required' => true, 'columns' => 6, 'args' => ['placeholder' => __('meta_key', 'field-reference')]],
                                    ['id' => 'value', 'type' => 'text', 'label' => __('Value', 'field-reference'), 'columns' => 6, 'args' => ['placeholder' => __('meta_value', 'field-reference')]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // ─── Data table ───────────────────────────────────
        [
            'id'       => 'data_table',
            'title'    => __('Data table', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'entries',
                    'title'       => __('Entries', 'field-reference'),
                    'description' => __('A paginated, searchable table backed by PHP callbacks. Data, delete, and archive all dispatch through the framework\'s generic REST controller.', 'field-reference'),
                    'fields'      => [
                        [
                            'id'    => 'demo_entries',
                            'type'  => 'table',
                            'label' => __('Demo entries', 'field-reference'),
                            'args'  => [
                                'data_callback' => 'field_reference_entries_fetch',
                                'per_page'      => 10,
                                'search'        => true,
                                'detail_view'   => [
                                    'fetch_callback'  => 'field_reference_entries_find',
                                    'render_callback' => 'field_reference_entries_render',
                                    'title'           => 'field_reference_entries_title',
                                    'back_label'      => __('Back to all entries', 'field-reference'),
                                ],
                                'fields'        => [
                                    ['id' => 'id',      'label' => __('ID', 'field-reference'),      'type' => 'integer',  'sortable' => true],
                                    ['id' => 'name',    'label' => __('Name', 'field-reference'),    'type' => 'text',     'sortable' => true, 'searchable' => true],
                                    ['id' => 'email',   'label' => __('Email', 'field-reference'),   'type' => 'text',     'searchable' => true],
                                    [
                                        'id'       => 'status',
                                        'label'    => __('Status', 'field-reference'),
                                        'elements' => [
                                            'active'   => __('Active', 'field-reference'),
                                            'pending'  => __('Pending', 'field-reference'),
                                            'archived' => __('Archived', 'field-reference'),
                                        ],
                                    ],
                                    ['id' => 'created', 'label' => __('Created', 'field-reference'), 'type' => 'datetime', 'sortable' => true],
                                ],
                                'actions' => [
                                    [
                                        'id'           => 'view',
                                        'label'        => __('View entry', 'field-reference'),
                                        'opens_detail' => true,
                                    ],
                                    [
                                        'id'              => 'resend',
                                        'label'           => __('Re-send notification', 'field-reference'),
                                        'callback'        => 'field_reference_entries_resend',
                                        'show_in_detail'  => true,
                                    ],
                                    [
                                        'id'             => 'archive',
                                        'label'          => __('Archive', 'field-reference'),
                                        'callback'       => 'field_reference_entries_archive',
                                        'supports_bulk'  => true,
                                        'show_in_detail' => true,
                                    ],
                                    [
                                        'id'             => 'delete',
                                        'label'          => __('Delete', 'field-reference'),
                                        'callback'       => 'field_reference_entries_delete',
                                        'is_destructive' => true,
                                        'confirm'        => __('Delete the selected entries?', 'field-reference'),
                                        'supports_bulk'  => true,
                                        'show_in_detail' => true,
                                    ],
                                    [
                                        'id'       => 'reset',
                                        'label'    => __('Reset demo data', 'field-reference'),
                                        'callback' => 'field_reference_entries_reset',
                                        'confirm'  => __('Reset all demo entries to their defaults?', 'field-reference'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // ─── Tools ────────────────────────────────────────
        [
            'id'       => 'tools',
            'title'    => __('Tools', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'backup',
                    'title'       => __('Export & import', 'field-reference'),
                    'description' => __('Backup and restore settings as JSON.', 'field-reference'),
                    'fields'      => [
                        ['id' => 'ref_export', 'type' => 'export', 'label' => __('Export', 'field-reference'), 'description' => __('Download all settings as JSON.', 'field-reference'), 'columns' => 6, 'args' => ['button_label' => __('Download JSON', 'field-reference'), 'filename' => 'field-reference-settings']],
                        ['id' => 'ref_import', 'type' => 'import', 'label' => __('Import', 'field-reference'), 'description' => __('Upload JSON to replace settings.', 'field-reference'), 'columns' => 6, 'args' => ['button_label' => __('Upload JSON', 'field-reference')]],
                    ],
                ],
            ],
        ],

        // ─── Role-Based Access ────────────────────────────
        //
        // Demonstrates the `access` key. Until at least one `access` key is
        // declared somewhere on the page, RBAC stays dormant and the page
        // behaves exactly like the rest of the demo (admin-only). The mere
        // presence of the keys below switches this entire page into RBAC
        // mode — drop the `access` lines and you're back to legacy mode.
        [
            'id'       => 'access',
            'title'    => __('Role-Based Access', 'field-reference'),
            'sections' => [
                [
                    'id'          => 'rbac_intro',
                    'title'       => __('How it works', 'field-reference'),
                    'description' => __('Each tab, section, and field accepts an optional `access` key. Values can be a role slug or a capability. Two verbs: `view` (can see) and `edit` (can write).', 'field-reference'),
                    'fields'      => [
                        [
                            'id'    => 'rbac_explainer',
                            'type'  => 'html',
                            'args'  => [
                                'content' => __('Sign in as an Editor to see how this page changes — fields you cannot edit render disabled, fields you cannot view disappear entirely, and Save/Reset only affects fields in your scope.', 'field-reference'),
                                'variant' => 'info',
                            ],
                        ],
                    ],
                ],

                // Editors can see + edit this section.
                [
                    'id'          => 'rbac_editor_zone',
                    'title'       => __('Editor zone', 'field-reference'),
                    'description' => __('Section visible and editable to anyone in the editor role or above.', 'field-reference'),
                    'access'      => 'editor',
                    'fields'      => [
                        [
                            'id'          => 'rbac_editor_note',
                            'type'        => 'textarea',
                            'label'       => __('Editorial note', 'field-reference'),
                            'description' => __('Editors can change this. Admins can change this. Subscribers see nothing.', 'field-reference'),
                            'args'        => ['rows' => 3],
                        ],
                    ],
                ],

                // Editors can VIEW but not EDIT — the field renders disabled.
                [
                    'id'     => 'rbac_view_only',
                    'title'  => __('View-only fields', 'field-reference'),
                    'fields' => [
                        [
                            'id'          => 'rbac_api_key',
                            'type'        => 'text',
                            'label'       => __('API key (view-only for editors)', 'field-reference'),
                            'description' => __('Editors can see the key for reference, but only admins can change it.', 'field-reference'),
                            'default'     => 'sk_demo_••••••••••••',
                            'access'      => [
                                'view' => 'editor',
                                'edit' => 'manage_options',
                            ],
                        ],
                    ],
                ],

                // Hidden from editors entirely — stripped from their config.
                [
                    'id'          => 'rbac_admin_zone',
                    'title'       => __('Admin-only zone', 'field-reference'),
                    'description' => __('Both this section and its fields require `manage_options`. Editors never see this section in the menu *or* the API.', 'field-reference'),
                    'access'      => ['view' => 'manage_options'],
                    'fields'      => [
                        [
                            'id'      => 'rbac_secret',
                            'type'    => 'password',
                            'label'   => __('Secret token', 'field-reference'),
                            'default' => '',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
