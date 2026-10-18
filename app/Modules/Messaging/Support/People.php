<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\CompanyScope;
use App\Modules\Clients\Models\Client;
use App\Modules\Identity\Models\User;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * The people mail and chat can reach, and who may write to whom (v1
 * mailContact + chat getContacts / assertTargetAllowed):
 *
 *  - an admin writes to the company's active vendors and clients (mail: and admins);
 *  - a vendor writes to the company's admins and its own clients;
 *  - a client writes to their own vendor only.
 */
final class People
{
    /**
     * @return array{admins: list<array<string, mixed>>, vendors: list<array<string, mixed>>, clients: list<array<string, mixed>>}
     */
    public function contacts(Actor $actor, bool $withAvatars): array
    {
        $result = ['admins' => [], 'vendors' => [], 'clients' => []];
        $avatar = static fn (mixed $url): ?string => is_string($url) && $url !== '' && ! str_starts_with($url, 'data:') && strlen($url) <= 500 ? $url : null;

        if ($actor->type === 'admin') {
            $result['vendors'] = $this->vendors($actor->companyId)->get(['id', 'company_name', 'email', 'logo'])
                ->map(static fn (Vendor $v): array => ['id' => $v->id, 'type' => 'vendor', 'name' => $v->company_name, 'email' => $v->getAttribute('email'), ...($withAvatars ? ['avatar' => $avatar($v->getAttribute('logo'))] : [])])->all();
            $result['clients'] = $this->clients()->whereIn('vendor_id', $this->vendors($actor->companyId)->select('id'))->get(['id', 'name', 'email', 'avatar_url', 'vendor_id'])
                ->map(fn (Client $c): array => $this->client($c, $withAvatars, $avatar))->all();
        }
        if ($actor->type === 'vendor') {
            $result['admins'] = $this->admins($actor->companyId)->get(['id', 'full_name', 'email', 'avatar'])
                ->map(static fn (User $u): array => ['id' => $u->id, 'type' => 'admin', 'name' => $u->full_name, 'email' => $u->email, ...($withAvatars ? ['avatar' => $avatar($u->getAttribute('avatar'))] : [])])->all();
            $result['clients'] = $this->clients()->where('vendor_id', $actor->id)->get(['id', 'name', 'email', 'avatar_url', 'vendor_id'])
                ->map(fn (Client $c): array => $this->client($c, $withAvatars, $avatar))->all();
        }
        if ($actor->type === 'client' && $actor->vendorId !== null) {
            $vendor = $this->vendors($actor->companyId)->find($actor->vendorId, ['id', 'company_name', 'email', 'logo']);
            if ($vendor !== null) {
                $result['vendors'] = [['id' => $vendor->id, 'type' => 'vendor', 'name' => $vendor->company_name, 'email' => $vendor->getAttribute('email'), ...($withAvatars ? ['avatar' => $avatar($vendor->getAttribute('logo'))] : [])]];
            }
        }

        return $result;
    }

    /** Throws unless $actor may write to (type, id). */
    public function assertCanReach(Actor $actor, string $type, int $id, bool $adminsToAdmins): void
    {
        $allowed = match ($actor->type) {
            'admin' => match ($type) {
                'vendor' => $this->vendors($actor->companyId)->whereKey($id)->exists(),
                'client' => $this->clients()->whereKey($id)->whereIn('vendor_id', $this->vendors($actor->companyId)->select('id'))->exists(),
                'admin' => $adminsToAdmins && $this->admins($actor->companyId)->whereKey($id)->exists(),
                default => false,
            },
            'vendor' => match ($type) {
                'admin' => $this->admins($actor->companyId)->whereKey($id)->exists(),
                'client' => $this->clients()->whereKey($id)->where('vendor_id', $actor->id)->exists(),
                default => false,
            },
            default => $type === 'vendor' && $id === $actor->vendorId && $this->vendors($actor->companyId)->whereKey($id)->exists(),
        };
        if (! $allowed) {
            // $adminsToAdmins is the mail rule set; mail answered one message for every refusal.
            throw ApiException::forbidden($adminsToAdmins ? 'Recipient is not allowed for this account.' : match ($actor->type) {
                'client' => 'Clients can only chat with their vendor.',
                'vendor' => 'Vendors can only chat with admins and their own clients.',
                default => 'Recipient is not allowed for this account.',
            });
        }
    }

    /**
     * Name, e-mail, avatar and vendor of each person, in one query per type.
     *
     * @param  list<array{0: string, 1: int}>  $people  [type, id] pairs
     * @return array<string, array{name: string, email: string|null, avatar: string|null, vendor_id: int|null}> keyed "type:id"
     */
    public function profiles(array $people): array
    {
        $ids = ['admin' => [], 'vendor' => [], 'client' => []];
        foreach ($people as [$type, $id]) {
            $ids[$type][] = $id;
        }
        $out = [];
        if ($ids['admin'] !== []) {
            foreach (User::query()->withoutGlobalScope(CompanyScope::class)->whereKey($ids['admin'])->get(['id', 'full_name', 'email', 'avatar']) as $u) {
                $out["admin:{$u->id}"] = ['name' => $u->full_name ?: ($u->email ?: "Admin #{$u->id}"), 'email' => $u->email, 'avatar' => $u->getAttribute('avatar'), 'vendor_id' => null];
            }
        }
        if ($ids['vendor'] !== []) {
            foreach (Vendor::query()->withoutGlobalScopes()->whereKey($ids['vendor'])->get(['id', 'company_name', 'email', 'logo']) as $v) {
                $out["vendor:{$v->id}"] = ['name' => $v->company_name ?: ((string) $v->getAttribute('email') ?: "Vendor #{$v->id}"), 'email' => $v->getAttribute('email'), 'avatar' => $v->getAttribute('logo'), 'vendor_id' => $v->id];
            }
        }
        if ($ids['client'] !== []) {
            foreach (Client::query()->withoutGlobalScopes()->whereKey($ids['client'])->get(['id', 'name', 'email', 'avatar_url', 'vendor_id']) as $c) {
                $out["client:{$c->id}"] = ['name' => $c->name ?: ((string) $c->email ?: "Client #{$c->id}"), 'email' => $c->email, 'avatar' => $c->avatar_url, 'vendor_id' => $c->vendor_id];
            }
        }

        return $out;
    }

    /** @return Builder<Vendor> */
    private function vendors(int $companyId): Builder
    {
        return Vendor::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)->where('status', VendorStatus::Active->value);
    }

    /** @return Builder<Client> */
    private function clients(): Builder
    {
        return Client::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('is_active', 1);
    }

    /** @return Builder<User> */
    private function admins(int $companyId): Builder
    {
        return User::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->where('is_active', 1);
    }

    /**
     * @param  \Closure(mixed): ?string  $avatar
     * @return array<string, mixed>
     */
    private function client(Client $c, bool $withAvatars, \Closure $avatar): array
    {
        return ['id' => $c->id, 'type' => 'client', 'name' => $c->name, 'email' => $c->email,
            ...($withAvatars ? ['avatar' => $avatar($c->avatar_url), 'vendor_id' => $c->vendor_id] : [])];
    }
}
