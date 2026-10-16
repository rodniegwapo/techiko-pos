<?php

namespace App\Models;

use ArrayObject;
use Illuminate\Database\Eloquent\Model;

/**
 * App-wide settings, one JSON value per key. Each is read from the database once per request.
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $read = self::read();
        if (! $read->offsetExists($key)) {
            $read[$key] = static::query()->find($key)?->value;
        }

        return $read[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::read()->offsetUnset($key);
    }

    /** Values read in this request (held by the app, so each request and test starts empty). */
    private static function read(): ArrayObject
    {
        if (! app()->bound(self::class.'.read')) {
            app()->instance(self::class.'.read', new ArrayObject);
        }

        return app(self::class.'.read');
    }
}
