<?php

declare(strict_types=1);

namespace MeldeVerkehr\Config;

final class Config
{
    private array $items = [];

    public function __construct(private readonly string $configPath)
    {
    }

    public function load(): void
    {
        $files = glob(rtrim($this->configPath, '/') . '/*.php') ?: [];

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $value = require $file;

            if (!is_array($value)) {
                throw new \RuntimeException(sprintf('Config file %s must return an array.', $file));
            }

            $this->items[$key] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->items;
    }
}
