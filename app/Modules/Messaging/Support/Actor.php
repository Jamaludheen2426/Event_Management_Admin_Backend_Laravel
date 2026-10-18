<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Identity\Models\User;
use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Database\Eloquent\Model;

/**
 * Who is mailing or chatting: an admin (users.id), a vendor (the business,
 * vendors.id — whoever of its logins is signed in) or a client (clients.id).
 */
final readonly class Actor
{
    public const TYPES = ['admin', 'vendor', 'client'];

    public function __construct(
        public string $type,
        public int $id,
        public ?int $vendorId,
        public int $companyId,
    ) {}

    public static function of(?Model $user): self
    {
        return match (true) {
            $user instanceof User => new self('admin', $user->id, null, $user->company_id),
            $user instanceof VendorUser => new self('vendor', $user->vendor_id, $user->vendor_id, self::companyOf($user->vendor_id)),
            $user instanceof Client => new self('client', $user->id, $user->vendor_id, self::companyOf($user->vendor_id)),
            default => throw ApiException::unauthorized('Authentication required.'),
        };
    }

    /** @return array{type: string, id: int, vendorId: int|null, companyId: int} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'vendorId' => $this->vendorId, 'companyId' => $this->companyId];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, self::TYPES, true) || ! is_numeric($data['id'] ?? null)) {
            throw ApiException::unauthorized('Invalid chat actor.');
        }

        return new self($type, (int) $data['id'], is_numeric($data['vendorId'] ?? null) ? (int) $data['vendorId'] : null, (int) ($data['companyId'] ?? 0));
    }

    public function is(string $type, int $id): bool
    {
        return $this->type === $type && $this->id === $id;
    }

    private static function companyOf(int $vendorId): int
    {
        return (int) Vendor::query()->withoutGlobalScopes()->whereKey($vendorId)->value('company_id');
    }
}
