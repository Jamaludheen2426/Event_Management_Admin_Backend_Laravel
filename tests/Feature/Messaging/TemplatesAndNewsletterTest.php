<?php

declare(strict_types=1);

use App\Modules\Messaging\Models\EmailConfig;
use App\Modules\Messaging\Models\EmailTemplate;
use App\Modules\Messaging\Models\MailNotification;
use App\Modules\Messaging\Models\VendorNewsletterRecipient;

/*
| Settings → Email → Templates (/email-templates), a vendor's own e-mail
| categories and templates (/vendors/email-*), and the vendor's newsletter
| (/vendors/newsletter/*). v1 emailTemplate, vendorEmailCategory,
| vendorEmailTemplate and vendorNewsletter.
*/

beforeEach(function () {
    seedHouse();
    $this->admin = accessToken(adminUser());
});

it('keeps platform e-mail templates with their parts and variables', function () {
    $header = $this->withToken($this->admin)->postJson('/api/v1/email-templates', ['name' => 'Brand header', 'type' => 'header', 'body' => '<p>HEADER</p>'])
        ->assertCreated()->assertJsonPath('data.template.slug', 'brand-header')->json('data.template.id');
    $id = $this->withToken($this->admin)->postJson('/api/v1/email-templates', [
        'name' => 'Welcome', 'subject' => 'Hi {{user_name}}', 'body' => '<p>Welcome to {{app_name}}, {{user_name}}</p>', 'header_id' => $header,
    ])->assertCreated()->assertJsonPath('message', 'Email template created successfully')
        ->assertJsonPath('data.template.variables', ['user_name', 'app_name'])->assertJsonPath('data.template.header.name', 'Brand header')
        ->json('data.template.id');

    $this->withToken($this->admin)->postJson('/api/v1/email-templates', ['name' => 'Welcome'])
        ->assertStatus(409)->assertJsonPath('message', 'Email template with this slug already exists');

    $preview = $this->withToken($this->admin)->postJson("/api/v1/email-templates/{$id}/preview", ['variables' => ['user_name' => 'Asha']])
        ->assertOk()->assertJsonPath('data.subject', 'Hi Asha')->json('data.body');
    expect($preview)->toContain('HEADER')->toContain('Welcome to')->toContain('Asha')->toContain('<!DOCTYPE html>');

    // No provider yet: sending says so.
    $this->withToken($this->admin)->postJson("/api/v1/email-templates/{$id}/send", ['to' => 'a@ra.test'])
        ->assertStatus(400)->assertJsonPath('message', 'No email configuration found. Please configure an email provider.');
    EmailConfig::query()->create(['company_id' => 1, 'name' => 'SMTP', 'from_email' => 'hi@ra.test', 'is_default' => true]);
    $this->withToken($this->admin)->postJson("/api/v1/email-templates/{$id}/send", ['to' => 'a@ra.test', 'variables' => ['user_name' => 'Asha']])
        ->assertOk()->assertJsonPath('message', 'Email sent successfully')->assertJsonPath('data.subject', 'Hi Asha');

    $this->withToken($this->admin)->patchJson("/api/v1/email-templates/{$id}/toggle-active")->assertOk()->assertJsonPath('data.template.is_active', 0);
    $this->withToken($this->admin)->getJson('/api/v1/email-templates/variables')->assertOk()->assertJsonPath('data.variables.0.key', 'user_name');
    $this->withToken($this->admin)->getJson('/api/v1/email-templates')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($this->admin)->deleteJson("/api/v1/email-templates/{$id}")->assertOk();
    expect(EmailTemplate::query()->count())->toBe(1);
});

it('lets a vendor keep its own e-mail categories and templates', function () {
    $owner = vendorOwner();
    $other = vendorOwner(['company_name' => 'Other']);
    $token = accessToken($owner);

    $category = $this->withToken($token)->postJson('/api/v1/vendors/email-categories', ['name' => 'Offers'])
        ->assertCreated()->assertJsonPath('data.category.name', 'Offers')->json('data.category.id');
    $this->withToken($token)->postJson('/api/v1/vendors/email-categories', ['name' => 'Offers'])
        ->assertStatus(400)->assertJsonPath('message', 'Category "Offers" already exists');
    // Another vendor may use the same name, and cannot see this one.
    $this->withToken(accessToken($other))->postJson('/api/v1/vendors/email-categories', ['name' => 'Offers'])->assertCreated();
    $this->withToken(accessToken($other))->getJson("/api/v1/vendors/email-categories/{$category}")->assertNotFound();

    $template = $this->withToken($token)->postJson('/api/v1/vendors/email-templates', ['name' => 'Diwali', 'category_id' => $category, 'description' => '<p>Sale!</p>'])
        ->assertCreated()->assertJsonPath('data.template.category.name', 'Offers')->json('data.template.id');
    $this->withToken($token)->patchJson("/api/v1/vendors/email-templates/{$template}/status")->assertOk()->assertJsonPath('data.template.is_active', 0);
    $this->withToken($token)->getJson("/api/v1/vendors/email-templates?category_id={$category}")->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/vendors/email-categories')->assertOk()->assertJsonCount(1, 'data');
});

it('sends a newsletter as in-app mail to the chosen subscribers', function () {
    $owner = vendorOwner();
    $token = accessToken($owner);
    $asha = client(['vendor_id' => $owner->vendor_id, 'name' => 'Asha', 'email' => 'asha@ra.test', 'newsletter_opt_in' => true]);
    $ravi = client(['vendor_id' => $owner->vendor_id, 'name' => 'Ravi', 'email' => 'ravi@ra.test', 'mobile' => '9800000002', 'newsletter_opt_in' => false]);

    $this->withToken($token)->getJson('/api/v1/vendors/newsletter/subscribers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.client_type', 'subscribed');
    $this->withToken($token)->patchJson("/api/v1/vendors/newsletter/{$ravi->id}/client-type")->assertOk()->assertJsonPath('data.client_type', 'subscribed');

    $template = $this->withToken($token)->postJson('/api/v1/vendors/email-templates', ['name' => 'October news', 'description' => '<p>News</p>'])->json('data.template.id');
    $this->withToken($token)->postJson('/api/v1/vendors/newsletter/send', ['user_type' => 'Everyone', 'send_to' => 'subscribers', 'template_id' => $template])
        ->assertStatus(400)->assertJsonPath('message', 'Valid user type is required.');
    $sent = $this->withToken($token)->postJson('/api/v1/vendors/newsletter/send', ['userType' => 'Registered', 'sendTo' => 'subscribers', 'templateId' => $template])
        ->assertOk()->assertJsonPath('message', 'Newsletter sent to 2 recipients')->assertJsonPath('data.count', 2)->json('data');

    expect(MailNotification::query()->where('mail_id', $sent['mailId'])->count())->toBe(2)
        ->and(VendorNewsletterRecipient::query()->where('newsletter_id', $sent['newsletterId'])->pluck('name')->sort()->values()->all())->toBe(['Asha', 'Ravi']);
    $this->withToken($token)->getJson('/api/v1/vendors/newsletter/sends')
        ->assertOk()->assertJsonPath('data.0.success_count', 2)->assertJsonPath('data.0.unread_count', 2);

    // The log shows each client's subscription as it is now.
    $this->withToken($token)->patchJson('/api/v1/vendors/newsletter/bulk', ['from' => 'subscribed', 'to' => 'unsubscribed'])->assertOk()->assertJsonPath('data', 2);
    $this->withToken($token)->getJson("/api/v1/vendors/newsletter/sent-logs?newsletter_id={$sent['newsletterId']}")
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.client_type', 'unsubscribed');

    // The clients find it in their inbox.
    $this->flushHeaders()->withToken(accessToken($asha))->withHeader('x-portal-type', 'client')->getJson('/api/v1/mail')
        ->assertOk()->assertJsonPath('data.rows.0.mail.subject', 'October news');
});
