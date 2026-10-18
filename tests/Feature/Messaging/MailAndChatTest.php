<?php

declare(strict_types=1);

use App\Modules\Messaging\Models\ChatParticipant;
use App\Modules\Messaging\Models\MailRecipient;
use App\Modules\Messaging\Support\ChatRelay;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
| In-app mail (/api/v1/mail) and chat (/api/v1/chat) between admins, vendors
| and clients — one set of routes, the portal named by x-portal-type. v1
| mail, mailNotification, mailContact and chat services, plus the relay's
| internal endpoints for chat-socket/.
*/

beforeEach(function () {
    seedHouse();
    config(['services.chat_socket.secret' => 'relay-secret', 'services.chat_socket.url' => 'http://relay.test']);
    $this->admin = adminUser();
    $this->owner = vendorOwner(['company_name' => 'RA Events']);
    $this->client = client(['vendor_id' => $this->owner->vendor_id, 'name' => 'Asha', 'email' => 'asha@ra.test', 'login_access' => true]);
    $this->as = fn (string $portal) => $this->withToken(accessToken(match ($portal) {
        'admin' => $this->admin, 'vendor' => $this->owner, 'client' => $this->client,
    }))->withHeader('x-portal-type', $portal);
});

it('lets each portal mail only the people it may reach', function () {
    ($this->as)('vendor')->getJson('/api/v1/mail/contacts')
        ->assertOk()->assertJsonPath('data.admins.0.id', $this->admin->id)->assertJsonPath('data.clients.0.name', 'Asha');
    ($this->as)('client')->getJson('/api/v1/mail/contacts')
        ->assertOk()->assertJsonPath('data.vendors.0.id', $this->owner->vendor_id)->assertJsonCount(0, 'data.admins');

    // A client may only write to their own vendor.
    ($this->as)('client')->postJson('/api/v1/mail/send', ['subject' => 'Hi', 'body' => '<p>x</p>', 'recipients' => [['type' => 'admin', 'id' => $this->admin->id]]])
        ->assertForbidden()->assertJsonPath('message', 'Recipient is not allowed for this account.');
    ($this->as)('client')->postJson('/api/v1/mail/send', ['subject' => 'Hi', 'body' => '<p><br></p>', 'recipients' => [['type' => 'vendor', 'id' => $this->owner->vendor_id]]])
        ->assertStatus(400)->assertJsonPath('message', 'Message body is required.');

    $sent = ($this->as)('client')->postJson('/api/v1/mail/send', ['subject' => 'Date change?', 'body' => '<p>Can we move it?</p>', 'recipients' => [['type' => 'vendor', 'id' => $this->owner->vendor_id]]])
        ->assertCreated()->assertJsonPath('message', 'Mail sent.')->assertJsonPath('data.status', 'sent')->json('data');
    expect($sent)->toHaveKeys(['createdAt', 'updatedAt', 'sender_is_active']);

    // The vendor's inbox and bell; reading clears the bell.
    ($this->as)('vendor')->getJson('/api/v1/mail')
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.mail.subject', 'Date change?')->assertJsonPath('data.rows.0.is_read', 0);
    ($this->as)('vendor')->getJson('/api/v1/mail/notifications')->assertJsonPath('data.unread_count', 1)->assertJsonPath('data.notifications.0.mail.subject', 'Date change?');
    ($this->as)('vendor')->patchJson("/api/v1/mail/{$sent['id']}/read")->assertOk();
    ($this->as)('vendor')->getJson('/api/v1/mail/notifications')->assertJsonPath('data.unread_count', 0);

    // The client's Sent names the recipient; the admin cannot open it.
    ($this->as)('client')->getJson('/api/v1/mail/sent')
        ->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.recipients.0.recipient_name', 'RA Events');
    ($this->as)('admin')->getJson("/api/v1/mail/{$sent['id']}")->assertNotFound()->assertJsonPath('message', 'Mail not found.');
});

it('keeps drafts, trash, labels and folders per person', function () {
    $draft = ($this->as)('vendor')->postJson('/api/v1/mail/drafts', ['subject' => 'Plan', 'body' => '', 'recipients' => []])
        ->assertCreated()->assertJsonPath('message', 'Draft saved.')->json('data.id');
    ($this->as)('vendor')->getJson('/api/v1/mail/drafts')->assertJsonPath('data.count', 1);
    ($this->as)('vendor')->postJson("/api/v1/mail/drafts/{$draft}/send", ['body' => '<p>Go</p>', 'recipients' => [['type' => 'client', 'id' => $this->client->id]]])
        ->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.recipients.0.recipient_id', $this->client->id);

    $folder = ($this->as)('client')->postJson('/api/v1/mail/folders', ['name' => 'Wedding'])->assertCreated()->json('data.id');
    ($this->as)('client')->patchJson("/api/v1/mail/{$draft}/folder", ['folder_id' => $folder])->assertOk();
    ($this->as)('client')->patchJson("/api/v1/mail/{$draft}/label", ['label' => 'spam'])->assertStatus(400)->assertJsonPath('message', 'Invalid label.');
    ($this->as)('client')->patchJson("/api/v1/mail/{$draft}/label", ['label' => 'updates'])->assertOk();
    ($this->as)('client')->getJson("/api/v1/mail?folder_id={$folder}&label=updates")->assertJsonPath('data.total', 1);

    // Trash is the client's copy only: the vendor's Sent still has it.
    ($this->as)('client')->deleteJson("/api/v1/mail/{$draft}")->assertOk()->assertJsonPath('message', 'Moved to trash.');
    ($this->as)('client')->getJson('/api/v1/mail')->assertJsonPath('data.total', 0);
    ($this->as)('client')->getJson('/api/v1/mail/trash')->assertJsonCount(1, 'data');
    ($this->as)('vendor')->getJson('/api/v1/mail/sent')->assertJsonPath('data.count', 1);
    ($this->as)('client')->patchJson("/api/v1/mail/trash/{$draft}/restore")->assertOk();
    ($this->as)('client')->getJson('/api/v1/mail')->assertJsonPath('data.total', 1);

    // Deleting the folder puts its mail back in the inbox.
    ($this->as)('client')->deleteJson("/api/v1/mail/folders/{$folder}")->assertOk();
    expect(MailRecipient::query()->where('mail_id', $draft)->value('custom_folder_id'))->toBeNull();
});

it('asks for a portal it can sign in with', function () {
    $this->getJson('/api/v1/mail')->assertUnauthorized();
    // A vendor token presented as the admin portal is refused.
    $this->withToken(accessToken($this->owner))->withHeader('x-portal-type', 'admin')->getJson('/api/v1/mail')->assertUnauthorized();
    // No header: the guard that accepts the token decides.
    $this->flushHeaders()->withToken(accessToken($this->owner))->getJson('/api/v1/mail')->assertOk();
});

it('chats in one direct conversation per pair and tells the relay', function () {
    Http::fake(['relay.test/*' => Http::response(null, 204)]);

    ($this->as)('client')->postJson('/api/v1/chat/conversations/direct', ['type' => 'admin', 'id' => $this->admin->id])
        ->assertForbidden()->assertJsonPath('message', 'Clients can only chat with their vendor.');
    $conversation = ($this->as)('client')->postJson('/api/v1/chat/conversations/direct', ['type' => 'vendor', 'id' => $this->owner->vendor_id])
        ->assertOk()->assertJsonPath('message', 'Conversation ready')->assertJsonPath('data.title', 'RA Events')->json('data');
    ($this->as)('vendor')->postJson('/api/v1/chat/conversations/direct', ['type' => 'client', 'id' => $this->client->id])
        ->assertOk()->assertJsonPath('data.id', $conversation['id'])->assertJsonPath('data.title', 'Asha');

    $message = ($this->as)('client')->postJson('/api/v1/chat/messages', ['conversation_id' => $conversation['id'], 'message' => 'Hello!'])
        ->assertCreated()->assertJsonPath('data.message', 'Hello!')->json('data');
    expect($message)->toHaveKey('createdAt');
    Http::assertSent(static fn (HttpRequest $r): bool => $r->url() === 'http://relay.test/internal/emit'
        && $r['room'] === "chat:{$conversation['id']}" && $r['event'] === 'chat:message' && $r->hasHeader('X-Relay-Secret', 'relay-secret'));

    ($this->as)('vendor')->getJson('/api/v1/chat/conversations')
        ->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.unread_count', 1)->assertJsonPath('data.rows.0.lastMessage.message', 'Hello!');
    ($this->as)('vendor')->getJson("/api/v1/chat/conversations/{$conversation['id']}/messages")->assertJsonPath('data.rows.0.message', 'Hello!');
    ($this->as)('vendor')->patchJson("/api/v1/chat/conversations/{$conversation['id']}/read")
        ->assertOk()->assertJsonPath('data.last_read_message_id', $message['id']);
    ($this->as)('vendor')->getJson('/api/v1/chat/conversations')->assertJsonPath('data.rows.0.unread_count', 0);

    // Someone outside the conversation cannot read it.
    ($this->as)('admin')->getJson("/api/v1/chat/conversations/{$conversation['id']}/messages")
        ->assertForbidden()->assertJsonPath('message', 'You do not have access to this conversation.');
});

it('lets the relay act only for the person in a valid socket token', function () {
    $conversation = ($this->as)('client')->postJson('/api/v1/chat/conversations/direct', ['type' => 'vendor', 'id' => $this->owner->vendor_id])->json('data');
    $token = ($this->as)('vendor')->getJson('/api/v1/chat/token')->assertOk()->json('data.token');
    expect(app(ChatRelay::class)->verify($token)['actor'] ?? null)->toMatchArray(['type' => 'vendor', 'id' => $this->owner->vendor_id]);

    $this->flushHeaders()->postJson('/api/internal/chat/join', ['token' => $token, 'conversation_id' => $conversation['id']])->assertForbidden();
    $relay = ['X-Relay-Secret' => 'relay-secret'];
    $this->withHeaders($relay)->postJson('/api/internal/chat/join', ['token' => 'forged.token.x', 'conversation_id' => $conversation['id']])->assertUnauthorized();
    $this->withHeaders($relay)->postJson('/api/internal/chat/join', ['token' => $token, 'conversation_id' => $conversation['id']])
        ->assertOk()->assertJsonPath('data.id', $conversation['id']);
    $this->withHeaders($relay)->postJson('/api/internal/chat/send', ['token' => $token, 'payload' => ['conversation_id' => $conversation['id'], 'message' => 'Hi from the socket']])
        ->assertOk()->assertJsonPath('data.sender_type', 'vendor');
    $this->withHeaders($relay)->postJson('/api/internal/chat/read', ['token' => $token, 'conversation_id' => $conversation['id']])
        ->assertOk()->assertJsonPath('data.actor_type', 'vendor');

    expect(ChatParticipant::query()->where('actor_type', 'vendor')->value('last_read_message_id'))->not->toBeNull();
});
