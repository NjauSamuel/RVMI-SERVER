<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Tag;
use App\Services\Media\RekognitionAutoTagger;
use App\Services\Media\S3MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $tag = Tag::query()->create(['name' => $data['name'], 'kind' => 'person']);
        $person = Person::query()->create(['name' => $data['name'], 'tag_id' => $tag->id]);

        $tag->update(['person_id' => $person->id]);

        return response()->json(['data' => $person->load('tag')], 201);
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

        $key = sprintf('people/%d/%s', $person->id, basename($data['filename']));

        return response()->json([
            'data' => [
                's3_key' => $key,
                'upload_url' => $this->store->presignedPutUrl($key, $data['content_type']),
            ],
        ]);
    }

    /**
     * Step 2: once the reference photo is uploaded, index it into the
     * Rekognition Face Collection under this person's ExternalImageId.
     */
    public function trainFromReferencePhoto(Request $request, Person $person): JsonResponse
    {
        $data = $request->validate(['s3_key' => ['required', 'string']]);

        $this->rekognition->ensureCollectionExists();
        $ok = $this->rekognition->indexPersonFace($person, $data['s3_key']);

        if ($ok) {
            $person->update(['indexed_in_rekognition' => true]);
        }

        return response()->json([
            'data' => $person->fresh(),
            'indexed' => $ok,
        ]);
    }
}
