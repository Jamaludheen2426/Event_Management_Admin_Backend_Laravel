<?php

declare(strict_types=1);

use App\Core\Approvals\ApprovalRequest;
use App\Core\Approvals\ApprovalStatus;
use App\Core\Contracts\MachineTranslator;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Actions\Roles\ProvisionCompanyRoles;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\Language;

/*
| The approval workflow: a role flagged "requires approval" parks the change,
| a level-100 reviewer approves (the change runs as the requester) or rejects.
| v1's approvals screen reads non-envelope shapes, kept here.
*/

beforeEach(function () {
    seedHouse();
});

function requester(array $permissions): User
{
    return giveRole(adminUser(role: null), customRole($permissions, level: 50));
}

it('parks a create that needs approval, shows it greyed out, and runs it on approval', function () {
    $roles = app(ProvisionCompanyRoles::class)->handle(1);
    $clerk = requester(['employees.view' => false, 'employees.create' => true]);
    $boss = adminUser();

    $response = $this->withToken(accessToken($clerk))->postJson('/api/v1/users', [
        'full_name' => 'Waiting', 'email' => 'wait@example.com', 'password' => 'Strong#Pass1', 'role_id' => $roles['admin']->id,
    ])->assertStatus(202)
        ->assertExactJson([
            'success' => true,
            'status' => 'pending_approval',
            'message' => 'Your request has been sent for approval',
            'approval_id' => ApprovalRequest::query()->value('id'),
            'action' => 'create',
        ]);

    $this->withToken(accessToken($boss))->getJson('/api/v1/users?search=wait@')
        ->assertJsonPath('data.0.is_active', 2);
    $this->postJson('/api/v1/auth/login', ['email' => 'wait@example.com', 'password' => 'Strong#Pass1'])->assertStatus(403);

    $this->withToken(accessToken($boss))->getJson('/api/v1/approvals/pending')->assertExactJson(['success' => true, 'count' => 1]);

    $this->withToken(accessToken($boss))->patchJson('/api/v1/approvals/'.$response->json('approval_id').'/approve', ['reviewNotes' => 'ok'])
        ->assertOk()
        ->assertJsonPath('message', 'Request approved and executed successfully')
        ->assertJsonPath('data.is_active', 1)
        ->assertJsonPath('data.review_notes', 'ok');

    $this->postJson('/api/v1/auth/login', ['email' => 'wait@example.com', 'password' => 'Strong#Pass1'])->assertOk();
});

it('removes the parked row when a create is rejected', function () {
    $clerk = requester(['languages.create' => true]);
    $boss = adminUser();

    $id = $this->withToken(accessToken($clerk))->postJson('/api/v1/languages', ['name' => 'Tamil', 'code' => 'ta'])
        ->assertStatus(202)->json('approval_id');
    expect(Language::query()->where('code', 'ta')->value('approval_status'))->toBe('pending');

    $this->withToken(accessToken($boss))->patchJson("/api/v1/approvals/{$id}/reject")
        ->assertOk()->assertJsonPath('data.is_active', 0);

    expect(Language::withTrashed()->where('code', 'ta')->exists())->toBeFalse();
});

it('flags rows with an open edit request in lists (has_pending_approval)', function () {
    $clerk = requester(['languages.view' => false, 'languages.edit' => true]);
    $language = Language::query()->create(['name' => 'English', 'code' => 'en']);

    $this->withToken(accessToken($clerk))->putJson("/api/v1/languages/{$language->id}", ['name' => 'British English'])
        ->assertStatus(202);

    $this->withToken(accessToken($clerk))->getJson('/api/v1/languages')
        ->assertJsonPath('data.0.has_pending_approval', true)
        ->assertJsonPath('data.0.name', 'English');
});

it('lists requests in the v1 non-envelope shape with requester details', function () {
    $clerk = requester(['languages.create' => true]);
    $this->withToken(accessToken($clerk))->postJson('/api/v1/languages', ['name' => 'Hindi', 'code' => 'hi']);

    $this->withToken(accessToken(adminUser()))->getJson('/api/v1/approvals?is_active=2')
        ->assertOk()
        ->assertJsonMissingPath('timestamp')
        ->assertJsonPath('pagination.totalItems', 1)
        ->assertJsonPath('data.0.module_slug', 'languages')
        ->assertJsonPath('data.0.requester.email', $clerk->email);
});

it('only lets level 100 review, and only within the reviewer\'s company', function () {
    $clerk = requester(['languages.create' => true]);
    $id = $this->withToken(accessToken($clerk))->postJson('/api/v1/languages', ['name' => 'Hindi', 'code' => 'hi'])->json('approval_id');

    $this->withToken(accessToken($clerk))->patchJson("/api/v1/approvals/{$id}/approve")
        ->assertStatus(403)->assertJsonPath('message', 'Access denied. Insufficient role level.');

    $outsider = adminUser(Company::factory()->create());
    $this->withToken(accessToken($outsider))->patchJson("/api/v1/approvals/{$id}/approve")->assertStatus(404);

    expect(Tenancy::bypass(fn () => ApprovalRequest::query()->find($id)->status))->toBe(ApprovalStatus::Pending);
});

it('lets the requester withdraw their own open request', function () {
    $clerk = requester(['languages.create' => true]);
    $id = $this->withToken(accessToken($clerk))->postJson('/api/v1/languages', ['name' => 'Hindi', 'code' => 'hi'])->json('approval_id');

    $this->withToken(accessToken(adminUser()))->deleteJson("/api/v1/approvals/{$id}")->assertStatus(400);
    $this->withToken(accessToken($clerk))->deleteJson("/api/v1/approvals/{$id}")
        ->assertExactJson(['success' => true, 'message' => 'Request cancelled successfully']);
});

it('runs approved changes for modules whose approval v1 could never execute', function () {
    // v1's service map lacked translations (and 7 more): approving threw "No service found".
    $clerk = requester(['translations.create' => true]);
    Language::query()->create(['name' => 'English', 'code' => 'en']);
    Language::query()->create(['name' => 'Tamil', 'code' => 'ta']);
    app()->instance(MachineTranslator::class, new class implements MachineTranslator
    {
        public function translate(string $text, string $from, string $to): string
        {
            return $from === $to ? $text : "[{$to}] {$text}";
        }
    });

    $id = $this->withToken(accessToken($clerk))->postJson('/api/v1/translation-keys', ['key' => 'common.save', 'default_value' => 'Save'])
        ->assertStatus(202)->json('approval_id');

    $this->withToken(accessToken(adminUser()))->patchJson("/api/v1/approvals/{$id}/approve")->assertOk();

    $this->getJson('/api/v1/translations/en')->assertJsonPath('data.translations', ['common.save' => 'Save']);
    $this->getJson('/api/v1/translations/ta')->assertJsonPath('data.translations', ['common.save' => '[ta] Save']);
});

it('lets a developer create a company whose first admin can actually sign in', function () {
    // v1 hashed that admin's password twice, so it never worked.
    $dev = adminUser(role: 'developer');

    $this->withToken(accessToken($dev))->postJson('/api/v1/companies', [
        'name' => 'Acme', 'slug' => 'acme',
        'admin_full_name' => 'Acme Owner', 'admin_email' => 'owner@acme.test', 'admin_password' => 'Acme#Pass1',
    ])->assertCreated()->assertJsonPath('data.company.slug', 'acme');

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@acme.test', 'password' => 'Acme#Pass1'])
        ->assertOk()
        ->assertJsonPath('data.user.role.slug', 'super_admin')
        ->assertJsonPath('data.user.company.slug', 'acme');
});

it('keeps company administration to developers', function () {
    $this->withToken(accessToken(adminUser()))->getJson('/api/v1/companies')
        ->assertStatus(403)->assertJsonPath('message', 'Access denied. Developer role required.');
});
