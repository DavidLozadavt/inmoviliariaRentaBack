<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LyraConfig extends Model
{
    protected $fillable = ['key', 'value', 'type', 'updated_by'];

    /**
     * Get a configuration value.
     */
    public static function getValue(string $key, $default = null)
    {
        $config = static::where('key', $key)->first();
        if (!$config) return $default;

        return match ($config->type) {
            'boolean' => filter_var($config->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $config->value,
            'json'    => json_decode($config->value, true),
            default   => $config->value,
        };
    }

    /**
     * Backward compatibility for get()
     */
    public static function get(string $key, $default = null)
    {
        return static::getValue($key, $default);
    }

    /**
     * Set a configuration value.
     */
    public static function setValue(string $key, $value, string $updatedBy = 'system')
    {
        $type = match (true) {
            is_bool($value)  => 'boolean',
            is_int($value)   => 'integer',
            is_array($value) => 'json',
            default          => 'string',
        };

        return static::updateOrCreate(
            ['key' => $key],
            [
                'value'      => is_array($value) ? json_encode($value) : (string) $value,
                'type'       => $type,
                'updated_by' => $updatedBy,
            ]
        );
    }

    /**
     * Backward compatibility for set()
     */
    public static function set(string $key, $value)
    {
        return static::setValue($key, $value);
    }
}
