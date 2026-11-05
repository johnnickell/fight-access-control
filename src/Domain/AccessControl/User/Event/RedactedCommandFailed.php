<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class RedactedCommandFailed
 *
 * Records a sensitive command failure without preserving its secret inputs.
 */
final readonly class RedactedCommandFailed implements Event
{
    /** @var array<string, mixed> */
    private array $redactedCommandData;

    /**
     * Constructs RedactedCommandFailed
     *
     * Constructs the redacted command-failure event.
     *
     * Validates named context fields without pretending that dynamic values are all strings.
     * Producers remain responsible for allowlisting secret-free content.
     *
     * @phpstan-param array<string, mixed> $redactedCommandData
     */
    public function __construct(
        private string $commandClass,
        array $redactedCommandData,
        private string $errorMessage
    ) {
        $this->redactedCommandData = self::validatedContext($redactedCommandData);
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

        if (!is_string($data['command_class']) || !is_string($data['error_message'])) {
            throw new DomainException('Failure identity and message must be strings.');
        }

        return new static(
            $data['command_class'],
            self::validatedContext($data['command_data']),
            $data['error_message']
        );
    }

    /**
     * Returns the canonical redacted failure payload
     *
     * @phpstan-return array{command_class: string, command_data: array<string, mixed>, error_message: string}
     */
    public function toArray(): array
    {
        return [
            'command_class' => $this->commandClass,
            'command_data'  => $this->redactedCommandData,
            'error_message' => $this->errorMessage
        ];
    }

    /**
     * Returns the failed command class name
     */
    public function getCommandClass(): string
    {
        return $this->commandClass;
    }

    /**
     * Returns the caller-supplied non-sensitive command data
     *
     * @return array<string, mixed>
     */
    public function getRedactedCommandData(): array
    {
        return $this->redactedCommandData;
    }

    /**
     * Returns the safe operation failure message
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Validates native context keys while preserving genuinely dynamic safe values
     *
     * @phpstan-param array<array-key, mixed> $context
     * @phpstan-return array<string, mixed>
     */
    private static function validatedContext(array $context): array
    {
        $validatedContext = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                throw new DomainException('Redacted command context requires named fields.');
            }

            $validatedContext[$key] = $value;
        }

        return $validatedContext;
    }
}
