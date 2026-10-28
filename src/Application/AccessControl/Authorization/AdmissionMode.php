<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization;

/**
 * Enum AdmissionMode
 */
enum AdmissionMode
{
    case PUBLIC;
    case AUTHENTICATED;
    case PROTECTED;
}
