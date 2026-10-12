<?php

declare(strict_types=1);

namespace Wireframe\Builder;

/**
 * Base for every builder node (page, tab, section, field, table column).
 *
 * Keys are stored in the order they are set, so `toArray()` compiles to the
 * same array a hand-written config would contain. Nested nodes compile
 * recursively; anything else passes through untouched.
 */
abstract class Node
{
    /** @var array<string, mixed> Config keys in insertion order. */
    protected array $attributes = [];

    /**
     * The node's `id`, or an empty string when it has none (pages).
     *
     * @return string Node ID.
     */
    public function id(): string
    {
        return (string) ($this->attributes['id'] ?? '');
    }

    /**
     * Read a raw config key.
     *
     * @param string $key     Config key.
     * @param mixed  $default Returned when the key is not set.
     * @return mixed Stored value (nested nodes are returned as objects).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set a raw config key. Low-level escape hatch behind every fluent setter.
     *
     * @param string $key   Config key.
     * @param mixed  $value Value; nested nodes are compiled by `toArray()`.
     * @return static
     */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Compile to the plain config array described by `schema/config.schema.json`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return self::compile($this->attributes);
    }

    /**
     * Recursively compile nested nodes inside a value.
     *
     * @param mixed $value Any config value.
     * @return mixed The value with every Node replaced by its array form.
     */
    protected static function compile(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map([self::class, 'compile'], $value);
        }

        return $value;
    }

    /**
     * Append items to a list-valued key.
     *
     * @param string       $key   Config key holding the list.
     * @param array<mixed> $items Items to append.
     * @return static
     */
    protected function append(string $key, array $items): static
    {
        $this->attributes[$key] = [...($this->attributes[$key] ?? []), ...$items];

        return $this;
    }
}
