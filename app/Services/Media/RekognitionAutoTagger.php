<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Tag;
use Aws\Rekognition\RekognitionClient;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

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

    private ?ImageManager $images = null;

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
        return $this->searchCollection([
            'S3Object' => [
                'Bucket' => $this->store->bucket(),
                'Name' => $mediaItem->s3_key,
            ],
        ]);
    }

    /**
     * Every trained person in this photo. SearchFacesByImage only compares
     * the largest face, so a group photo is also cropped per detected face.
     *
     * @return array<int, array{tag: Tag, source: string, confidence: int}>
     */
    public function searchPersonTags(MediaItem $mediaItem): array
    {
        if (! $this->enabled() || ! $mediaItem->isImage()) {
            return [];
        }

        $results = $this->detectFaceTags($mediaItem);
        $boxes = $this->detectFaceBoxes($mediaItem);

        if (count($boxes) > 1) {
            foreach ($this->searchCroppedFaces($mediaItem, $boxes) as $match) {
                $results[] = $match;
            }
        }

        return $this->dedupePersonTags($results);
    }

    /**
     * Store 1 to 5 reference faces for this person. When $replace is true,
     * previously indexed faces for the same ExternalImageId are removed first.
     *
     * @param  array<int, string>  $s3Keys
     */
    public function indexPersonFaces(Person $person, array $s3Keys, bool $replace = false): int
    {
        $this->ensureCollectionExists();

        if ($replace) {
            $this->deletePersonFaces($person);
        }

        $indexed = 0;

        foreach ($s3Keys as $s3Key) {
            if ($this->indexPersonFace($person, $s3Key)) {
                $indexed++;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<int, array{tag: Tag, source: string, confidence: int}>
     */
    private function searchCollection(array $image): array
    {
        $collectionId = (string) config('services.aws.rekognition.face_collection_id');

        try {
            $response = $this->client()->searchFacesByImage([
                'CollectionId' => $collectionId,
                'Image' => $image,
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
            if (! $person || ! $person->tag_id || ! $person->tag) {
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
     * @param  array<int, array{tag: Tag, source: string, confidence: int}>  $results
     * @return array<int, array{tag: Tag, source: string, confidence: int}>
     */
    private function dedupePersonTags(array $results): array
    {
        $best = [];

        foreach ($results as $result) {
            $id = $result['tag']->id;
            if (! isset($best[$id]) || $result['confidence'] > $best[$id]['confidence']) {
                $best[$id] = $result;
            }
        }

        return array_values($best);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectFaceBoxes(MediaItem $mediaItem): array
    {
        try {
            $response = $this->client()->detectFaces([
                'Image' => [
                    'S3Object' => [
                        'Bucket' => $this->store->bucket(),
                        'Name' => $mediaItem->s3_key,
                    ],
                ],
                'Attributes' => ['DEFAULT'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $boxes = [];

        foreach ($response['FaceDetails'] ?? [] as $face) {
            $box = $face['BoundingBox'] ?? null;
            if ((float) ($face['Confidence'] ?? 0) < 80 || ! is_array($box)) {
                continue;
            }

            $boxes[] = $box;
        }

        usort($boxes, function (array $a, array $b): int {
            $areaA = ((float) ($a['Width'] ?? 0)) * ((float) ($a['Height'] ?? 0));
            $areaB = ((float) ($b['Width'] ?? 0)) * ((float) ($b['Height'] ?? 0));

            return $areaB <=> $areaA;
        });

        return array_slice($boxes, 0, 10);
    }

    /**
     * @param  array<int, array<string, mixed>>  $boxes
     * @return array<int, array{tag: Tag, source: string, confidence: int}>
     */
    private function searchCroppedFaces(MediaItem $mediaItem, array $boxes): array
    {
        try {
            $binary = $this->store->getObjectBody($mediaItem->s3_key);
            $image = $this->images()->decodeBinary($binary);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $results = [];

        foreach ($boxes as $box) {
            $crop = $this->cropFace($image, $box);
            if ($crop === null) {
                continue;
            }

            foreach ($this->searchCollection(['Bytes' => $crop]) as $match) {
                $results[] = $match;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $box
     */
    private function cropFace(ImageInterface $image, array $box): ?string
    {
        $width = $image->width();
        $height = $image->height();
        $left = (float) ($box['Left'] ?? 0);
        $top = (float) ($box['Top'] ?? 0);
        $boxWidth = (float) ($box['Width'] ?? 0);
        $boxHeight = (float) ($box['Height'] ?? 0);

        if ($boxWidth <= 0 || $boxHeight <= 0 || $width < 1 || $height < 1) {
            return null;
        }

        $padX = $boxWidth * 0.25;
        $padY = $boxHeight * 0.25;
        $x = max(0, (int) floor(($left - $padX) * $width));
        $y = max(0, (int) floor(($top - $padY) * $height));
        $cropWidth = min($width - $x, (int) ceil(($boxWidth + ($padX * 2)) * $width));
        $cropHeight = min($height - $y, (int) ceil(($boxHeight + ($padY * 2)) * $height));

        if ($cropWidth < 40 || $cropHeight < 40) {
            return null;
        }

        try {
            $clone = (clone $image)->crop($cropWidth, $cropHeight, $x, $y);

            return (string) $clone->encode(new JpegEncoder(quality: 85));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Index a person's reference photo (already uploaded to S3) into the
     * Face Collection, keyed by Person::rekognitionExternalId().
     */
    public function indexPersonFace(Person $person, string $s3Key): bool
    {
        $collectionId = (string) config('services.aws.rekognition.face_collection_id');

        try {
            $response = $this->client()->indexFaces([
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

            return count($response['FaceRecords'] ?? []) > 0;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function deletePersonFaces(Person $person): void
    {
        $collectionId = (string) config('services.aws.rekognition.face_collection_id');
        $externalId = $person->rekognitionExternalId();
        $faceIds = [];
        $nextToken = null;

        try {
            for ($page = 0; $page < 20; $page++) {
                $params = [
                    'CollectionId' => $collectionId,
                    'MaxResults' => 1000,
                ];

                if ($nextToken) {
                    $params['NextToken'] = $nextToken;
                }

                $response = $this->client()->listFaces($params);

                foreach ($response['Faces'] ?? [] as $face) {
                    if (($face['ExternalImageId'] ?? null) === $externalId && isset($face['FaceId'])) {
                        $faceIds[] = $face['FaceId'];
                    }
                }

                $nextToken = $response['NextToken'] ?? null;
                if (! $nextToken) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        foreach (array_chunk($faceIds, 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            try {
                $this->client()->deleteFaces([
                    'CollectionId' => $collectionId,
                    'FaceIds' => $chunk,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function images(): ImageManager
    {
        return $this->images ??= new ImageManager(Driver::class);
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
