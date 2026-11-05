<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Support;

use ReflectionClass;

/**
 * Base class for type-safe DTOs mapped from raw PostgREST rows. Extend this
 * and declare typed public properties matching your table's columns; extra
 * columns not declared as properties are silently ignored.
 *
 * Used via PostgrestQueryBuilder::getAs()/singleAs() instead of working
 * with raw arrays.
 */
abstract class SupabaseResource
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): static
    {
        $instance = new static;

        foreach ((new ReflectionClass($instance))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (array_key_exists($property->getName(), $attributes)) {
                $instance->{$property->getName()} = $attributes[$property->getName()];
            }
        }

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = [];

        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            if (! $property->isStatic() && $property->isInitialized($this)) {
                $values[$property->getName()] = $property->getValue($this);
            }
        }

        return $values;
    }
}
