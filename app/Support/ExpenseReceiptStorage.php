<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receipt photos/PDFs for expenses. Never public: S3 objects are reached through short-lived
 * signed URLs, local files are streamed by the app.
 */
class ExpenseReceiptStorage
{
    public const DISK = 'expense_receipts';

    public static function put(UploadedFile $file, string $domainSlug): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = sprintf('expenses/%s/%s.%s', $domainSlug, Str::uuid(), $extension);
        $disk = Storage::disk(self::DISK);

        if ($disk instanceof AwsS3V3Adapter) {
            // Upload without object ACLs, like ProductImageStorage (bucket-owner-enforced buckets reject ACL headers).
            $disk->getClient()->putObject([
                'Bucket' => $disk->getConfig()['bucket'],
                'Key' => $path,
                'Body' => file_get_contents($file->getRealPath()),
                'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
            ]);
        } else {
            $disk->putFileAs(dirname($path), $file, basename($path));
        }

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function response(string $path): Response
    {
        $disk = Storage::disk(self::DISK);

        if ($disk instanceof AwsS3V3Adapter) {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(10)));
        }

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
