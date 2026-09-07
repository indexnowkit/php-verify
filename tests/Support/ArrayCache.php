<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/** Minimal PSR-16 cache double: values in an array, TTLs recorded. Docblocks typed for psr/simple-cache 1.0, which carries no native types. */
class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];
    /** @var array<string, mixed> */
    public array $ttls = [];

    /**
     * @param string $key
     */
    public function get($key, $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @param string                $key
     * @param int|DateInterval|null $ttl
     */
    public function set($key, $value, $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    /**
     * @param string $key
     */
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

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<string, mixed> $values
     * @param int|DateInterval|null   $ttl
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * @param string $key
     */
    public function has($key): bool
    {
        return \array_key_exists($key, $this->values);
    }
}
