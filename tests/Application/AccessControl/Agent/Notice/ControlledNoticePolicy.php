<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Notice;

use Closure;
use Fight\AccessControl\Application\AccessControl\Agent\Notice\AgentNoticePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Notice\Notice;
use Fight\Common\Domain\Mcp\Notice\NoticeRemovalSelection;

final class ControlledNoticePolicy implements AgentNoticePolicy
{
    public bool $recording = true;

    public bool $removal = true;

    public ?string $deniedRetainedId = null;

    public ?Closure $beforePolicy = null;

    /** @var list<string> */
    public array $calls = [];

    public function allowsRecording(AuthenticatedAgentPrincipal $principal, Notice $notice): bool
    {
        $this->calls[] = 'record:'.$notice->id();
        ($this->beforePolicy ?? static function (): void {
        })();

        return $this->recording && $notice->producer() === 'producer' && $notice->scope() === 'project'
            && $notice->metadata()->toArray() === ['safe/state' => 'ready']
            && $notice->relationships()->toArray() === [
                'subject'    => 'subject',
                'recipients' => ['recipient'],
                'author'     => 'author',
                'command'    => 'command',
                'event'      => 'event',
                'action'     => 'action'
            ] && $notice->toArray()['topic'] === 'updates'
            && in_array($notice->toArray()['type'], ['changed', 'other'], true);
    }

    public function allowsRemoval(AuthenticatedAgentPrincipal $principal, NoticeRemovalSelection $selection): bool
    {
        $this->calls[] = 'selection';
        ($this->beforePolicy ?? static function (): void {
        })();
        foreach ($selection->targets() as $target) {
            if ($target['scope'] !== 'project' || $target['producer'] !== 'producer') {
                return false;
            }
        }

        return $this->removal;
    }

    public function allowsRetainedRemoval(AuthenticatedAgentPrincipal $principal, Notice $notice): bool
    {
        $this->calls[] = 'retained:'.$notice->id();
        ($this->beforePolicy ?? static function (): void {
        })();

        return $this->removal && $notice->id() !== $this->deniedRetainedId;
    }
}
