<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\PersonReferencePhoto;
use App\Models\Tag;
use App\Services\Media\CloudFrontUrlSigner;
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
        private readonly CloudFrontUrlSigner $signer,
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
        $keys = $this->rekognition->indexPersonFaces(
            $person,
            $items->pluck('s3_key')->filter()->values()->all(),
        );
        $count = count($keys);
        $this->rememberReferences($person, $keys, $items->pluck('id', 's3_key')->all());

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

        $indexedKeys = $this->rekognition->indexPersonFaces($person, $keys, replace: true);
        $count = count($indexedKeys);
        $this->rememberReferences($person, $indexedKeys);

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

    /**
     * The photos that trained this person. Existing people keep working
     * before the next retrain: uploaded shots live under people/{id}/, and
     * gallery training is the photos tagged with this person by hand.
     */
    public function references(Person $person): JsonResponse
    {
        $rows = $person->referencePhotos()->with('mediaItem')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $rows = $this->backfillReferences($person);
        }

        return response()->json([
            'data' => [
                'id' => $person->id,
                'name' => $person->name,
                'reference_face_count' => $person->reference_face_count,
                'photos' => $rows->map(fn (PersonReferencePhoto $row) => [
                    'url' => $this->signer->signedUrl($row->mediaItem?->thumbnail_key ?: $row->s3_key),
                    'full_url' => $this->signer->signedUrl($row->s3_key),
                    'media_item_id' => $row->media_item_id,
                ])->values(),
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, int>  $mediaIdsByKey
     */
    private function rememberReferences(Person $person, array $keys, array $mediaIdsByKey = []): void
    {
        $person->referencePhotos()->delete();

        foreach ($keys as $key) {
            PersonReferencePhoto::query()->create([
                'person_id' => $person->id,
                's3_key' => $key,
                'media_item_id' => $mediaIdsByKey[$key] ?? null,
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, PersonReferencePhoto>
     */
    private function backfillReferences(Person $person)
    {
        $uploaded = [];
        try {
            $uploaded = $this->store->listObjects("people/{$person->id}/");
        } catch (\Throwable $e) {
            report($e);
        }
        $limit = $person->reference_face_count > 0 ? $person->reference_face_count : count($uploaded);
        $keys = array_slice(array_column($uploaded, 'key'), 0, $limit);

        if ($keys === [] && $person->tag_id) {
            $items = MediaItem::query()
                ->where('type', 'image')
                ->whereNotNull('s3_key')
                ->whereHas('tags', function ($query) use ($person) {
                    $query->where('tags.id', $person->tag_id)
                        ->where('media_tag.source', 'manual');
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            $this->rememberReferences(
                $person,
                $items->pluck('s3_key')->all(),
                $items->pluck('id', 's3_key')->all(),
            );

            return $person->referencePhotos()->with('mediaItem')->orderBy('id')->get();
        }

        if ($keys !== []) {
            $this->rememberReferences($person, $keys);
        }

        return $person->referencePhotos()->with('mediaItem')->orderBy('id')->get();
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
