<?php

namespace App\Services\Media;

use Aws\S3\S3Client;
use Illuminate\Support\Str;

/**
 * Thin wrapper around the AWS S3 SDK for everything the media pipeline needs:
 * presigned PUT URLs for direct-from-device uploads, reading small objects
 * for thumbnail/BlurHash generation, and copying staging objects into their
 * final approved location.
 */
class S3MediaStore
{
    private S3Client $client;

    public function __construct()
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => config('services.aws.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
    }

    public function bucket(): string
    {
        return (string) config('services.aws.bucket');
    }

    /**
     * Build a brand-new staging key for an incoming upload.
     */
    public function newStagingKey(string $originalFilename): string
    {
        $extension = Str::lower(pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'bin');

        return sprintf(
            '%s/%s.%s',
            config('services.aws.staging_prefix'),
            (string) Str::uuid(),
            $extension,
        );
    }

    /**
     * A presigned URL the client can PUT the raw file bytes to directly.
     */
    public function presignedPutUrl(string $key, string $contentType, int $expiresInSeconds = 900): string
    {
        $command = $this->client->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $contentType,
        ]);

        $request = $this->client->createPresignedRequest($command, "+{$expiresInSeconds} seconds");

        return (string) $request->getUri();
    }

    /**
     * Fetch the raw bytes of an object (used only for thumbnail generation,
     * where we must touch the pixels - kept to the smallest objects
     * possible, never full-resolution video).
     */
    public function getObjectBody(string $key): string
    {
        $result = $this->client->getObject([
            'Bucket' => $this->bucket(),
            'Key' => $key,
        ]);

        return (string) $result['Body'];
    }

    public function putObject(string $key, string $body, string $contentType): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
        ]);
    }

    public function headObject(string $key): ?array
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $key,
            ]);

            return $result->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    public function copyObject(string $fromKey, string $toKey): void
    {
        $this->client->copyObject([
            'Bucket' => $this->bucket(),
            'CopySource' => rawurlencode($this->bucket().'/'.$fromKey),
            'Key' => $toKey,
        ]);
    }

    public function deleteObject(string $key): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket(),
            'Key' => $key,
        ]);
    }

    public function finalMediaKey(?int $eventId, string $stagingKey): string
    {
        $extension = pathinfo($stagingKey, PATHINFO_EXTENSION);
        $folder = $eventId ? (string) $eventId : 'unassigned';

        return sprintf(
            '%s/%s/%s.%s',
            config('services.aws.media_prefix'),
            $folder,
            (string) Str::uuid(),
            $extension,
        );
    }

    public function thumbnailKeyFor(string $mediaKey): string
    {
        $dot = strrpos($mediaKey, '.');
        $base = $dot !== false ? substr($mediaKey, 0, $dot) : $mediaKey;

        return $base.'_thumb.jpg';
    }
}
