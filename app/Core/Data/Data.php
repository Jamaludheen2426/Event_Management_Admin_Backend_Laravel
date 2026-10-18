<?php

declare(strict_types=1);

namespace App\Core\Data;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base for typed input objects that Actions receive ("CreateEventData").
 *
 * A FormRequest validates, then builds one of these with from($validated).
 * Keys arrive in snake_case (the API's language) and map onto camelCase
 * constructor parameters. A missing optional parameter keeps its default.
 *
 * For partial updates (PATCH-like PUTs where only sent fields change), pass the
 * validated array itself to the Action instead — it already contains only the
 * keys the caller sent.
 *
 * @phpstan-consistent-constructor
 */
abstract class Data
{
    /** @param array<string, mixed> $attributes */
    public static function from(array $attributes): static
    {
        $constructor = (new ReflectionClass(static::class))->getConstructor();
        $arguments = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $snake = Str::snake($name);

            if (array_key_exists($snake, $attributes)) {
                $arguments[$name] = $attributes[$snake];
            } elseif (array_key_exists($name, $attributes)) {
                $arguments[$name] = $attributes[$name];
            } elseif (! $parameter->isDefaultValueAvailable()) {
                $arguments[$name] = null;
            }
        }

        return new static(...$arguments);
    }

    /** @return array<string, mixed> snake_case keys, ready for Eloquent */
    public function toArray(): array
    {
        $out = [];
        foreach ((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $out[Str::snake($property->getName())] = $property->getValue($this);
        }

        return $out;
    }
}
