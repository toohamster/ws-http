<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Support;

class VisionMessage
{
    public static function text(string $text): array
    {
        return [
            'type' => 'text',
            'text' => $text,
        ];
    }

    public static function image(string $url, ?string $detail = null): array
    {
        $image = ['url' => $url];

        if ($detail !== null) {
            $image['detail'] = $detail;
        }

        return [
            'type' => 'image_url',
            'image_url' => $image,
        ];
    }

    public static function claudeImage(string $base64, string $mediaType = 'image/jpeg'): array
    {
        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64,
            ],
        ];
    }
}
