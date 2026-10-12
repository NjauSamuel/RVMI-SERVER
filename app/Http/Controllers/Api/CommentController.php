<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Push\MemberPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $event->assertVisibleTo($request->user());

        $comments = $event->comments()
            ->with('user:id,name,avatar_url')
            ->paginate(30);

        return response()->json($comments);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $event->assertVisibleTo($request->user());

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $comment = $event->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'notified' => true,
        ]);

        $actor = $request->user();
        $title = $event->title;
        $body = $actor->name.' commented on '.$event->title;
        $actorId = $actor->id;
        $eventId = $event->id;
        $privateEvent = $event->isPrivate() ? $event : null;

        app()->terminating(function () use ($title, $body, $actorId, $eventId, $privateEvent) {
            app(MemberPush::class)->send($title, $body, [
                'type' => 'event_comment',
                'event_id' => (string) $eventId,
            ], [$actorId], $privateEvent);
        });

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'avatar_url' => $request->user()->avatar_url,
                ],
                'created_at' => $comment->created_at,
            ],
        ], 201);
    }
}
