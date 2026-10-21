<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Identity\Contracts\Recipients;
use App\Modules\Notifications\Actions\MarkAllRead;
use App\Modules\Notifications\Actions\MarkRead;
use App\Modules\Notifications\Http\Requests\ListNotificationsRequest;
use App\Modules\Notifications\Http\Requests\ReadAllRequest;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\Inbox;
use App\Shared\Live\ConditionalPoll;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The caller's own inbox (phase-2 plan section 4; notifications.view, which every role holds):
 *
 *   GET  /notifications                       200 {data: [Notification], unread_count, cursor, server_time} + ETag, or 304
 *                                             ?unread=1, ?since=<cursor>, ?limit= (default 30, at most 100); newest first
 *   POST /notifications/{notification}/read   200 the Notification, read; 404 for a line that is not the caller's
 *   POST /notifications/read-all              200 {updated}; {before_id?} marks only the lines up to that id
 *
 * Lines are always the caller's own; the writes are AuditedActions behind an Idempotency-Key (Actions\MarkRead,
 * Actions\MarkAllRead). title and body are in the caller's language (Recipients::localeOf).
 */
final class NotificationsController
{
    public function __construct(private readonly Recipients $recipients) {}

    public function index(ListNotificationsRequest $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $inbox = new Inbox($user->id, $request->unread(), $request->limit(), $request->since());
        $now = CarbonImmutable::now();

        return ConditionalPoll::respond($request, $inbox->etag($now), function () use ($request, $inbox, $now): array {
            $page = $inbox->page($now);
            $locale = $this->locale($request);

            return [
                'data' => $page['lines']->map(fn (Notification $line): array => (new NotificationResource($line, $locale))->resolve($request))->values()->all(),
                'unread_count' => $inbox->unreadCount(),
                'cursor' => (string) $page['cursor'],
                'server_time' => $now->toIso8601String(),
            ];
        });
    }

    public function read(Request $request, int $notification, MarkRead $markRead): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $line = $markRead->handle($user->id, $notification);

        return response()->json((new NotificationResource($line, $this->locale($request)))->resolve($request));
    }

    public function readAll(ReadAllRequest $request, MarkAllRead $markAllRead): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return response()->json(['updated' => $markAllRead->handle($user->id, $request->beforeId())]);
    }

    private function locale(Request $request): string
    {
        $user = $request->user();

        return $user === null ? 'en' : $this->recipients->localeOf($user);
    }
}
