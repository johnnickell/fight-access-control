<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Admission;

/**
 * Enum AdmissionMode
 */
enum AdmissionMode
{
    case PUBLIC;
    case AUTHENTICATED;
    case PROTECTED;
}
