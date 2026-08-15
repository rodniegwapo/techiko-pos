<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductPayloadNormalizer
{
    public const DEFAULT_COLOR_HEX = '94a3b8';

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function applyRepresentationAndCostDefaults(array $validated): array
    {
        $type = isset($validated['representation_type'])
            ? trim((string) $validated['representation_type'])
            : '';

        if ($type === '') {
            $validated['representation_type'] = 'color';
            $type = 'color';
        }

        $rep = isset($validated['representation'])
            ? trim((string) $validated['representation'])
            : '';

        if ($type === 'color' && $rep === '') {
            $validated['representation'] = self::DEFAULT_COLOR_HEX;
        }

        if (! array_key_exists('cost', $validated) || $validated['cost'] === '' || $validated['cost'] === null) {
            $validated['cost'] = null;
        }

        return $validated;
    }

    /**
     * Persist an uploaded product image to S3 (product_images disk) and set representation URL.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function applyUploadedRepresentationImage(
        Request $request,
        array $validated,
        ?string $domainSlug,
    ): array {
        unset($validated['representation_image']);

        $type = isset($validated['representation_type'])
            ? trim((string) $validated['representation_type'])
            : '';

        if ($type !== 'image') {
            return $validated;
        }

        if ($request->hasFile('representation_image')) {
            $file = $request->file('representation_image');

            if (! $file->isValid()) {
                throw ValidationException::withMessages([
                    'representation_image' => __('Please upload a valid product image.'),
                ]);
            }

            $pathname = $file->getPathname();
            if ($pathname === '' || ! is_readable($pathname)) {
                throw ValidationException::withMessages([
                    'representation_image' => __('Please upload a valid product image.'),
                ]);
            }

            $folder = 'products/'.($domainSlug ?: 'global');
            $path = $folder.'/'.$file->hashName();

            $contents = file_get_contents($pathname);
            if ($contents === false || $contents === '') {
                throw ValidationException::withMessages([
                    'representation_image' => __('Please upload a valid product image.'),
                ]);
            }

            try {
                ProductImageStorage::put(
                    $path,
                    $contents,
                    $file->getMimeType() ?: null,
                );
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages([
                    'representation_image' => __('Could not store the product image.'),
                ]);
            }

            // Store object key; ProductImageStorage::displayUrl() signs for the browser.
            $validated['representation'] = $path;

            return $validated;
        }

        $existing = isset($validated['representation'])
            ? trim((string) $validated['representation'])
            : '';

        if ($existing !== '') {
            return $validated;
        }

        throw ValidationException::withMessages([
            'representation_image' => __('Please upload a product image.'),
        ]);
    }
}
