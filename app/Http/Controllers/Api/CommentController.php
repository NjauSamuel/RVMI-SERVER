<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
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
        ]);

        // Deliberately NOT sent immediately - the notifications:dispatch-digest
        // cron command batches these into one push per event, so the feed
        // never feels like a per-comment social notification spam.
        return response()->json([
            'data' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'created_at' => $comment->created_at,
            ],
        ], 201);
    }
}
