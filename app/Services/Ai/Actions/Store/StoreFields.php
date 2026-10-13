<?php

namespace App\Services\Ai\Actions\Store;

use App\Services\Ai\Actions\ActionInputException;

/** A store's form fields as the AI sends them, and how they read on the confirm card. */
class StoreFields
{
    public const KEYS = ['name', 'code', 'type', 'address', 'contact_person', 'phone', 'email'];

    private const LABELS = [
        'name' => 'Name',
        'code' => 'Code',
        'type' => 'Type',
        'address' => 'Address',
        'contact_person' => 'Contact person',
        'phone' => 'Phone',
        'email' => 'Email',
    ];

    /** The given fields, trimmed, without empty ones. */
    public static function normalise(array $input): array
    {
        $data = [];
        foreach (self::KEYS as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $data[$key] = $key === 'code' ? strtoupper($value) : $value;
            }
        }
        if (isset($data['type']) && ! in_array($data['type'], ['store', 'warehouse'], true)) {
            throw new ActionInputException('The type must be store or warehouse.');
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, ?string>  $before  The store's current values, to show "old → new".
     * @return list<array{label: string, value: string}>
     */
    public static function describe(array $data, array $before = []): array
    {
        $fields = [];
        foreach (self::KEYS as $key) {
            if (! isset($data[$key])) {
                continue;
            }
            $old = $before[$key] ?? null;
            $value = $key === 'type' ? ucfirst($data[$key]) : $data[$key];
            $old = $key === 'type' && $old ? ucfirst($old) : $old;
            $fields[] = ['label' => self::LABELS[$key], 'value' => $old && $old !== $value ? $old.' → '.$value : $value];
        }

        return $fields;
    }
}
