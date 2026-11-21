<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

use function PHPStan\dumpType;

static function (UserAuthentication $authentication, SessionLifecycle $sessions, UserId $userId): void {
    $tokens = $authentication->activate($userId, 'credential', 'password');
    dumpType($tokens);
    dumpType($authentication->login('user@example.test', 'password'));
    dumpType($sessions->refresh(str_repeat('a', 64)));
    dumpType($tokens->getUserId());
    dumpType($tokens->getRefreshSessionId());
    dumpType($tokens->getRefreshCredential());
    dumpType($tokens->getRefreshExpiresAt());
    dumpType($tokens->isRemembered());
    dumpType($tokens->getAccessToken());
    dumpType($tokens->getAccessTokenExpiresAt());
    dumpType(RefreshResult::conflict()->getTokenSet());
};
