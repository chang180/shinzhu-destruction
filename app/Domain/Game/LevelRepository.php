<?php

namespace App\Domain\Game;

use InvalidArgumentException;

class LevelRepository
{
    /** @var array<string, LevelDefinition> */
    private array $levels = [];

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $id => $definition) {
            $this->levels[$id] = LevelDefinition::fromConfig($id, $definition);
        }

        uasort(
            $this->levels,
            static fn (LevelDefinition $a, LevelDefinition $b): int => $a->sequence <=> $b->sequence,
        );
    }

    public function has(string $levelId): bool
    {
        return array_key_exists($levelId, $this->levels);
    }

    public function get(string $levelId): LevelDefinition
    {
        if (! $this->has($levelId)) {
            throw new InvalidArgumentException("未知的關卡：{$levelId}");
        }

        return $this->levels[$levelId];
    }

    /**
     * @return array<string, LevelDefinition>
     */
    public function all(): array
    {
        return $this->levels;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->levels);
    }
}
