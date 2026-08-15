<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImageStorage
{
    public const DISK = 'product_images';

    /**
     * Upload without object ACLs (Bucket owner enforced buckets reject ACL headers).
     */
    public static function put(string $path, string $contents, ?string $contentType = null): void
    {
        $disk = Storage::disk(self::DISK);
        $config = $disk->getConfig();
        $bucket = $config['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            throw new \RuntimeException('Product images S3 bucket is not configured.');
        }

        $params = [
            'Bucket' => $bucket,
            'Key' => ltrim($path, '/'),
            'Body' => $contents,
        ];

        if ($contentType !== null && $contentType !== '') {
            $params['ContentType'] = $contentType;
        }

        $disk->getClient()->putObject($params);
    }

    /**
     * Resolve a browser-usable URL for a product image representation.
     * S3 objects use temporary signed URLs (bucket ACLs often disabled).
     */
    public static function displayUrl(?string $representation, ?string $representationType): ?string
    {
        if ($representationType !== 'image') {
            return $representation;
        }

        $raw = trim((string) $representation);
        if ($raw === '') {
            return null;
        }

        // Legacy local public disk URLs still work as-is.
        if (str_contains($raw, '/storage/products/')) {
            return $raw;
        }

        $key = self::objectKey($raw);
        if ($key === null) {
            return $raw;
        }

        try {
            return Storage::disk(self::DISK)->temporaryUrl($key, now()->addHours(6));
        } catch (Throwable $e) {
            report($e);

            return $raw;
        }
    }

    /**
     * Extract the S3 object key from a stored key or absolute S3 URL.
     */
    public static function objectKey(string $representation): ?string
    {
        $raw = trim($representation);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'products/')) {
            return $raw;
        }

        // https://bucket.s3.amazonaws.com/products/...
        // https://bucket.s3.region.amazonaws.com/products/...
        // https://custom.cdn/products/...
        if (preg_match('~/(products/[^?#]+)~', $raw, $matches) === 1) {
            return rawurldecode($matches[1]);
        }

        return null;
    }
}
