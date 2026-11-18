<?php

declare(strict_types=1);

use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

static function (SessionLifecycle $sessions, RefreshResult $result, TokenSet $tokens): void {
    (static fn(TokenSet $tokens): TokenSet => $tokens)($result->getTokenSet());
    $sessions->refresh($tokens->getRefreshCredential());
    (static fn(UserId $userId): UserId => $userId)($tokens->getRefreshSessionId());
    $tokens = $sessions->logout('credential');
    var_dump($tokens);
};
