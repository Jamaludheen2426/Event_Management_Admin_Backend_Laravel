<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Approvals\ApprovalHandler;
use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Http\Resources\V1\ClientResource;
use App\Modules\Clients\Models\Client;
use Illuminate\Database\Eloquent\Model;

/**
 * Approvals for the admin client screens (website_clients / website_client).
 * A client created under approval exists at once, inactive, until approved.
 */
final class ClientApprovals implements ApprovalHandler
{
    public function __construct(private readonly ClientWriter $writer) {}

    public function snapshot(int $id): ?array
    {
        $client = $this->writer->adminQuery()->with(['vendor', 'favourites'])->find($id);

        return $client === null ? null : (new ClientResource($client))->resolve();
    }

    public function createPending(array $payload, Model $requester): int
    {
        return $this->writer->adminCreate([...$payload, 'is_active' => 0], (int) $requester->getKey())->id;
    }

    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model
    {
        $client = $resourceId === null ? null : $this->writer->adminQuery()->find($resourceId);
        if (! $client instanceof Client) {
            throw ApiException::notFound('Client not found');
        }

        return match ($action) {
            'create' => $this->writer->setActive($client, 1),
            'delete' => tap(null, fn () => $this->writer->delete($client)),
            default => $this->writer->adminUpdate($client, $payload),
        };
    }

    public function discard(int $resourceId, Model $reviewer): void
    {
        $client = $this->writer->adminQuery()->find($resourceId);
        if ($client !== null && $client->is_active === 0) {
            $client->forceDelete();
        }
    }
}
