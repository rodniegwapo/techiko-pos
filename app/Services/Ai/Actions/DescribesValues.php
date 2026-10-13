<?php

namespace App\Services\Ai\Actions;

/** Formats values for the confirm card the way the rest of the POS shows them. */
trait DescribesValues
{
    protected function peso(mixed $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }

    protected function onOff(mixed $value): string
    {
        return $value ? 'On' : 'Off';
    }

    /** "old → new", or just the new value when there was none or it is unchanged. */
    protected function change(?string $old, string $new): string
    {
        return $old === null || $old === '' || $old === $new ? $new : $old.' → '.$new;
    }

    /** The given keys of the input that have a value (0 and false count). */
    protected function filled(array $input, array $keys): array
    {
        return array_filter(
            array_intersect_key($input, array_flip($keys)),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
