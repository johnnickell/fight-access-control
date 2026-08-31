<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a sensitive command failure without preserving its secret inputs.
 */
final readonly class RedactedCommandFailed implements Event
{
    /**
     * Constructs the redacted command-failure event.
     *
     * @param string               $commandClass
     * @param array<string, mixed> $redactedCommandData
     * @param string               $errorMessage
     */
    public function __construct(
        private string $commandClass,
        private array $redactedCommandData,
        private string $errorMessage
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['command_class', 'command_data', 'error_message'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        if (!is_array($data['command_data'])) {
            throw new DomainException('The redacted command data must be an array.');
        }

        return new static(
            (string) $data['command_class'],
            $data['command_data'],
            (string) $data['error_message']
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'command_class' => $this->commandClass,
            'command_data'  => $this->redactedCommandData,
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * Returns the failed command class name.
     */
    public function getCommandClass(): string
    {
        return $this->commandClass;
    }

    /**
     * Returns the caller-supplied non-sensitive command data.
     *
     * @return array<string, mixed>
     */
    public function getRedactedCommandData(): array
    {
        return $this->redactedCommandData;
    }

    /**
     * Returns the original failure message.
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
