<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Core\Tenancy\VendorScope;
use App\Modules\Clients\Enums\ClientSource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\NewsletterSubscriber;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A vendor's newsletter list (v1 vendorSubscriber.service) and the public
 * "subscribe" form on the vendor's site.
 */
final class NewsletterSubscribers
{
    /** @param array<string, mixed> $input */
    public function create(array $input): NewsletterSubscriber
    {
        $email = $this->email($input['email'] ?? null);
        if (NewsletterSubscriber::query()->where('email', $email)->exists()) {
            throw ApiException::conflict('This email is already subscribed.');
        }

        return NewsletterSubscriber::query()->create([
            'email' => $email,
            'is_active' => array_key_exists('is_active', $input) ? Flag::bit($input['is_active']) === 1 : true,
        ]);
    }

    public function find(int $id): NewsletterSubscriber
    {
        return NewsletterSubscriber::query()->find($id) ?? throw ApiException::notFound('Subscriber not found');
    }

    /** @param array<string, mixed> $input */
    public function update(NewsletterSubscriber $subscriber, array $input): NewsletterSubscriber
    {
        if (array_key_exists('email', $input)) {
            $email = $this->email($input['email']);
            if ($email !== $subscriber->email && NewsletterSubscriber::query()->where('email', $email)->whereKeyNot($subscriber->id)->exists()) {
                throw ApiException::conflict('This email is already subscribed.');
            }
            $subscriber->email = $email;
        }
        if (array_key_exists('is_active', $input)) {
            $subscriber->is_active = Flag::bit($input['is_active']) === 1;
        }
        $subscriber->save();

        return $subscriber;
    }

    /**
     * POST /public/vendors/{slug}/newsletter-subscribe: the e-mail joins the
     * list, and the vendor's client with that e-mail is marked subscribed (or
     * a guest client without a login is created for it, as v1 did).
     *
     * @param  array<string, mixed>  $input  email, name, mobile
     * @return array{client: Client, created: bool}
     */
    public function subscribeFromSite(Vendor $vendor, array $input): array
    {
        $email = $this->email($input['email'] ?? null);

        return DB::transaction(function () use ($vendor, $email, $input): array {
            $client = Client::query()->withoutGlobalScope(VendorScope::class)->where('vendor_id', $vendor->id)->where('email', $email)->first();
            $created = $client === null;
            if ($client === null) {
                $client = Client::query()->create([
                    'vendor_id' => $vendor->id,
                    'source' => ClientSource::Vendor,
                    'code' => 'CLI-'.mb_strtoupper(Str::random(10)),
                    'name' => is_string($input['name'] ?? null) && trim($input['name']) !== '' ? trim($input['name']) : (Str::before($email, '@') ?: 'Newsletter Subscriber'),
                    'email' => $email,
                    'mobile' => null,
                    'registration_type' => 'guest',
                    'newsletter_opt_in' => true,
                    'login_access' => false,
                ]);
            } else {
                $client->forceFill(['newsletter_opt_in' => true])->save();
            }
            $this->addToList($vendor->id, $email);

            return ['client' => $client, 'created' => $created];
        });
    }

    /** Ensure the e-mail is on the vendor's list and active. */
    public function addToList(int $vendorId, string $email): void
    {
        $row = NewsletterSubscriber::query()->withoutGlobalScope(VendorScope::class)->where('vendor_id', $vendorId)->where('email', $email)->first();
        $row !== null
            ? $row->forceFill(['is_active' => true])->save()
            : NewsletterSubscriber::query()->create(['vendor_id' => $vendorId, 'email' => $email, 'is_active' => true]);
    }

    private function email(mixed $value): string
    {
        $email = mb_strtolower(trim(is_string($value) ? $value : ''));

        return $email !== '' ? $email : throw ApiException::badRequest('Email is required');
    }
}
