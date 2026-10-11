<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Tag;
use App\Services\Media\RekognitionAutoTagger;
use App\Services\Media\S3MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonController extends Controller
{
    public function __construct(
        private readonly S3MediaStore $store,
        private readonly RekognitionAutoTagger $rekognition,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Person::query()->with('tag')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        if ($this->personNameTaken($data['name'])) {
            return response()->json(['message' => 'A person with that name already exists.'], 422);
        }

        $person = $this->createPerson($data['name']);

        return response()->json(['data' => $person->load('tag')], 201);
    }

    /**
     * Name this person from photos already in the gallery. Stores those
     * faces as references and labels the selected photos with the new name.
     * Does not search the photos for other people.
     */
    public function trainFromGallery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'media_item_ids' => ['required', 'array', 'min:1', 'max:5'],
            'media_item_ids.*' => ['integer', 'distinct'],
        ]);

        if ($this->personNameTaken($data['name'])) {
            return response()->json(['message' => 'A person with that name already exists.'], 422);
        }

        $ids = array_values(array_unique($data['media_item_ids']));
        $items = $this->visibleImages($request, $ids);

        if ($items->count() !== count($ids)) {
            return response()->json([
                'message' => 'Choose 1 to 5 approved photos where that person is the main face.',
            ], 422);
        }

        $person = $this->createPerson($data['name']);
        $count = $this->rekognition->indexPersonFaces(
            $person,
            $items->pluck('s3_key')->filter()->values()->all(),
        );

        $person->update([
            'indexed_in_rekognition' => $count > 0,
            'reference_face_count' => $count,
        ]);

        foreach ($items as $item) {
            $item->tags()->syncWithoutDetaching([
                $person->tag_id => [
                    'source' => 'manual',
                    'confidence' => null,
                ],
            ]);
        }

        $person->refresh();
        $person->load('tag');

        return response()->json([
            'data' => $person,
            'indexed' => $count > 0,
            'reference_face_count' => $count,
        ], 201);
    }

    /**
     * Run face search on the selected photos only and attach every trained
     * person Rekognition recognizes.
     */
    public function tagSelected(Request $request): JsonResponse
    {
        $data = $request->validate([
            'media_item_ids' => ['required', 'array', 'min:1', 'max:10'],
            'media_item_ids.*' => ['integer', 'distinct'],
        ]);

        $items = $this->visibleImages($request, $data['media_item_ids']);

        if ($items->isEmpty()) {
            return response()->json(['message' => 'Choose approved photos to tag.'], 422);
        }

        $tagged = 0;
        $unmatched = 0;

        foreach ($items as $item) {
            $matches = $this->rekognition->searchPersonTags($item);

            if ($matches === []) {
                $unmatched++;

                continue;
            }

            foreach ($matches as $match) {
                $item->tags()->syncWithoutDetaching([
                    $match['tag']->id => [
                        'source' => 'auto',
                        'confidence' => $match['confidence'],
                    ],
                ]);
            }

            $tagged++;
        }

        return response()->json([
            'tagged' => $tagged,
            'unmatched' => $unmatched,
        ]);
    }

    /**
     * Step 1: get a presigned URL to upload a reference ("training") photo
     * for this person directly to S3.
     */
    public function presignReferencePhoto(Request $request, Person $person): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string'],
            'content_type' => ['required', 'string'],
        ]);

        $key = sprintf(
            'people/%d/%s-%s',
            $person->id,
            (string) Str::uuid(),
            basename($data['filename']),
        );

        return response()->json([
            'data' => [
                's3_key' => $key,
                'upload_url' => $this->store->presignedPutUrl($key, $data['content_type']),
            ],
        ]);
    }

    /**
     * Step 2: once the reference photos are uploaded, replace this person's
     * indexed faces with that set (1 to 5).
     */
    public function trainFromReferencePhoto(Request $request, Person $person): JsonResponse
    {
        $data = $request->validate([
            's3_key' => ['sometimes', 'string'],
            's3_keys' => ['sometimes', 'array', 'min:1', 'max:5'],
            's3_keys.*' => ['string'],
        ]);

        $keys = $data['s3_keys'] ?? (isset($data['s3_key']) ? [$data['s3_key']] : []);

        if ($keys === [] || count($keys) > 5) {
            return response()->json(['message' => 'Choose 1 to 5 photos.'], 422);
        }

        $prefix = "people/{$person->id}/";

        foreach ($keys as $key) {
            if (! str_starts_with($key, $prefix) || str_contains($key, '..')) {
                return response()->json(['message' => 'Invalid reference photo.'], 422);
            }
        }

        $count = $this->rekognition->indexPersonFaces($person, $keys, replace: true);

        $person->update([
            'indexed_in_rekognition' => $count > 0,
            'reference_face_count' => $count,
        ]);

        $person->refresh();

        return response()->json([
            'data' => $person,
            'indexed' => $count > 0,
            'reference_face_count' => $count,
        ]);
    }

    private function personNameTaken(string $name): bool
    {
        return Tag::query()->where('name', $name)->where('kind', 'person')->exists()
            || Person::query()->where('name', $name)->exists();
    }

    private function createPerson(string $name): Person
    {
        return DB::transaction(function () use ($name) {
            $tag = Tag::query()->create(['name' => $name, 'kind' => 'person']);
            $person = Person::query()->create(['name' => $name, 'tag_id' => $tag->id]);
            $tag->update(['person_id' => $person->id]);

            return $person;
        });
    }

    /**
     * Approved images the current user is allowed to see.
     *
     * @param  array<int, int>  $ids
     */
    private function visibleImages(Request $request, array $ids)
    {
        $canSeePrivate = $request->user()->can('view-private-media');

        return MediaItem::query()
            ->with('event')
            ->whereIn('id', $ids)
            ->where('status', MediaItem::STATUS_APPROVED)
            ->where('type', 'image')
            ->get()
            ->filter(function (MediaItem $item) use ($canSeePrivate) {
                if ($canSeePrivate) {
                    return true;
                }

                return $item->effectiveVisibility() === 'public';
            })
            ->values();
    }
}
