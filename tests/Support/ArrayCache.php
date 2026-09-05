<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/** Minimal PSR-16 cache double: values in an array, TTLs recorded. */
class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];
    /** @var array<string, mixed> */
    public array $ttls = [];

    public function get($key, $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    public function delete($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key): bool
    {
        return \array_key_exists($key, $this->values);
    }
}
