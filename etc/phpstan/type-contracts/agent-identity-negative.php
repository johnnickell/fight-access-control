<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

static function (
    Agent $agent,
    AgentView $view,
    AgentRepository $repository,
    AgentId $agentId,
    AgentCredentialId $credentialId,
    AgentName $name,
    UserId $userId,
    PermissionId $permissionId,
    DateTimeImmutable $now
): void {
    $repository->getById($credentialId);
    $repository->getByCredentialId($agentId);
    $repository->rename($agentId, $name, static fn(): string => 'not a clock');
    $repository->validatePermissionAssignments([$permissionId]);
    $agent->replacePermissions([$agentId], 1, $now);
    new ReplaceAgentPermissions($userId, $agentId, 1, [$agentId]);
    new AgentPermissionsReplaced($userId, $agentId, [$agentId], 2, $now);
    new AgentView($agentId, $name, AgentState::ACTIVE, $credentialId, 0, 1, [$permissionId]);
    (static function (Agent $required): void {
    })($repository->getById($agentId));
    (static function (DateTimeImmutable $changed): void {
    })($repository->rename($agentId, $name, static fn(): DateTimeImmutable => $now));
    array_map(static fn(PermissionId $id): string => $id->toString(), $view->toArray()['permissions']);
};
