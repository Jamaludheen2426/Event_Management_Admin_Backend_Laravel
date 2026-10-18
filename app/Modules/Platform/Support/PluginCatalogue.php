<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * The integrations an admin can switch on (v1 PREDEFINED_PLUGINS). Each
 * company has its own on/off row per plugin, created on first read.
 */
final class PluginCatalogue
{
    public const CATEGORY_ORDER = ['content', 'authentication', 'analytics', 'storage', 'payment', 'maps', 'security', 'communication', 'general'];

    /** @var list<array{slug: string, name: string, description: string, category: string, icon: string, config_group: string|null, config_route: string|null}> */
    public const PLUGINS = [
        ['slug' => 'website-management', 'name' => 'Website Management', 'description' => 'Enable vendor website pages, header, footer, sliders, and public website builder access.', 'category' => 'content', 'icon' => 'globe', 'config_group' => null, 'config_route' => null],
        ['slug' => 'faq', 'name' => 'FAQ', 'description' => 'Create frequently asked questions organized by category.', 'category' => 'content', 'icon' => 'help-circle', 'config_group' => null, 'config_route' => null],
        ['slug' => 'locations', 'name' => 'Locations', 'description' => 'Manage countries, states, districts, and cities.', 'category' => 'general', 'icon' => 'map-pin', 'config_group' => null, 'config_route' => null],
        ['slug' => 'google-oauth', 'name' => 'Google OAuth', 'description' => 'Allow users to sign in with their Google account.', 'category' => 'authentication', 'icon' => 'google', 'config_group' => 'social_login', 'config_route' => '/admin/settings/social-login'],
        ['slug' => 'facebook-oauth', 'name' => 'Facebook OAuth', 'description' => 'Allow users to sign in with their Facebook account.', 'category' => 'authentication', 'icon' => 'facebook', 'config_group' => 'social_login', 'config_route' => '/admin/settings/social-login'],
        ['slug' => 'google-tag-manager', 'name' => 'Google Tag Manager', 'description' => 'Manage and deploy marketing tags without editing code.', 'category' => 'analytics', 'icon' => 'tag', 'config_group' => 'analytics', 'config_route' => '/admin/settings/website-tracking'],
        ['slug' => 'google-analytics', 'name' => 'Google Analytics 4', 'description' => 'Track website traffic and user engagement with GA4.', 'category' => 'analytics', 'icon' => 'bar-chart', 'config_group' => 'analytics', 'config_route' => '/admin/settings/website-tracking'],
        ['slug' => 'amazon-s3', 'name' => 'Amazon S3', 'description' => 'Store and serve uploaded files via Amazon S3.', 'category' => 'storage', 'icon' => 'cloud', 'config_group' => 'media', 'config_route' => '/admin/settings/media'],
        ['slug' => 'cloudflare-r2', 'name' => 'Cloudflare R2', 'description' => 'Zero-egress object storage compatible with S3 API.', 'category' => 'storage', 'icon' => 'cloud', 'config_group' => 'media', 'config_route' => '/admin/settings/media'],
        ['slug' => 'digitalocean-spaces', 'name' => 'DigitalOcean Spaces', 'description' => 'Scalable object storage from DigitalOcean.', 'category' => 'storage', 'icon' => 'cloud', 'config_group' => 'media', 'config_route' => '/admin/settings/media'],
        ['slug' => 'wasabi', 'name' => 'Wasabi Storage', 'description' => 'Hot cloud storage - fast, low-cost, reliable.', 'category' => 'storage', 'icon' => 'cloud', 'config_group' => 'media', 'config_route' => '/admin/settings/media'],
        ['slug' => 'google-maps', 'name' => 'Google Maps', 'description' => 'Embed maps and location services powered by Google.', 'category' => 'maps', 'icon' => 'map-pin', 'config_group' => 'google_maps', 'config_route' => '/admin/plugins/google-maps/config'],
        ['slug' => 'stripe', 'name' => 'Stripe Payments', 'description' => 'Accept online payments with cards, wallets, and more via Stripe.', 'category' => 'payment', 'icon' => 'credit-card', 'config_group' => 'stripe', 'config_route' => '/admin/payments/stripe'],
        ['slug' => 'paypal', 'name' => 'PayPal', 'description' => 'Fast and trusted global payments via PayPal.', 'category' => 'payment', 'icon' => 'credit-card', 'config_group' => 'paypal', 'config_route' => '/admin/payments/paypal'],
        ['slug' => 'razorpay', 'name' => 'Razorpay', 'description' => 'Accept payments via cards, UPI, wallets & more (India-focused).', 'category' => 'payment', 'icon' => 'credit-card', 'config_group' => 'razorpay', 'config_route' => '/admin/payments/razorpay'],
        ['slug' => 'paystack', 'name' => 'Paystack', 'description' => 'Simple and reliable payments across Africa.', 'category' => 'payment', 'icon' => 'credit-card', 'config_group' => 'paystack', 'config_route' => '/admin/payments/paystack'],
        ['slug' => 'mollie', 'name' => 'Mollie', 'description' => 'Effortless European online payments.', 'category' => 'payment', 'icon' => 'credit-card', 'config_group' => 'mollie', 'config_route' => '/admin/payments/mollie'],
        ['slug' => 'flutterwave', 'name' => 'Flutterwave', 'description' => 'Pan-African payment infrastructure.', 'category' => 'payment', 'icon' => 'credit-card', 'config_group' => 'flutterwave', 'config_route' => '/admin/payments/flutterwave'],
        ['slug' => 'recaptcha', 'name' => 'Google reCAPTCHA', 'description' => 'Protect your forms from bots with Google reCAPTCHA.', 'category' => 'security', 'icon' => 'shield', 'config_group' => 'recaptcha', 'config_route' => '/admin/plugins/recaptcha/config'],
        ['slug' => 'twilio', 'name' => 'Twilio SMS', 'description' => 'Send SMS notifications and OTPs via Twilio.', 'category' => 'communication', 'icon' => 'message-square', 'config_group' => 'twilio', 'config_route' => '/admin/plugins/twilio/config'],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::PLUGINS, 'slug');
    }
}
