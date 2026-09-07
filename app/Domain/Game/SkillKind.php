<?php

namespace App\Domain\Game;

enum SkillKind: string
{
    case Probe = 'probe';
    case Breach = 'breach';
    case Disrupt = 'disrupt';
    case Gather = 'gather';
    case Ultimate = 'ultimate';

    /**
     * 是否計入連攜鏈。蓄勢與終招中斷連攜（GAME-DESIGN §3.3）。
     */
    public function continuesCombo(): bool
    {
        return $this === self::Probe || $this === self::Breach || $this === self::Disrupt;
    }

    public function isElemental(): bool
    {
        return $this->continuesCombo();
    }
}
