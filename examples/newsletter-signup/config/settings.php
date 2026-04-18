<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

return [
    'title'    => __('Newsletter Signup', 'newsletter-signup'),
    'subtitle' => __('Collect email signups and send them to your provider.', 'newsletter-signup'),
    'tabs'     => [
        [
            'id'       => 'form',
            'title'    => __('Form', 'newsletter-signup'),
            'sections' => [
                [
                    'id'          => 'content',
                    'title'       => __('Content', 'newsletter-signup'),
                    'description' => __('What your visitors see when the form appears.', 'newsletter-signup'),
                    'fields'      => [
                        [
                            'id'       => 'heading',
                            'type'     => 'text',
                            'label'    => __('Heading', 'newsletter-signup'),
                            'default'  => __('Join the newsletter', 'newsletter-signup'),
                            'required' => true,
                            'columns'  => 6,
                        ],
                        [
                            'id'      => 'button_label',
                            'type'    => 'text',
                            'label'   => __('Button label', 'newsletter-signup'),
                            'default' => __('Subscribe', 'newsletter-signup'),
                            'columns' => 6,
                        ],
                        [
                            'id'          => 'description',
                            'type'        => 'textarea',
                            'label'       => __('Description', 'newsletter-signup'),
                            'description' => __('Shown below the heading.', 'newsletter-signup'),
                            'args'        => ['rows' => 3],
                        ],
                    ],
                ],
                [
                    'id'     => 'appearance',
                    'title'  => __('Appearance', 'newsletter-signup'),
                    'fields' => [
                        [
                            'id'      => 'accent_color',
                            'type'    => 'color',
                            'label'   => __('Button color', 'newsletter-signup'),
                            'default' => '#3858e9',
                            'columns' => 6,
                        ],
                        [
                            'id'      => 'show_on_mobile',
                            'type'    => 'toggle',
                            'label'   => __('Show on mobile', 'newsletter-signup'),
                            'default' => true,
                            'columns' => 6,
                        ],
                    ],
                ],
            ],
        ],
        [
            'id'       => 'provider',
            'title'    => __('Provider', 'newsletter-signup'),
            'sections' => [
                [
                    'id'          => 'connection',
                    'title'       => __('Connection', 'newsletter-signup'),
                    'description' => __('Send new signups to your email service provider.', 'newsletter-signup'),
                    'fields'      => [
                        [
                            'id'      => 'provider',
                            'type'    => 'select',
                            'label'   => __('Provider', 'newsletter-signup'),
                            'default' => 'none',
                            'columns' => 6,
                            'args'    => [
                                'options' => [
                                    'none'      => __('Store locally', 'newsletter-signup'),
                                    'mailchimp' => __('Mailchimp', 'newsletter-signup'),
                                    'convertkit' => __('ConvertKit', 'newsletter-signup'),
                                ],
                            ],
                        ],
                        [
                            'id'         => 'api_key',
                            'type'       => 'password',
                            'label'      => __('API key', 'newsletter-signup'),
                            'columns'    => 6,
                            'conditions' => ['all' => [['field' => 'provider', 'operator' => 'not_equals', 'value' => 'none']]],
                            'args'       => ['placeholder' => 'sk_...'],
                        ],
                        [
                            'id'         => 'list_id',
                            'type'       => 'text',
                            'label'      => __('List / audience ID', 'newsletter-signup'),
                            'conditions' => ['all' => [['field' => 'provider', 'operator' => 'not_equals', 'value' => 'none']]],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
