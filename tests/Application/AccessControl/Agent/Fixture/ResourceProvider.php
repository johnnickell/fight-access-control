<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Fixture;

use Closure;
use Fight\Common\Application\Mcp\Resource\McpProtectedResourceProvider;
use Fight\Common\Application\Mcp\Resource\McpResourceContent;
use Fight\Common\Application\Mcp\Resource\McpResourceInfo;

final class ResourceProvider implements McpProtectedResourceProvider
{
    /** @var list<string> */
    public array $opened = [];

    /** @var list<string> */
    public array $lookedUp = [];

    public int $enumerations = 0;

    public bool $allowed = true;

    public ?Closure $lookup = null;

    public ?Closure $policy = null;

    /**
     * @param array<string, array<string, mixed>> $metadata
     * @param list<string>                       $unlisted
     */
    public function __construct(
        public array $metadata,
        private readonly Closure $openContent,
        public array $unlisted = []
    ) {
    }

    public function resources(): iterable
    {
        ++$this->enumerations;
        $metadata = $this->metadata;
        ksort($metadata, SORT_STRING);
        foreach ($metadata as $uri => $entry) {
            if (!in_array($uri, $this->unlisted, true)) {
                yield McpResourceInfo::fromArray($entry);
            }
        }
    }

    public function find(string $uri): ?McpResourceInfo
    {
        $this->lookedUp[] = $uri;
        if ($this->lookup !== null) {
            return ($this->lookup)($uri);
        }

        return isset($this->metadata[$uri]) ? McpResourceInfo::fromArray($this->metadata[$uri]) : null;
    }

    public function isAvailable(McpResourceInfo $resource): bool
    {
        return $this->policy === null ? $this->allowed : ($this->policy)($resource);
    }

    public function open(McpResourceInfo $resource): McpResourceContent
    {
        $this->opened[] = $resource->uri();

        return ($this->openContent)($resource);
    }
}
