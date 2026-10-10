<?php

namespace App\Services\Media;

use Aws\CloudFront\CloudFrontClient;

/**
 * Issues a short-lived view URL so private media stays access-controlled.
 * CloudFront signed URLs when that distribution is configured; otherwise an
 * S3 presigned GET, which needs no extra service and works on shared hosting.
 */
class CloudFrontUrlSigner
{
    public function __construct(private readonly S3MediaStore $store) {}

    public function configured(): bool
    {
        return filled(config('services.aws.cloudfront.domain'))
            && filled(config('services.aws.cloudfront.key_pair_id'))
            && filled(config('services.aws.cloudfront.private_key_path'));
    }

    public function signedUrl(string $key): string
    {
        if (! $this->configured()) {
            $ttl = (int) config('services.aws.cloudfront.signed_url_ttl_seconds', 600);

            return $this->store->presignedGetUrl($key, $ttl);
        }

        $domain = rtrim((string) config('services.aws.cloudfront.domain'), '/');
        $url = "https://{$domain}/".ltrim($key, '/');

        $client = new CloudFrontClient([
            'region' => config('services.aws.region'),
            'version' => 'latest',
        ]);

        $expires = time() + (int) config('services.aws.cloudfront.signed_url_ttl_seconds', 600);

        return $client->getSignedUrl([
            'url' => $url,
            'expires' => $expires,
            'private_key' => $this->privateKeyPath(),
            'key_pair_id' => config('services.aws.cloudfront.key_pair_id'),
        ]);
    }

    /**
     * Namecheap's PHP process is not started from the Laravel root, so a
     * relative storage path has to be resolved from base_path().
     */
    private function privateKeyPath(): string
    {
        $path = (string) config('services.aws.cloudfront.private_key_path');

        if ($path !== '' && ! $this->isAbsolutePath($path)) {
            return base_path($path);
        }

        return $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':');
    }
}
