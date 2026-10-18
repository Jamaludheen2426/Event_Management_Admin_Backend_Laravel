<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Messaging\Actions\Mailbox;
use App\Modules\Messaging\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/mail/* — in-app mail for admins, vendors and clients
 * (v1 mail.controller + mailNotification.controller).
 */
final class MailController
{
    public function __construct(private readonly Mailbox $mail) {}

    public function contacts(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mail->contacts($this->actor($request)), 'Contacts fetched.');
    }

    public function notifications(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mail->notifications($this->actor($request)), 'Notifications fetched.');
    }

    public function readNotifications(Request $request): JsonResponse
    {
        $this->mail->readAllNotifications($this->actor($request));

        return ApiResponse::success(null, 'Notifications marked as read.');
    }

    public function saveDraft(Request $request): JsonResponse
    {
        return ApiResponse::created($this->mail->saveDraft($this->actor($request), $request->all()), 'Draft saved.');
    }

    public function updateDraft(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->mail->updateDraft($this->actor($request), $id, $request->all()), 'Draft updated.');
    }

    public function sendDraft(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->mail->sendDraft($this->actor($request), $id, $request->all()), 'Draft sent.');
    }

    public function send(Request $request): JsonResponse
    {
        return ApiResponse::created($this->mail->send($this->actor($request), $request->all()), 'Mail sent.');
    }

    public function inbox(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mail->inbox($this->actor($request), $request->query()), 'Inbox fetched.');
    }

    public function sent(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mail->sentBox($this->actor($request), $request->query(), 'sent'), 'Sent mails fetched.');
    }

    public function drafts(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mail->sentBox($this->actor($request), $request->query(), 'draft'), 'Drafts fetched.');
    }

    public function trash(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mail->trash($this->actor($request)), 'Trash fetched.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->mail->find($this->actor($request), $id), 'Mail fetched.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->mail->setState($this->actor($request), $id, from: 1, to: 0);

        return ApiResponse::success(null, 'Moved to trash.');
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $this->mail->setState($this->actor($request), $id, from: 0, to: 1);

        return ApiResponse::success(null, 'Mail restored.');
    }

    public function purge(Request $request, int $id): JsonResponse
    {
        $this->mail->setState($this->actor($request), $id, from: 0, to: 2);

        return ApiResponse::success(null, 'Mail permanently deleted.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $this->mail->trashMany($this->actor($request), $this->ids($request));

        return ApiResponse::success(null, 'Moved to trash.');
    }

    public function toggleRead(Request $request, int $id): JsonResponse
    {
        $this->mail->toggleRead($this->actor($request), $id);

        return ApiResponse::success(null, 'Read status updated.');
    }

    public function bulkRead(Request $request): JsonResponse
    {
        $this->mail->markMany($this->actor($request), $this->ids($request), filter_var($request->input('is_read'), FILTER_VALIDATE_BOOL));

        return ApiResponse::success(null, 'Read status updated.');
    }

    public function label(Request $request, int $id): JsonResponse
    {
        $this->mail->label($this->actor($request), [$id], $request->input('label'));

        return ApiResponse::success(null, 'Label assigned.');
    }

    public function bulkLabel(Request $request): JsonResponse
    {
        $this->mail->label($this->actor($request), $this->ids($request), $request->input('label'));

        return ApiResponse::success(null, 'Labels assigned.');
    }

    public function folder(Request $request, int $id): JsonResponse
    {
        $this->mail->moveToFolder($this->actor($request), [$id], $request->input('folder_id'));

        return ApiResponse::success(null, 'Mail moved to folder.');
    }

    public function bulkFolder(Request $request): JsonResponse
    {
        $this->mail->moveToFolder($this->actor($request), $this->ids($request), $request->input('folder_id'));

        return ApiResponse::success(null, 'Mails moved to folder.');
    }

    public function folders(Request $request): JsonResponse
    {
        return ApiResponse::success($this->mail->folderList($this->actor($request)), 'Folders fetched.');
    }

    public function createFolder(Request $request): JsonResponse
    {
        return ApiResponse::created($this->mail->createFolder($this->actor($request), $request->input('name')), 'Folder created.');
    }

    public function updateFolder(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->mail->updateFolder($this->actor($request), $id, $request->all()), 'Folder updated.');
    }

    public function deleteFolder(Request $request, int $id): JsonResponse
    {
        $this->mail->deleteFolder($this->actor($request), $id);

        return ApiResponse::success(null, 'Folder deleted.');
    }

    private function actor(Request $request): Actor
    {
        return Actor::of($request->user());
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $ids = $request->input('ids');

        return array_values(array_map('intval', array_filter(is_array($ids) ? $ids : [], 'is_numeric')));
    }
}
