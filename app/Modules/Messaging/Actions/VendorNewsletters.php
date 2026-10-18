<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Clients\Models\Client;
use App\Modules\Messaging\Models\Mail;
use App\Modules\Messaging\Models\MailNotification;
use App\Modules\Messaging\Models\MailRecipient;
use App\Modules\Messaging\Models\VendorEmailTemplate;
use App\Modules\Messaging\Models\VendorNewsletter;
use App\Modules\Messaging\Models\VendorNewsletterRecipient;
use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The vendor's newsletter (v1 vendorNewsletter): who is subscribed, switching
 * clients in and out, and sending one of the vendor's e-mail templates.
 *
 * A newsletter is delivered as one in-app mail to every chosen client, with
 * a bell notification each (v1). Nothing is e-mailed.
 */
final class VendorNewsletters
{
    /** @return list<array<string, mixed>> */
    public function clients(bool $subscribed): array
    {
        return Client::query()->where('newsletter_opt_in', $subscribed)->with('planType:id,name')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (Client $c): array => $this->client($c))->all();
    }

    /** @return array<string, mixed> */
    public function toggle(int $clientId): array
    {
        $client = Client::query()->with('planType:id,name')->find($clientId) ?? throw ApiException::badRequest('Client not found');
        $client->forceFill(['newsletter_opt_in' => ! $client->newsletter_opt_in])->save();

        return $this->client($client);
    }

    /** Everyone in one list moves to the other ({from, to}). */
    public function moveAll(mixed $from, mixed $to): int
    {
        return Client::query()->where('newsletter_opt_in', $this->subscribed($from))->update(['newsletter_opt_in' => $this->subscribed($to)]);
    }

    /** @param list<int> $ids */
    public function setMany(array $ids, mixed $clientType): int
    {
        return Client::query()->whereKey($ids)->update(['newsletter_opt_in' => $this->subscribed($clientType)]);
    }

    /** @return list<array<string, mixed>> */
    public function sends(): array
    {
        $counts = static fn (string $condition): \Illuminate\Database\Query\Builder => DB::table('vendor_newsletter_recipients')
            ->selectRaw('COUNT(*)')->whereColumn('newsletter_id', 'vendor_newsletters.id')->whereRaw($condition);

        return VendorNewsletter::query()
            ->select(['id', 'template_id', 'template_name', 'user_type', 'plans', 'total_sent', 'created_at'])
            ->selectSub($counts("status = 'sent'"), 'success_count')
            ->selectSub($counts("status = 'failed'"), 'failed_count')
            ->selectSub($counts('opened_at IS NOT NULL'), 'read_count')
            ->selectSub($counts("opened_at IS NULL AND status = 'sent'"), 'unread_count')
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(static fn (VendorNewsletter $n): array => [
                'id' => $n->id, 'template_id' => $n->template_id, 'template_name' => $n->template_name, 'user_type' => $n->user_type,
                'plans' => $n->plans, 'total_sent' => $n->total_sent, 'createdAt' => Dates::at($n->getAttribute('created_at')),
                'success_count' => (int) $n->getAttribute('success_count'), 'failed_count' => (int) $n->getAttribute('failed_count'),
                'read_count' => (int) $n->getAttribute('read_count'), 'unread_count' => (int) $n->getAttribute('unread_count'),
            ])->all();
    }

    /**
     * The recipient log, each with the client's subscription status now.
     *
     * @return list<array<string, mixed>>
     */
    public function log(mixed $newsletterId): array
    {
        $vendors = VendorNewsletter::query()->when(is_numeric($newsletterId), static fn (Builder $q) => $q->whereKey((int) $newsletterId))->pluck('vendor_id', 'id');
        $rows = VendorNewsletterRecipient::query()->whereIn('newsletter_id', $vendors->keys())->orderByDesc('created_at')->orderByDesc('id')->get();
        $optIn = Client::query()->whereKey($rows->pluck('client_id')->filter()->all())->pluck('newsletter_opt_in', 'id');

        return $rows->map(static fn (VendorNewsletterRecipient $r): array => [
            'id' => $r->id, 'vendor_id' => (int) $vendors[$r->newsletter_id], 'campaign_id' => $r->newsletter_id,
            'client_id' => $r->client_id, 'email' => $r->email, 'name' => $r->name, 'membership' => $r->membership, 'template' => $r->template,
            'status' => $r->status, 'opened_at' => Dates::at($r->opened_at), 'clicked_at' => Dates::at($r->getAttribute('clicked_at')),
            'createdAt' => Dates::at($r->created_at), 'updatedAt' => Dates::at($r->updated_at),
            'client_type' => $r->client_id === null || ! $optIn->has($r->client_id) ? null : ($optIn[$r->client_id] ? 'subscribed' : 'unsubscribed'),
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $input  user_type Guest|Registered, send_to subscribers|unsubscribers, template_id, category_id, plans
     * @return array{newsletterId: int, mailId: int|null, count: int}
     */
    public function send(VendorUser $sender, array $input): array
    {
        $userType = $input['user_type'] ?? null;
        $sendTo = $input['send_to'] ?? null;
        if (! in_array($userType, ['Guest', 'Registered'], true)) {
            throw ApiException::badRequest('Valid user type is required.');
        }
        if (! in_array($sendTo, ['subscribers', 'unsubscribers'], true)) {
            throw ApiException::badRequest('Valid recipient group is required.');
        }
        $template = VendorEmailTemplate::query()->where('is_active', 1)->find(is_numeric($input['template_id'] ?? null) ? (int) $input['template_id'] : 0)
            ?? throw ApiException::notFound('Template not found');
        $plans = array_values(array_filter(array_map('strval', is_array($input['plans'] ?? null) ? $input['plans'] : [])));

        $clients = Client::query()->where('newsletter_opt_in', $sendTo === 'subscribers')
            ->where('registration_type', $userType === 'Guest' ? 'guest' : 'client')
            ->when($userType === 'Registered' && $plans !== [], static fn (Builder $q) => $q->whereHas('planType', static fn (Builder $p) => $p->whereIn('name', $plans)))
            ->with('planType:id,name')->get();

        $vendorId = $sender->vendor_id;
        $companyId = (int) Vendor::query()->withoutGlobalScopes()->whereKey($vendorId)->value('company_id');

        return DB::transaction(function () use ($template, $clients, $input, $userType, $plans, $vendorId, $companyId): array {
            $mail = null;
            if ($clients->isNotEmpty()) {
                $mail = Mail::query()->create([
                    'company_id' => $companyId, 'sender_type' => 'vendor', 'sender_id' => $vendorId, 'subject' => $template->name,
                    'body' => $template->description ?: $template->name, 'status' => 'sent', 'sent_at' => now(),
                ]);
                $now = now();
                MailRecipient::query()->insert($clients->map(static fn (Client $c): array => [
                    'mail_id' => $mail->id, 'recipient_type' => 'client', 'recipient_id' => $c->id, 'role' => 'to', 'is_read' => false, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
                MailNotification::query()->insert($clients->map(static fn (Client $c): array => [
                    'mail_id' => $mail->id, 'recipient_type' => 'client', 'recipient_id' => $c->id, 'is_read' => false, 'created_at' => $now,
                ])->all());
            }
            $newsletter = VendorNewsletter::query()->create([
                'template_id' => $template->id, 'template_name' => $template->name,
                'category_id' => is_numeric($input['category_id'] ?? null) ? (int) $input['category_id'] : $template->category_id,
                'user_type' => $userType, 'plans' => $plans === [] ? null : $plans, 'total_sent' => $clients->count(), 'mail_id' => $mail?->id,
            ]);
            if ($clients->isNotEmpty()) {
                $now = now();
                VendorNewsletterRecipient::query()->insert($clients->map(static fn (Client $c): array => [
                    'newsletter_id' => $newsletter->id, 'client_id' => $c->id, 'email' => (string) $c->email, 'name' => $c->name ?: (string) $c->email,
                    'membership' => $c->registration_type === 'guest' ? 'Guest' : ($c->planType->name ?? 'Standard'),
                    'template' => $template->name, 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            }

            return ['newsletterId' => $newsletter->id, 'mailId' => $mail?->id, 'count' => $clients->count()];
        });
    }

    /** @return array<string, mixed> */
    private function client(Client $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'plan' => $c->planType?->name,
            'client_type' => $c->newsletter_opt_in ? 'subscribed' : 'unsubscribed',
            'registration_type' => $c->registration_type, 'is_active' => $c->is_active,
        ];
    }

    private function subscribed(mixed $clientType): bool
    {
        return match ($clientType) {
            'subscribed' => true,
            'unsubscribed' => false,
            default => throw ApiException::badRequest('client_type must be subscribed or unsubscribed'),
        };
    }
}
