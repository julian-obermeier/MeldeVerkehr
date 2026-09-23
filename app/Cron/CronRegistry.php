<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cron;

final class CronRegistry
{
    /** @var array<string, callable> */
    private array $tasks = [];

    public function register(string $name, callable $task): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Cron task name must not be empty.');
        }

        $this->tasks[$name] = $task;
    }

    public function has(string $name): bool
    {
        return isset($this->tasks[$name]);
    }

    public function run(string $name): array
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException('Unknown cron task: ' . $name);
        }

        $result = ($this->tasks[$name])();

        if (!is_array($result)) {
            return ['processed' => 0, 'errors' => 0, 'message' => null];
        }

        return [
            'processed' => (int) ($result['processed'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'message' => isset($result['message']) ? (string) $result['message'] : null,
        ];
    }

    public function names(): array
    {
        return array_keys($this->tasks);
    }
}
