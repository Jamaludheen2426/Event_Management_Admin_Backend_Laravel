<?php

declare(strict_types=1);

use App\Core\Http\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;

const ISO_MILLIS = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

it('answers success in the v1 envelope', function () {
    $body = ApiResponse::success(['eventCategory' => ['id' => 3]], 'Event category created successfully', 201)->getData(true);

    expect($body)->toHaveKeys(['success', 'message', 'data', 'timestamp'])
        ->and($body['success'])->toBeTrue()
        ->and($body['message'])->toBe('Event category created successfully')
        ->and($body['data'])->toBe(['eventCategory' => ['id' => 3]])
        ->and($body['timestamp'])->toMatch(ISO_MILLIS);
});

it('uses Success and null data by default, like v1', function () {
    $response = ApiResponse::success();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toMatchArray(['success' => true, 'message' => 'Success', 'data' => null]);
});

it('answers errors without a data key and with errors only when given', function () {
    $plain = ApiResponse::error('Invalid OTP', 400)->getData(true);
    $detailed = ApiResponse::error('Validation error', 400, ['email' => ['Required']])->getData(true);

    expect($plain)->toMatchArray(['success' => false, 'message' => 'Invalid OTP'])
        ->and($plain)->not->toHaveKeys(['data', 'errors'])
        ->and($detailed['errors'])->toBe(['email' => ['Required']]);
});

it('builds the v1 pagination block', function () {
    $page = new LengthAwarePaginator(items: [['id' => 1], ['id' => 2]], total: 25, perPage: 10, currentPage: 2);

    $body = ApiResponse::paginated($page)->getData(true);

    expect($body['data'])->toHaveCount(2)
        ->and($body['pagination'])->toBe([
            'page' => 2,
            'limit' => 10,
            'totalItems' => 25,
            'totalPages' => 3,
            'hasNextPage' => true,
            'hasPrevPage' => true,
        ]);
});

it('reports zero pages for an empty list, as v1 did', function () {
    $page = new LengthAwarePaginator(items: [], total: 0, perPage: 10, currentPage: 1);

    expect(ApiResponse::paginated($page)->getData(true)['pagination'])
        ->toMatchArray(['totalItems' => 0, 'totalPages' => 0, 'hasNextPage' => false, 'hasPrevPage' => false]);
});

it('answers pending approval with 202 and the exact v1 keys', function () {
    $response = ApiResponse::pendingApproval(17, 'create');

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getData(true))->toBe([
            'success' => true,
            'status' => 'pending_approval',
            'message' => 'Your request has been sent for approval',
            'approval_id' => 17,
            'action' => 'create',
        ]);
});

it('does not escape slashes or unicode, like JSON.stringify', function () {
    $raw = ApiResponse::success(['url' => 'https://x.test/a', 'name' => 'தமிழ்'])->getContent();

    expect($raw)->toContain('https://x.test/a')->toContain('தமிழ்');
});
