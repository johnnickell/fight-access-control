<?php

declare(strict_types=1);

namespace Fight\AccessControl\Tooling\ContractInventory;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;
use stdClass;
use Throwable;

final class Discovery
{
    private const array DECLARATIONS = ['Stmt_Class', 'Stmt_Interface', 'Stmt_Trait', 'Stmt_Enum', 'Stmt_Function'];

    private const array MEMBERS = ['Stmt_ClassMethod', 'Stmt_EnumCase'];

    private const array BOUNDARIES = [
        ...self::DECLARATIONS, ...self::MEMBERS,
        'Stmt_Property', 'PropertyItem', 'Stmt_ClassConst', 'Stmt_Const', 'Const', 'Param', 'PropertyHook',
        'Stmt_Use', 'Stmt_GroupUse', 'Expr_Closure', 'Expr_ArrowFunction', 'ClosureUse', 'Attribute', 'Stmt_TraitUse',
        'Expr_FuncCall', 'Expr_MethodCall', 'Expr_NullsafeMethodCall', 'Expr_StaticCall',
        'Expr_New', 'Expr_PropertyFetch', 'Expr_NullsafePropertyFetch', 'Expr_StaticPropertyFetch'
    ];

    private array $records = [];

    private array $declarations = [];

    public static function pattern(string $pattern): string
    {
        return "\x01\\A(?:".$pattern.")\\z\x01u";
    }

    public function collect(string $root, stdClass $config): array
    {
        $files = $this->files($root, $config->roots);
        $parser = new ParserFactory()->createForHostVersion();
        $traverser = new NodeTraverser(new NameResolver());
        foreach ($files as $path => $hash) {
            try {
                $nodes = $parser->parse(file_get_contents($root.'/'.$path));
                if ($nodes === null) {
                    throw new RuntimeException('Incomplete inventory parse');
                }

                // Keep the parser's normalized JSON node shape, as used by the original inventory.
                $encoded = json_encode($traverser->traverse($nodes), JSON_THROW_ON_ERROR);
                $tree = json_decode($encoded, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                $message = 'PHP AST discovery failed: '.$path.': '.$error->getMessage();
                throw new RuntimeException($message, previous: $error);
            }

            $owners = array_values(array_filter(
                $config->rules,
                static fn (stdClass $rule): bool => self::matches($rule, $path)
            ));
            if (count($owners) !== 1) {
                throw new RuntimeException('Expected one correction owner, found '.count($owners).': '.$path);
            }

            $owner = self::owner($owners[0]);
            $concerns = [];
            $seen = [$owner->task => true];
            foreach ($config->concerns as $rule) {
                if (!self::matches($rule, $path)) {
                    continue;
                }

                if (isset($seen[$rule->task])) {
                    throw new RuntimeException('Duplicate contract concern owner: '.$path);
                }

                $seen[$rule->task] = true;
                $concerns[] = self::owner($rule);
            }

            $record = function (
                string $id,
                string $kind,
                stdClass $contract,
                ?int $line,
                ?string $parent
            ) use (
                $path,
                $hash,
                $owner,
                $concerns
            ): void {
                if (isset($this->records[$id])) {
                    throw new RuntimeException('Duplicate boundary: '.$id);
                }

                $fields = compact('id', 'kind', 'path', 'line', 'parent', 'owner', 'concerns', 'contract');
                $fields['contract_hash'] = InventoryJson::hash($contract);
                $fields['source_hash'] = $hash;
                $this->records[$id] = (object) $fields;
            };
            $fileId = 'file:'.$path;
            $record($fileId, 'file', new stdClass(), 1, null);
            $counters = [];
            $this->walk($tree, $fileId, '', '', $counters, $record);
        }

        if ($files !== $this->files($root, $config->roots)) {
            throw new RuntimeException('Discovery inputs changed during parsing');
        }

        ksort($this->records, SORT_STRING);

        return [(object) $files, array_values($this->records)];
    }

    private static function owner(stdClass $rule): stdClass
    {
        return (object) ['task' => $rule->task, 'ticket' => $rule->ticket];
    }

    private static function matches(stdClass $rule, string $path): bool
    {
        return preg_match(self::pattern($rule->path), $path) === 1
            && preg_match(self::pattern($rule->except ?? '(?!)'), $path) !== 1;
    }

    private static function name(mixed $value): string
    {
        if ($value instanceof stdClass) {
            return self::name(property_exists($value, 'name') ? $value->name : ($value->var ?? 'dynamic'));
        }

        return is_string($value) ? $value : 'anonymous';
    }

    private static function names(array $nodes): string
    {
        return implode(',', array_map(static fn (stdClass $node): string => self::name($node->name), $nodes));
    }

    private static function docs(stdClass $node): array
    {
        $docs = [];
        foreach ($node->attributes->comments ?? [] as $comment) {
            if ($comment->nodeType === 'Comment_Doc') {
                $docs[] = $comment->text;
            }
        }

        return $docs;
    }

    private static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::clean(...), $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        $result = new stdClass();
        foreach ($value as $key => $item) {
            if ($key !== 'attributes' && $key !== 'stmts') {
                $result->$key = self::clean($item);
            }
        }

        $docs = self::docs($value);
        if ($docs !== []) {
            $result->phpdoc = $docs;
        }

        return $result;
    }

    private static function header(stdClass $node): stdClass
    {
        $kind = $node->nodeType;
        $namedHeaders = [...self::DECLARATIONS, ...self::MEMBERS, 'Expr_Closure', 'Expr_ArrowFunction', 'PropertyHook'];
        if (in_array($kind, $namedHeaders, true)) {
            $header = clone $node;
            unset($header->stmts, $header->expr);

            return self::clean($header);
        }

        if (!str_starts_with($kind, 'Expr_')) {
            return self::clean($node);
        }

        // These syntax hints do not resolve dispatch or establish who calls the boundary.
        $header = new stdClass();
        foreach (['nodeType', 'name', 'class'] as $key) {
            if (property_exists($node, $key)) {
                $header->$key = self::clean($node->$key);
            }
        }

        $header->receiver = self::name($node->var ?? null);
        $header->first_class_callable = array_any(
            $node->args ?? [],
            static fn (stdClass $argument): bool => $argument->nodeType === 'VariadicPlaceholder'
        );

        return $header;
    }

    private function files(string $root, array $roots): array
    {
        if ($roots !== ['src', 'openapi']) {
            throw new RuntimeException('Discovery must include exactly src and openapi');
        }

        clearstatcache(true);
        $files = [];
        foreach ($roots as $name) {
            $start = count($files);
            $this->visit($root, $name, $files);
            if (count($files) === $start) {
                throw new RuntimeException('Empty discovery root: '.$name);
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function visit(string $root, string $relative, array &$files): void
    {
        $directory = $root.'/'.$relative;
        if (is_link($directory) || !is_dir($directory)) {
            throw new RuntimeException('Missing, linked or invalid discovery directory: '.$directory);
        }

        $mode = fileperms($directory);
        if (!($mode & 0444) || !($mode & 0111)) {
            throw new RuntimeException('Unreadable discovery directory: '.$directory);
        }

        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.'/'.$name;
            if (is_link($path)) {
                throw new RuntimeException('Linked discovery input: '.$path);
            }

            if (is_dir($path)) {
                $this->visit($root, $relative.'/'.$name, $files);
            } elseif (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'php' && $name !== '.php') {
                if (!is_file($path) || !(fileperms($path) & 0444)) {
                    throw new RuntimeException('Unreadable or special discovery input: '.$path);
                }

                $files[$relative.'/'.$name] = hash_file('sha256', $path);
            }
        }
    }

    private function walk(
        mixed $value,
        string $parent,
        string $namespace,
        string $type,
        array &$counters,
        callable $record
    ): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->walk($item, $parent, $namespace, $type, $counters, $record);
            }

            return;
        }

        if (!$value instanceof stdClass || !isset($value->nodeType)) {
            return;
        }

        $kind = $value->nodeType;
        if ($kind === 'Stmt_Namespace') {
            $namespace = isset($value->name) ? self::name($value->name) : '';
        }

        $id = $parent;
        $docs = self::docs($value);
        $boundary = in_array($kind, self::BOUNDARIES, true);
        if ($boundary || $docs !== []) {
            $declaration = in_array($kind, self::DECLARATIONS, true);
            $member = in_array($kind, self::MEMBERS, true);
            $label = self::name($value->name ?? null);
            if ($declaration && isset($value->name)) {
                $label = ltrim(self::name($value->namespacedName ?? $namespace.'\\'.$label), '\\');
                $id = ($kind === 'Stmt_Function' ? 'function:' : 'type:').$label;
            } elseif ($member) {
                $id = $parent.($kind === 'Stmt_EnumCase' ? '::case:'.$label : '::'.$label.'()');
            } elseif ($kind === 'PropertyItem') {
                $id = $type.'::$'.$label;
            } elseif ($kind === 'Const') {
                $id = ($type === '' ? $namespace : $type).'::constant:'.$label;
            } elseif ($kind === 'Param') {
                $id = $parent.'/param:$'.self::name($value->var);
            } else {
                $label = match (true) {
                    $kind === 'Stmt_Property' => self::names($value->props),
                    $kind === 'Stmt_ClassConst', $kind === 'Stmt_Const' => self::names($value->consts),
                    str_starts_with($kind, 'Expr_') => self::name($value->class ?? $value->name ?? $kind),
                    default => $label,
                };
                $key = $parent.'/'.$kind.':'.$label;
                $counters[$key] = ($counters[$key] ?? 0) + 1;
                $id = $key.'#'.$counters[$key];
            }

            if ($declaration || $member || $kind === 'PropertyItem' || $kind === 'Const') {
                $collision = $declaration || $kind === 'Stmt_ClassMethod' ? mb_strtolower($id, 'UTF-8') : $id;
                if (isset($this->declarations[$collision])) {
                    throw new RuntimeException('Duplicate declaration: '.$id);
                }

                $this->declarations[$collision] = true;
            }

            $contract = $boundary ? self::header($value) : (object) ['nodeType' => $kind, 'phpdoc' => $docs];
            $record($id, $kind, $contract, $value->attributes->startLine ?? null, $parent);
            if ($declaration && $kind !== 'Stmt_Function') {
                $type = $id;
            }
        }

        foreach ($value as $key => $child) {
            if ($key !== 'attributes' && $key !== 'namespacedName') {
                $this->walk($child, $id, $namespace, $type, $counters, $record);
            }
        }
    }
}
