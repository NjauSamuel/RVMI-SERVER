<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\UserTagFavorite;
use App\Models\UserTagView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = Tag::query()
            ->when($request->string('kind')->isNotEmpty(), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'kind']);

        return response()->json(['data' => $tags]);
    }

    public function favorite(Request $request, Tag $tag): JsonResponse
    {
        UserTagFavorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'tag_id' => $tag->id,
        ]);

        return response()->json(['message' => 'Saved to favorites.']);
    }

    public function unfavorite(Request $request, Tag $tag): JsonResponse
    {
        UserTagFavorite::query()
            ->where('user_id', $request->user()->id)
            ->where('tag_id', $tag->id)
            ->delete();

        return response()->json(['message' => 'Removed from favorites.']);
    }

    public function myFavorites(Request $request): JsonResponse
    {
        $tags = Tag::query()
            ->whereHas('favoritedBy', fn ($q) => $q->where('user_id', $request->user()->id))
            ->get(['id', 'name', 'kind']);

        return response()->json(['data' => $tags]);
    }

    /**
     * "For You" rail: favorited tags first, then whatever the user has
     * viewed most/most-recently - a simple, explainable heuristic rather
     * than a heavyweight recommender.
     */
    public function suggested(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $favorited = Tag::query()
            ->whereHas('favoritedBy', fn ($q) => $q->where('user_id', $userId))
            ->get(['id', 'name', 'kind'])
            ->map(fn (Tag $tag) => [...$tag->toArray(), 'reason' => 'favorite']);

        $mostViewed = Tag::query()
            ->join('user_tag_views', 'user_tag_views.tag_id', '=', 'tags.id')
            ->where('user_tag_views.user_id', $userId)
            ->whereNotIn('tags.id', $favorited->pluck('id'))
            ->orderByDesc('user_tag_views.view_count')
            ->orderByDesc('user_tag_views.last_viewed_at')
            ->limit(10)
            ->get(['tags.id', 'tags.name', 'tags.kind'])
            ->map(fn (Tag $tag) => [...$tag->toArray(), 'reason' => 'frequently_viewed']);

        return response()->json(['data' => $favorited->concat($mostViewed)->values()]);
    }
}
