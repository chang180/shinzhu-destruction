<?php

namespace App\Domain\Game;

use InvalidArgumentException;

/**
 * 由 config/game.php 展開的技能池。三系各有試探／破陣／擾序，另有蓄勢與終招，
 * 共 11 個穩定代碼。
 */
class SkillCatalog
{
    /** @var array<string, Skill> */
    private array $skills = [];

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $definition) {
            $kind = SkillKind::from($definition['kind']);

            if ($kind->isElemental()) {
                foreach (Element::all() as $element) {
                    $skill = Skill::fromConfig($definition, $element);
                    $this->skills[$skill->id] = $skill;
                }

                continue;
            }

            $skill = Skill::fromConfig($definition, null);
            $this->skills[$skill->id] = $skill;
        }
    }

    public function has(string $skillId): bool
    {
        return array_key_exists($skillId, $this->skills);
    }

    public function get(string $skillId): Skill
    {
        if (! $this->has($skillId)) {
            throw new InvalidArgumentException("未知的技能代碼：{$skillId}");
        }

        return $this->skills[$skillId];
    }

    /**
     * @return array<string, Skill>
     */
    public function all(): array
    {
        return $this->skills;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->skills);
    }
}
