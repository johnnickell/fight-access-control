<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant;

/**
 * Enum EmailChangeTransitionStage
 */
enum EmailChangeTransitionStage: string
{
    case REQUESTED = 'requested';
    case CONFIRMED = 'confirmed';
}
