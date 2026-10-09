<?php

namespace App\Services\Media;

use Aws\CloudFront\CloudFrontClient;

/**
 * Issues short-lived signed CloudFront URLs so private media stays
 * access-controlled without any persistent server process - just crypto on
 * a normal request/response cycle, which is fine on shared hosting.
 */
class CloudFrontUrlSigner
{
    public function configured(): bool
    {
        return filled(config('services.aws.cloudfront.domain'))
            && filled(config('services.aws.cloudfront.key_pair_id'))
            && filled(config('services.aws.cloudfront.private_key_path'));
    }

    public function signedUrl(string $key): string
    {
        $domain = rtrim((string) config('services.aws.cloudfront.domain'), '/');
        $url = "https://{$domain}/".ltrim($key, '/');

        if (! $this->configured()) {
            // Local/dev fallback - not access-controlled, only used before
            // CloudFront is wired up.
            return $url;
        }

        $client = new CloudFrontClient([
            'region' => config('services.aws.region'),
            'version' => 'latest',
        ]);

        $expires = time() + (int) config('services.aws.cloudfront.signed_url_ttl_seconds', 600);

        return $client->getSignedUrl([
            'url' => $url,
            'expires' => $expires,
            'private_key' => config('services.aws.cloudfront.private_key_path'),
            'key_pair_id' => config('services.aws.cloudfront.key_pair_id'),
        ]);
    }
}
