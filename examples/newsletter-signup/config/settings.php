<?php

declare(strict_types=1);

use Wireframe\Builder\Fields\ColorField;
use Wireframe\Builder\Fields\PasswordField;
use Wireframe\Builder\Fields\SelectField;
use Wireframe\Builder\Fields\TextareaField;
use Wireframe\Builder\Fields\TextField;
use Wireframe\Builder\Fields\ToggleField;
use Wireframe\Builder\Page;
use Wireframe\Builder\Section;
use Wireframe\Builder\Tab;

if (! defined('ABSPATH')) {
    exit;
}

// Built with the fluent builder; the other examples use plain arrays. Both compile to the same shape.
$hasProvider = ['all' => [['field' => 'provider', 'operator' => 'not_equals', 'value' => 'none']]];

return Page::make()
    ->title(__('Newsletter Signup', 'newsletter-signup'))
    ->subtitle(__('Collect email signups and send them to your provider.', 'newsletter-signup'))
    // `edit_pages` is held by editors and admins; the `'editor'` role slug
    // would exclude admins (no role hierarchy in WordPress).
    ->tab('form', __('Form', 'newsletter-signup'), fn (Tab $tab) => $tab
        ->access('edit_pages')
        ->section('content', __('Content', 'newsletter-signup'), fn (Section $section) => $section
            ->description(__('What your visitors see when the form appears.', 'newsletter-signup'))
            // Admin-only: editors fail the check and the section is stripped from the localized config.
            ->access('manage_options')
            ->fields(
                TextField::make('heading')
                    ->label(__('Heading', 'newsletter-signup'))
                    ->default(__('Join the newsletter', 'newsletter-signup'))
                    ->required()
                    ->columns(6),
                TextField::make('button_label')
                    ->label(__('Button label', 'newsletter-signup'))
                    ->default(__('Subscribe', 'newsletter-signup'))
                    ->columns(6),
                TextareaField::make('description')
                    ->label(__('Description', 'newsletter-signup'))
                    ->description(__('Shown below the heading.', 'newsletter-signup'))
                    ->rows(3),
            ))
        ->section('appearance', __('Appearance', 'newsletter-signup'), fn (Section $section) => $section
            ->fields(
                ColorField::make('accent_color')
                    ->label(__('Button color', 'newsletter-signup'))
                    ->default('#3858e9')
                    ->columns(6),
                ToggleField::make('show_on_mobile')
                    ->label(__('Show on mobile', 'newsletter-signup'))
                    ->default(true)
                    ->columns(6),
            )))
    // Admin-only: editors never see this tab and the REST gate rejects writes to its fields.
    ->tab('provider', __('Provider', 'newsletter-signup'), fn (Tab $tab) => $tab
        ->access('manage_options')
        ->section('connection', __('Connection', 'newsletter-signup'), fn (Section $section) => $section
            ->description(__('Send new signups to your email service provider.', 'newsletter-signup'))
            ->fields(
                SelectField::make('provider')
                    ->label(__('Provider', 'newsletter-signup'))
                    ->default('none')
                    ->columns(6)
                    ->options([
                        'none'       => __('Store locally', 'newsletter-signup'),
                        'mailchimp'  => __('Mailchimp', 'newsletter-signup'),
                        'convertkit' => __('ConvertKit', 'newsletter-signup'),
                    ]),
                PasswordField::make('api_key')
                    ->label(__('API key', 'newsletter-signup'))
                    ->columns(6)
                    ->conditions($hasProvider)
                    ->placeholder('sk_...'),
                TextField::make('list_id')
                    ->label(__('List / audience ID', 'newsletter-signup'))
                    ->conditions($hasProvider),
            )));
