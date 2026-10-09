<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Tag;
use Aws\Rekognition\RekognitionClient;

/**
 * Calls AWS Rekognition directly against the S3 object (no download needed)
 * so the shared-hosting cron tick stays cheap. Produces:
 *  - scene/object labels (DetectLabels) -> 'moment'/'category' tags
 *  - known-person matches (SearchFacesByImage against our Face Collection) -> 'person' tags
 *
 * Scoped to images only - video auto-tagging is intentionally out of scope.
 */
class RekognitionAutoTagger
{
    private ?RekognitionClient $client = null;

    public function __construct(private readonly S3MediaStore $store) {}

    private function client(): RekognitionClient
    {
        return $this->client ??= new RekognitionClient([
            'version' => 'latest',
            'region' => config('services.aws.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
    }

    public function enabled(): bool
    {
        return (bool) config('services.aws.rekognition.enabled');
    }

    /**
     * @return array<int, array{tag: Tag, source: string, confidence: int}>
     */
    public function autoTag(MediaItem $mediaItem): array
    {
        if (! $this->enabled() || ! $mediaItem->isImage()) {
            return [];
        }

        $results = [];

        foreach ($this->detectLabelTags($mediaItem) as $result) {
            $results[] = $result;
        }

        foreach ($this->detectFaceTags($mediaItem) as $result) {
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @return array<int, array{tag: Tag, source: string, confidence: int}>
     */
    private function detectLabelTags(MediaItem $mediaItem): array
    {
        try {
            $response = $this->client()->detectLabels([
                'Image' => [
                    'S3Object' => [
                        'Bucket' => $this->store->bucket(),
                        'Name' => $mediaItem->s3_key,
                    ],
                ],
                'MaxLabels' => (int) config('services.aws.rekognition.max_labels', 8),
                'MinConfidence' => (float) config('services.aws.rekognition.min_label_confidence', 75),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $results = [];

        foreach ($response['Labels'] ?? [] as $label) {
            $name = (string) ($label['Name'] ?? '');
            if ($name === '') {
                continue;
            }

            $tag = Tag::query()->firstOrCreate(
                ['name' => $name, 'kind' => 'moment'],
            );

            $results[] = [
                'tag' => $tag,
                'source' => 'auto',
                'confidence' => (int) round((float) ($label['Confidence'] ?? 0)),
            ];
        }

        return $results;
    }

    /**
     * @return array<int, array{tag: Tag, source: string, confidence: int}>
     */
    private function detectFaceTags(MediaItem $mediaItem): array
    {
        $collectionId = (string) config('services.aws.rekognition.face_collection_id');

        try {
            $response = $this->client()->searchFacesByImage([
                'CollectionId' => $collectionId,
                'Image' => [
                    'S3Object' => [
                        'Bucket' => $this->store->bucket(),
                        'Name' => $mediaItem->s3_key,
                    ],
                ],
                'FaceMatchThreshold' => (float) config('services.aws.rekognition.min_face_confidence', 80),
                'MaxFaces' => 10,
            ]);
        } catch (\Throwable $e) {
            // No faces found, or the collection doesn't exist yet - both are
            // fine, auto-tagging simply yields no person suggestions.
            report($e);

            return [];
        }

        $results = [];

        foreach ($response['FaceMatches'] ?? [] as $match) {
            $externalImageId = $match['Face']['ExternalImageId'] ?? null;
            $similarity = (int) round((float) ($match['Similarity'] ?? 0));

            if (! $externalImageId) {
                continue;
            }

            $person = Person::query()->find($externalImageId);
            if (! $person || ! $person->tag_id) {
                continue;
            }

            $results[] = [
                'tag' => $person->tag,
                'source' => 'auto',
                'confidence' => $similarity,
            ];
        }

        return $results;
    }

    /**
     * Index a person's reference photo (already uploaded to S3) into the
     * Face Collection, keyed by Person::rekognitionExternalId().
     */
    public function indexPersonFace(Person $person, string $s3Key): bool
    {
        $collectionId = (string) config('services.aws.rekognition.face_collection_id');

        try {
            $this->client()->indexFaces([
                'CollectionId' => $collectionId,
                'ExternalImageId' => $person->rekognitionExternalId(),
                'Image' => [
                    'S3Object' => [
                        'Bucket' => $this->store->bucket(),
                        'Name' => $s3Key,
                    ],
                ],
                'MaxFaces' => 1,
                'QualityFilter' => 'AUTO',
                'DetectionAttributes' => [],
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function ensureCollectionExists(): void
    {
        $collectionId = (string) config('services.aws.rekognition.face_collection_id');

        try {
            $this->client()->describeCollection(['CollectionId' => $collectionId]);
        } catch (\Throwable) {
            try {
                $this->client()->createCollection(['CollectionId' => $collectionId]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
