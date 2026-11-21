<?php

declare(strict_types=1);

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\GrantPermissionToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ReplaceAgentPermissions;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RevokePermissionFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentNameChanged;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentPermissionsReplaced;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionGrantedToAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\PermissionRevokedFromAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentProfileView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentById;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgents;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Test\AccessControl\Domain\AccessControl\Agent\ExtensibleAgent;

use function PHPStan\dumpType;

static function (
    ExtensibleAgent $agent,
    AgentRepository $repository,
    AgentId $agentId,
    AgentCredentialId $credentialId,
    AgentName $name,
    PermissionId $permissionId,
    Pagination $pagination,
    AuthenticatedAgentPrincipal $principal,
    AgentUpdateInitiator $initiator,
    AgentView $view,
    AgentProfileView $profile,
    GrantPermissionToAgent $grant,
    RevokePermissionFromAgent $revoke,
    ReplaceAgentPermissions $replace,
    UpdateAgent $update,
    AgentNameChanged $renamed,
    AgentPermissionsReplaced $replaced,
    PermissionGrantedToAgent $granted,
    PermissionRevokedFromAgent $revoked,
    GetAgentById $get,
    GetAgentProfile $getProfile,
    ListAgents $list,
    DateTimeImmutable $now
): void {
    dumpType($agent->getPermissionIds());
    dumpType($repository->getById($agentId));
    dumpType($repository->getByCredentialId($credentialId));
    dumpType($repository->getAll($pagination));
    dumpType($repository->rename($agentId, $name, static fn(): DateTimeImmutable => $now));
    dumpType($principal->getPermissions());
    dumpType($principal->toArray());
    dumpType($initiator->getId());
    dumpType($initiator->toArray());
    dumpType($view->getPermissions());
    dumpType($view->toArray());
    dumpType($profile->toArray());
    dumpType($grant->toArray());
    dumpType($revoke->toArray());
    dumpType($replace->toArray());
    dumpType($update->toArray());
    dumpType($renamed->toArray());
    dumpType($replaced->toArray());
    dumpType($granted->toArray());
    dumpType($revoked->toArray());
    dumpType($get->toArray());
    dumpType($getProfile->toArray());
    dumpType($list->toArray());
    dumpType(array_map(static fn(PermissionId $id): string => $id->toString(), $agent->getPermissionIds()));
    dumpType($agent->rename($name, $now));
    dumpType($agent->grantPermission($permissionId, $now));
    dumpType($agent->revokePermission($permissionId, $now));
    dumpType($agent->replacePermissions([], 1, $now));
    dumpType($agent->rotateRecoverableCredential($credentialId, 0, $credentialId, 'envelope', $now));
    dumpType($agent->revoke($now));
    dumpType(ExtensibleAgent::provision($agentId, $name, $credentialId, 'envelope', $now));
};
