<?php

namespace App\Services\Media;

use Intervention\Image\Colors\Rgb\Channels\Blue;
use Intervention\Image\Colors\Rgb\Channels\Green;
use Intervention\Image\Colors\Rgb\Channels\Red;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Kornrunner\Blurhash\Blurhash;

/**
 * Generates a small JPEG thumbnail + a BlurHash placeholder string for an
 * image, Telegram-style: the client paints the BlurHash instantly, then
 * swaps in the thumbnail, then the full image only on open.
 */
class ThumbnailGenerator
{
    private const THUMB_MAX_DIMENSION = 480;

    private const BLURHASH_SAMPLE_DIMENSION = 32;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(Driver::class);
    }

    /**
     * @return array{thumbnail: string, blurhash: string, width: int, height: int}
     */
    public function process(string $binaryImage): array
    {
        $image = $this->manager->decodeBinary($binaryImage);
        $width = $image->width();
        $height = $image->height();

        $thumb = $this->manager->decodeBinary($binaryImage)
            ->scaleDown(width: self::THUMB_MAX_DIMENSION, height: self::THUMB_MAX_DIMENSION);
        $thumbnailBinary = (string) $thumb->encode(new JpegEncoder(quality: 72));

        try {
            $blurhash = $this->encodeBlurhash($image);
        } catch (\Throwable) {
            $blurhash = '';
        }

        return [
            'thumbnail' => $thumbnailBinary,
            'blurhash' => $blurhash,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function encodeBlurhash(ImageInterface $image): string
    {
        $sample = (clone $image)->scaleDown(
            width: self::BLURHASH_SAMPLE_DIMENSION,
            height: self::BLURHASH_SAMPLE_DIMENSION,
        );

        $width = $sample->width();
        $height = $sample->height();

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $color = $sample->colorAt($x, $y);
                $row[] = [
                    $color->channel(Red::class)->value(),
                    $color->channel(Green::class)->value(),
                    $color->channel(Blue::class)->value(),
                ];
            }
            $pixels[] = $row;
        }

        return Blurhash::encode($pixels, 4, 3);
    }
}
