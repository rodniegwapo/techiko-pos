<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Aws\Exception\AwsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class S3TestUploadController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Dev/S3UploadTest');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $file = $validated['image'];
        $extension = $file->guessExtension() ?: 'jpg';
        $filename = Str::uuid()->toString().'.'.$extension;

        $context = $this->s3LogContext();

        Log::debug('S3 test upload attempt.', $context);

        // On-demand driver with exceptions enabled so AWS errors are visible (global s3 disk uses throw => false).
        $disk = Storage::createS3Driver(array_merge(
            config('filesystems.disks.s3'),
            ['throw' => true]
        ));

        try {
            $path = $disk->putFileAs('test-uploads', $file, $filename);
        } catch (Throwable $e) {
            Log::warning('S3 test upload failed.', array_merge($context, [
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ], $this->awsExceptionLogContext($e)));

            return back()
                ->withErrors(['image' => $this->userFacingS3FailureMessage($e)])
                ->with('error', 'S3 upload failed.');
        }

        if ($path === false || $path === null) {
            Log::warning('S3 test upload failed: storage put returned empty.', $context);

            return back()
                ->withErrors(['image' => 'Failed to store file on S3. Verify AWS credentials, region, and bucket permissions.'])
                ->with('error', 'S3 upload failed.');
        }

        $url = null;
        $temporaryUrlGenerated = false;
        try {
            $url = $disk->temporaryUrl($path, now()->addMinutes(10));
            $temporaryUrlGenerated = true;
        } catch (Throwable $temporaryUrlException) {
            Log::debug('S3 test upload: temporaryUrl failed; trying public url.', array_merge($context, [
                'path' => $path,
                'exception' => $temporaryUrlException,
            ]));
            try {
                $url = $disk->url($path);
            } catch (Throwable $urlException) {
                Log::warning('S3 test upload: preview URL generation failed after successful put.', array_merge($context, [
                    'path' => $path,
                    'temporary_url_error' => $temporaryUrlException->getMessage(),
                    'public_url_error' => $urlException->getMessage(),
                    'exception' => $urlException,
                ], $this->awsExceptionLogContext($urlException)));
            }
        }

        Log::info('S3 test upload succeeded.', array_merge($context, [
            'path' => $path,
            'temporary_url_generated' => $temporaryUrlGenerated,
            'preview_url_generated' => $url !== null,
        ]));

        return redirect()
            ->route('dev.s3-upload-test')
            ->with('success', 'Image uploaded to S3.')
            ->with('s3_path', $path)
            ->with('s3_url', $url);
    }

    /**
     * @return array{environment: string, bucket: mixed, region: mixed, access_key_configured: bool, secret_configured: bool}
     */
    private function s3LogContext(): array
    {
        $config = config('filesystems.disks.s3', []);

        return [
            'environment' => app()->environment(),
            'bucket' => $config['bucket'] ?? null,
            'region' => $config['region'] ?? null,
            'access_key_configured' => filled($config['key'] ?? null),
            'secret_configured' => filled($config['secret'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function awsExceptionLogContext(Throwable $e): array
    {
        $aws = $this->resolveAwsException($e);
        if ($aws === null) {
            return [];
        }

        return [
            'aws_error_code' => $aws->getAwsErrorCode(),
            'aws_error_message' => $aws->getAwsErrorMessage(),
            'aws_request_id' => $aws->getAwsRequestId(),
        ];
    }

    private function resolveAwsException(Throwable $e): ?AwsException
    {
        if ($e instanceof AwsException) {
            return $e;
        }

        $previous = $e->getPrevious();

        return $previous instanceof AwsException ? $previous : null;
    }

    private function userFacingS3FailureMessage(Throwable $e): string
    {
        $aws = $this->resolveAwsException($e);
        if ($aws !== null) {
            $code = $aws->getAwsErrorCode() ?? 'Error';
            $detail = $aws->getAwsErrorMessage() ?: $aws->getMessage();
            $hint = match ($code) {
                'AccessDenied' => 'Check IAM allows s3:PutObject on this bucket and prefix.',
                'InvalidAccessKeyId' => 'Access key is not recognized. Create a new key in IAM for the intended user, update .env, then run php artisan config:clear.',
                'SignatureDoesNotMatch' => 'Secret does not match this access key (wrong pairing, typo, wrapped in quotes, or line break in .env). Paste the secret as one unquoted line, use AWS_USE_PATH_STYLE_ENDPOINT=false and leave AWS_ENDPOINT empty for real S3, sync OS time, run php artisan config:clear, or create a new access key.',
                'NoSuchBucket' => 'Check AWS_BUCKET exists in this account.',
                'PermanentRedirect', 'AuthorizationHeaderMalformed' => 'Check AWS_DEFAULT_REGION matches the bucket region.',
                default => '',
            };

            $message = "S3 error [{$code}]: {$detail}";
            if ($hint !== '') {
                $message .= " {$hint}";
            }

            return $message;
        }

        return 'S3 upload failed: '.$e->getMessage();
    }
}
