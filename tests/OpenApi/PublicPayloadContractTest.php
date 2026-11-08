<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureStatus;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicyChangeAction;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\RevokeSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Query\Query;
use OpenApi\Generator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

#[CoversNothing]
final class PublicPayloadContractTest extends TestCase
{
    private static ?stdClass $document = null;

    public function test_every_current_public_message_has_an_executable_sample(): void
    {
        $actual = [];
        foreach (glob(dirname(__DIR__, 2).'/src/Domain/AccessControl/*/{Command,Query}/*.php', GLOB_BRACE) as $path) {
            $class = 'Fight\\AccessControl\\Domain\\AccessControl\\'.str_replace(
                '/',
                '\\',
                substr($path, strlen(dirname(__DIR__, 2).'/src/Domain/AccessControl/'), -4)
            );
            if (is_a($class, Command::class, true) || is_a($class, Query::class, true)) {
                $actual[] = $class;
            }
        }

        $sampled = array_column(iterator_to_array(PublicMessageSamples::messages()), 0);
        self::assertEqualsCanonicalizing($actual, $sampled);
    }

    /**
     * @param class-string<Command|Query> $class
     * @param array<string, mixed> $input
     */
    #[DataProviderExternal(PublicMessageSamples::class, 'messages')]
    public function test_canonical_message_round_trips_validate_against_generated_components(string $class, array $input): void
    {
        $payload = $class::fromArray($input)->toArray();
        self::assertSame($payload, $class::fromArray($payload)->toArray());
        $name = new ReflectionClass($class)->getShortName();
        self::assertTrue(self::valid($name, $payload), $name);
        $schema = self::schema($name);
        if (isset($schema->{'$ref'})) {
            $schema = self::schema(basename($schema->{'$ref'}));
        }

        self::assertEqualsCanonicalizing(array_keys($payload), $schema->required ?? [], $name.' required');
        self::assertEqualsCanonicalizing(array_keys($payload), array_keys((array) ($schema->properties ?? [])), $name);
        foreach (array_keys($payload) as $field) {
            $invalid = $payload;
            unset($invalid[$field]);
            self::assertFalse(self::valid($name, (object) $invalid), $name.' missing '.$field);
        }
    }

    public function test_native_empty_orderings_and_empty_query_serialization_are_supported(): void
    {
        foreach (PublicMessageSamples::messages() as $name => [$class, $input]) {
            if (!array_key_exists('orderings', $input)) {
                continue;
            }

            $input['orderings'] = [];
            self::assertTrue(self::valid($name, $class::fromArray($input)->toArray()), $name);
            $input['orderings'] = ['name' => 'sideways'];
            self::assertFalse(self::valid($name, $input), $name);
            $input['orderings'] = ['ASC'];
            self::assertFalse(self::valid($name, $input), $name);
        }
    }

    public function test_revocation_optional_reason_is_required_nullable_and_canonically_bounded(): void
    {
        $class = RevokeSession::class;
        $input = [
            'actor_id'           => PublicMessageSamples::ID,
            'current_session_id' => PublicMessageSamples::ID,
            'target_session_id'  => PublicMessageSamples::ID
        ];
        foreach ([null, '  Operator requested  ', str_repeat('é', 500)] as $reason) {
            $input['reason'] = $reason;
            self::assertTrue(self::valid('RevokeSession', $class::fromArray($input)->toArray()));
        }

        foreach (['', str_repeat('é', 501), 1] as $reason) {
            $input['reason'] = $reason;
            self::assertFalse(self::valid('RevokeSession', $input));
        }
    }

    public function test_generated_catalog_resolves_all_references_without_transport_or_internal_schemas(): void
    {
        $document = self::document();
        self::assertSame('3.1.0', $document->openapi);
        self::assertFalse(isset($document->paths));
        self::assertFalse(isset($document->components->securitySchemes));
        $walk = function (mixed $value) use (&$walk, $document): void {
            if (is_object($value) || is_array($value)) {
                foreach ($value as $key => $child) {
                    if ($key === '$ref') {
                        self::assertStringStartsWith('#/components/schemas/', $child);
                        self::assertTrue(isset($document->components->schemas->{substr($child, 21)}), $child);
                    } else {
                        $walk($child);
                    }
                }
            }
        };
        $walk($document);
        foreach (
            ['TokenSet', 'RefreshResult', 'CredentialDeliveryInvocation', 'AgentDeliveryWorkView',
            'AgentDeliveryMaterial', 'AgentCredentialOperation', 'Failure', 'Error'] as $internal
        ) {
            self::assertFalse(isset($document->components->schemas->{'Fight.AccessControl.'.$internal}), $internal);
        }
    }

    #[DataProviderExternal(PublicValueSamples::class, 'values')]
    public function test_real_safe_values_and_typed_collections_validate(string $name, mixed $payload): void
    {
        self::assertTrue(self::valid($name, $payload), $name.' '.json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertFalse(self::valid($name, 'not a public value'), $name);
        if (is_array($payload) && !array_is_list($payload)) {
            foreach (array_keys($payload) as $field) {
                $invalid = $payload;
                unset($invalid[$field]);
                self::assertFalse(self::valid($name, (object) $invalid), $name.' missing '.$field);
            }
        }
    }

    public function test_optional_status_and_operation_branches_reject_invented_states(): void
    {
        foreach (['CredentialDeliveryStatusResult', 'InvitationDeliveryStatusResult'] as $name) {
            foreach ([null, [], ['state' => 'missing'], ['status' => 'absent']] as $invalid) {
                self::assertFalse(self::valid($name, $invalid), $name);
            }
        }

        foreach (PublicValueSamples::values() as [$name, $payload]) {
            if ($name === 'AgentOperation') {
                $payload['issuance_outcome'] = 'success';
                self::assertFalse(self::valid($name, $payload));
                $payload['issuance_outcome'] = 'indeterminate';
                $payload['canonical_version'] = 2;
                self::assertFalse(self::valid($name, $payload));
            }
        }
    }

    public function test_ids_timestamps_enums_numeric_bounds_and_typed_elements_reject_invalid_samples(): void
    {
        foreach (PublicMessageSamples::messages() as $name => [$class, $input]) {
            $payload = $class::fromArray($input)->toArray();
            foreach ($payload as $field => $value) {
                if (str_ends_with($field, '_id') || in_array($field, ['occurred_at', 'at', 'status', 'purpose'], true)) {
                    $invalid = $payload;
                    $invalid[$field] = 'not-valid';
                    // actor/caller strings intentionally carry arbitrary worker provenance.
                    if (in_array($field, ['actor_id', 'caller_id'], true)) {
                        continue;
                    }

                    self::assertFalse(self::valid($name, $invalid), $name.' '.$field);
                }

                if ($field === 'limit' && $name !== 'FindDueCredentialDeliveries') {
                    $invalid = $payload;
                    $invalid[$field] = 101;
                    self::assertFalse(self::valid($name, $invalid), $name.' limit maximum');
                }

                if (is_int($value) && !in_array($field, ['page', 'per_page'], true)) {
                    $invalid = $payload;
                    $invalid[$field] = -1;
                    self::assertFalse(self::valid($name, $invalid), $name.' '.$field);
                }
            }
        }

        foreach (PublicValueSamples::values() as [$name, $payload]) {
            if (str_ends_with($name, 'Collection')) {
                $payload['records'] = [['unrelated_id' => PublicMessageSamples::ID]];
                self::assertFalse(self::valid($name, $payload), $name);
            }

            if (in_array($name, ['DueAgentDeliveries', 'AgentDeliveryMaintenance'], true) && count($payload) === 100) {
                $payload[] = $payload[0];
                self::assertFalse(self::valid($name, $payload), $name);
            }
        }
    }

    public function test_seven_synchronous_payloads_and_browser_portable_profiles_keep_secrets_separate(): void
    {
        $id = PublicMessageSamples::ID;
        $requests = [
            'Activate'       => ['user_id' => $id, 'activation_credential' => 'test-only', 'plain_password' => 'test-only'],
            'Login'          => ['email' => 'operator@example.test', 'plain_password' => 'test-only'],
            'Refresh'        => ['refresh_credential' => 'test-only'],
            'Logout'         => ['refresh_credential' => 'test-only'],
            'ResetPassword'  => ['user_id' => $id, 'reset_credential' => 'test-only', 'plain_password' => 'test-only'],
            'ChangePassword' => ['user_id' => $id, 'current_password' => 'test-only', 'new_password' => 'test-only'],
            'ConfirmEmail'   => ['user_id' => $id, 'email_change_credential' => 'test-only']
        ];
        foreach ($requests as $operation => $payload) {
            $name = 'Authentication.'.$operation.'Request';
            self::assertTrue(self::valid($name, $payload));
            foreach ($payload as $field => $value) {
                $property = self::schema($name)->properties->{$field};
                if (str_contains($field, 'password') || str_contains($field, 'credential')) {
                    self::assertTrue($property->writeOnly);
                    self::assertFalse(isset($property->example));
                    self::assertFalse(isset($property->examples));
                }

                $invalid = $payload;
                unset($invalid[$field]);
                self::assertFalse(self::valid($name, (object) $invalid), $name.' missing '.$field);
            }
        }

        foreach (['Activate', 'Login'] as $operation) {
            $name = 'Authentication.'.$operation.'Request';
            $payload = $requests[$operation] + ['remember' => true];
            self::assertTrue(self::valid($name, $payload));
            $payload['remember'] = 'true';
            self::assertFalse(self::valid($name, $payload));
        }

        $tokens = new TokenSet(
            UserId::fromString($id),
            RefreshSessionId::fromString($id),
            RefreshCredential::fromString(str_repeat('a', 64)),
            new DateTimeImmutable(PublicMessageSamples::TIME),
            false,
            AccessToken::fromString('test-only-access'),
            new DateTimeImmutable(PublicMessageSamples::TIME)
        );
        // Consumer-owned explicit transport projection; TokenSet itself is intentionally not serializable.
        $browser = [
            'user_id'                 => $tokens->getUserId()->toString(),
            'refresh_session_id'      => $tokens->getRefreshSessionId()->toString(),
            'access_token'            => $tokens->getAccessToken()->toString(),
            'access_token_expires_at' => $tokens->getAccessTokenExpiresAt()->format(DATE_ATOM),
            'refresh_expires_at'      => $tokens->getRefreshExpiresAt()->format(DATE_ATOM),
            'remembered'              => $tokens->isRemembered()
        ];
        self::assertTrue(self::valid('Authentication.BrowserResponse', $browser));
        self::assertFalse(self::valid('Authentication.TokenSet', $browser));
        $portable = $browser + ['refresh_token' => $tokens->getRefreshCredential()->toString()];
        self::assertTrue(self::valid('Authentication.TokenSet', $portable));
        self::assertFalse(self::valid('Authentication.BrowserResponse', $portable));
        self::assertTrue(self::valid(
            'JSend.Success.Authentication.BrowserResponse',
            ['status' => 'success', 'data' => $browser]
        ));
        self::assertTrue(self::valid(
            'JSend.Success.Authentication.TokenSet',
            ['status' => 'success', 'data' => $portable]
        ));
        self::assertFalse(self::valid(
            'JSend.Success.Authentication.BrowserResponse',
            ['status' => 'success', 'data' => $portable]
        ));
        self::assertTrue(self::valid(
            'JSend.Success.Authentication.RefreshConflict',
            ['status' => 'success', 'data' => ['outcome' => 'conflict']]
        ));
        self::assertFalse(isset(self::schema('Authentication.TokenSet')->properties->refresh_token->example));
        self::assertTrue(self::valid('Authentication.RefreshConflict', ['outcome' => 'conflict']));
        self::assertTrue(self::valid('JSend.Success.Empty', ['status' => 'success', 'data' => null]));
        self::assertFalse(self::valid('JSend.Success.Empty', ['status' => 'success', 'data' => []]));
    }

    public function test_found_feature_binding_and_expired_work_keep_their_real_optional_relationships(): void
    {
        foreach (PublicValueSamples::values() as [$name, $payload]) {
            if ($name === 'Feature') {
                $payload['binding_missing'] = !$payload['binding_missing'];
                self::assertFalse(self::valid($name, $payload));
            }

            if ($name === 'ExpiredCredentialDelivery') {
                $payload['email_change_grant_id'] = $payload['email_change_grant_id'] === null ? PublicMessageSamples::ID : null;
                self::assertFalse(self::valid($name, $payload));
            }
        }
    }

    public function test_raw_names_and_exact_opaque_scope_boundaries_do_not_confuse_canonical_constraints(): void
    {
        foreach (PublicMessageSamples::messages() as $name => [$class, $input]) {
            if (in_array($name, ['ProvisionAgent', 'UpdateAgent'], true)) {
                $input['name'] = str_repeat(' ', 150).'Operator'.str_repeat(' ', 150);
                self::assertTrue(self::valid($name, $class::fromArray($input)->toArray()));
            }

            foreach (['namespace', 'caller_type', 'caller_id', 'key_version'] as $field) {
                if (isset($input[$field])) {
                    $invalid = $input;
                    $invalid[$field] .= "\n";
                    self::assertFalse(self::valid($name, $invalid), $name.' '.$field.' trailing newline');
                }
            }
        }
    }

    public function test_core_enums_match_exact_current_backed_values(): void
    {
        $enums = [
            [UserState::class, 'User', 'state'],
            [AgentState::class, 'Agent', 'state'],
            [PermissionTier::class, 'Permission', 'tier'],
            [FeatureStatus::class, 'Feature', 'status'],
            [ManagedPolicyChangeAction::class,
                'ManagedPolicy.PermissionPlanItem', 'action']
        ];
        foreach ($enums as [$class, $name, $field]) {
            self::assertSame(array_column($class::cases(), 'value'), self::schema($name)->properties->{$field}->enum);
        }
    }

    public function test_canonical_names_reject_malformed_values_without_constraining_raw_agent_inputs(): void
    {
        foreach (PublicValueSamples::values() as [$name, $payload]) {
            if (
                in_array($name, ['Permission', 'Role', 'PrincipalPermission',
                'ManagedPolicy.PermissionDefinition', 'ManagedPolicy.RoleDefinition', 'Feature'], true)
            ) {
                $invalid = $payload;
                $invalid['name'] = 'not a canonical name';
                self::assertFalse(self::valid($name, $invalid), $name);
            }

            if (in_array($name, ['Agent', 'AgentProfile'], true)) {
                $payload['name'] = str_repeat('é', 121);
                self::assertFalse(self::valid($name, $payload), $name);
            }

            if ($name === 'Feature' && $payload['permission_name'] !== null) {
                $payload['permission_name'] = 'not a canonical name';
                self::assertFalse(self::valid($name, $payload));
            }
        }

        foreach (PublicMessageSamples::messages() as $name => [, $payload]) {
            if (in_array($name, ['CreateCustomRole', 'RenameCustomRole', 'CreateFeature'], true)) {
                $payload['name'] = 'not a canonical name';
                self::assertFalse(self::valid($name, $payload), $name);
            }
        }
    }

    public function test_every_optional_resource_success_envelope_composes_real_values(): void
    {
        $samples = ['CreatedResource' => ['id' => PublicMessageSamples::ID]];
        foreach (PublicValueSamples::values() as [$name, $payload]) {
            $samples[$name] = $payload;
        }

        foreach (self::document()->components->schemas as $name => $schema) {
            if (
                !str_starts_with($name, 'Fight.AccessControl.JSend.Success.')
                || str_contains($name, '.Authentication.') || str_ends_with($name, '.Empty')
            ) {
                continue;
            }

            $dataName = substr($schema->properties->data->{'$ref'}, strlen('#/components/schemas/Fight.AccessControl.'));
            self::assertArrayHasKey($dataName, $samples);
            $name = substr($name, strlen('Fight.AccessControl.'));
            self::assertTrue(self::valid($name, ['status' => 'success', 'data' => $samples[$dataName]]), $name);
            self::assertFalse(self::valid($name, ['status' => 'error', 'data' => $samples[$dataName]]), $name);
            self::assertFalse(self::valid($name, ['status' => 'success', 'data' => 'invalid']), $name);
        }
    }

    private static function document(): stdClass
    {
        if (self::$document === null) {
            require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
            require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
            $document = new Generator()->generate([
                dirname(__DIR__, 2).'/openapi', __DIR__.'/CredentialDeliveryConsumerDocument.php'
            ], null, false);
            self::assertNotNull($document);
            self::$document = json_decode($document->toJson(), flags: JSON_THROW_ON_ERROR);
        }

        return self::$document;
    }

    private static function schema(string $name): stdClass
    {
        $schemas = self::document()->components->schemas;
        $name = str_starts_with($name, 'Fight.AccessControl.') ? $name : 'Fight.AccessControl.'.$name;
        self::assertTrue(isset($schemas->{$name}), 'Missing component '.$name);

        return $schemas->{$name};
    }

    private static function valid(string $name, mixed $payload): bool
    {
        self::schema($name);
        $schema = (object) [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            '$ref'       => '#/components/schemas/Fight.AccessControl.'.$name,
            'components' => self::document()->components
        ];
        $validator = new Validator();
        $validator->parser()->setOption('allowDefaults', false);
        $result = $validator->validate(json_decode(json_encode($payload, JSON_THROW_ON_ERROR)), $schema);
        if ($result->hasError()) {
            // Keep validation diagnostics available without changing the assertion's expected positive/negative result.
            self::assertNotEmpty(new ErrorFormatter()->format($result->error()));
        }

        return $result->isValid();
    }
}
