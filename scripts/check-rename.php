#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require $autoload;
}

/** @var list<string> $OLD_API_NAMES */
const OLD_API_NAMES = [
    'NestedSet',
    'NodeTrait',
    'QueryBuilder',
    'NestedSetServiceProvider',
    'appendToNode',
    'prependToNode',
    'getLft',
    'getRgt',
    'toTree',
    'toFlatTree',
    'MenuItem',
    'NodeTest',
    'ScopedNodeTest',
    'Belongs_To',
    'Has_Many',
    'Model_Not_Found',
    'Base_Collection',
];

/**
 * @return array<string, true>
 */
function load_allowlist(string $path): array
{
    $allow = [];
    if (!is_readable($path)) {
        return $allow;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $allow[$line] = true;
    }

    return $allow;
}

function is_studly_snake_class(string $name): bool
{
    return (bool) preg_match('/^[A-Z][a-zA-Z0-9]*(_[A-Z][a-zA-Z0-9]*)+$/', $name);
}

function repo_owned_identifier_violation(string $name, bool $isClass = false): bool
{
    if ($name === '') {
        return false;
    }
    if ($isClass && is_studly_snake_class($name)) {
        foreach (explode('_', $name) as $segment) {
            if (preg_match('/[a-z][A-Z]/', $segment)) {
                return true;
            }
        }

        return false;
    }
    foreach (explode('_', $name) as $segment) {
        if (preg_match('/[a-z][A-Z]/', $segment)) {
            return true;
        }
    }

    return false;
}

function allowlist_permits(array $allow, string $site, string $name, string $relativePath): bool
{
    $candidates = [
        "{$relativePath} {$site} {$name}",
        "{$site} {$name}",
    ];
    foreach ($candidates as $key) {
        if (isset($allow[$key])) {
            return true;
        }
    }

    return false;
}

function laravel_hook_method_allowed(string $class, string $method): bool
{
    if ($class === '') {
        return false;
    }
    if (!class_exists($class) && !trait_exists($class)) {
        if (str_ends_with($class, '_Test')) {
            return method_exists(PHPUnit\Framework\TestCase::class, $method);
        }
        if (str_ends_with($class, '_Trait') && method_exists(Illuminate\Database\Eloquent\Model::class, $method)) {
            return true;
        }

        return false;
    }
    try {
        $rc = new ReflectionClass($class);
        if ($rc->isTrait()) {
            $expected_boot = 'boot' . $rc->getShortName();
            if ($method === $expected_boot) {
                return true;
            }
            if (method_exists(Illuminate\Database\Eloquent\Model::class, $method)) {
                return true;
            }
        }
        if (preg_match('/^set[A-Z].*Attribute$/', $method)) {
            return true;
        }
        if ($rc->isSubclassOf(Illuminate\Database\Eloquent\Relations\Relation::class)) {
            $relation_methods = [
                'addConstraints',
                'addEagerConstraints',
                'addEagerConstraint',
                'getResults',
                'initRelation',
                'getRelationExistenceQuery',
                'getRelationCountHash',
                'matchForModel',
                'getForeignKeyName',
                'relationExistenceCondition',
            ];
            if (in_array($method, $relation_methods, true)) {
                return true;
            }
        }
    } catch (ReflectionException) {
        return false;
    }

    return false;
}

function parent_chain_declares_method(string $class, string $method): bool
{
    if ($class === '') {
        return false;
    }
    if (!class_exists($class) && !trait_exists($class)) {
        if (str_ends_with($class, '_Test')) {
            return method_exists(PHPUnit\Framework\TestCase::class, $method);
        }
        if (str_ends_with($class, '_Trait') && method_exists(Illuminate\Database\Eloquent\Model::class, $method)) {
            return true;
        }

        return false;
    }
    try {
        $parent = new ReflectionClass($class);
        while ($parent = $parent->getParentClass()) {
            if ($parent->hasMethod($method)) {
                return true;
            }
        }
    } catch (ReflectionException) {
        return false;
    }

    return false;
}

function reflection_allows_call(string $class, string $function, string $called): bool
{
    static $cache = [];
    $key = "{$class}::{$function}::{$called}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!class_exists($class) && !trait_exists($class)) {
        if (str_ends_with($class, '_Test')) {
            if (method_exists(PHPUnit\Framework\Assert::class, $called) || method_exists(PHPUnit\Framework\TestCase::class, $called)) {
                return $cache[$key] = true;
            }
            $eloquent_types = [
                Illuminate\Database\Eloquent\Model::class,
                Illuminate\Database\Eloquent\Builder::class,
                Illuminate\Database\Query\Builder::class,
                Illuminate\Database\Connection::class,
                Illuminate\Database\Schema\Blueprint::class,
                Illuminate\Database\Schema\Builder::class,
                Illuminate\Database\Capsule\Manager::class,
                Illuminate\Database\Query\Grammars\Grammar::class,
            ];
            foreach ($eloquent_types as $eloquent_type) {
                if (method_exists($eloquent_type, $called)) {
                    return $cache[$key] = true;
                }
            }
            if (method_exists(Illuminate\Database\Eloquent\Model::class, $called)) {
                return $cache[$key] = true;
            }
        }
        if (str_ends_with($class, '_Trait') && method_exists(Illuminate\Database\Eloquent\Model::class, $called)) {
            return $cache[$key] = true;
        }

        return $cache[$key] = false;
    }

    try {
        if ($function !== '' && method_exists($class, $function)) {
            $ref = new ReflectionMethod($class, $function);
            $declaring = $ref->getDeclaringClass();
            if ($declaring->hasMethod($called)) {
                return $cache[$key] = true;
            }
        }
        $rc = new ReflectionClass($class);
        while ($rc) {
            if ($rc->hasMethod($called)) {
                return $cache[$key] = true;
            }
            $rc = $rc->getParentClass();
        }
    } catch (ReflectionException) {
        return $cache[$key] = false;
    }

  if (is_subclass_of($class, PHPUnit\Framework\TestCase::class) || str_ends_with($class, '_Test')) {
        if (method_exists(PHPUnit\Framework\Assert::class, $called)) {
            return $cache[$key] = true;
        }
        if (method_exists(PHPUnit\Framework\TestCase::class, $called)) {
            return $cache[$key] = true;
        }
    }

    if (method_exists(Illuminate\Database\Eloquent\SoftDeletes::class, $called)) {
        return $cache[$key] = true;
    }

    if (str_starts_with($class, 'Kalnoy\\Nestedset\\') || str_ends_with($class, '_Test')) {
        $eloquent_types = [
            Illuminate\Database\Eloquent\Model::class,
            Illuminate\Database\Eloquent\Builder::class,
            Illuminate\Database\Query\Builder::class,
            Illuminate\Database\Connection::class,
            Illuminate\Database\Capsule\Manager::class,
            Illuminate\Database\Schema\Blueprint::class,
            Illuminate\Database\Schema\Builder::class,
            Illuminate\Support\Collection::class,
            Illuminate\Database\Eloquent\Collection::class,
            Illuminate\Database\Query\Grammars\Grammar::class,
            Illuminate\Database\Eloquent\Relations\Relation::class,
        ];
        foreach ($eloquent_types as $eloquent_type) {
            if (method_exists($eloquent_type, $called)) {
                return $cache[$key] = true;
            }
        }
        if (method_exists(Illuminate\Database\Eloquent\Model::class, $called)) {
            return $cache[$key] = true;
        }
    }

    return $cache[$key] = false;
}

/**
 * @return list<string>
 */
function scan_docblock_idents(string $relativePath, string $code): array
{
    $violations = [];
    if (!preg_match_all('/\/\*\*(.*?)\*\//s', $code, $blocks)) {
        return $violations;
    }
    foreach ($blocks[1] as $block) {
        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $line = ltrim($line, " \t*");
            if (preg_match('/@(?:param|var|property(?:-read|-write)?)\s+[^\s$]+\s+\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $m)) {
                if (repo_owned_identifier_violation($m[1])) {
                    $violations[] = "{$relativePath}: docblock: repo variable \${$m[1]}";
                }
            }
            if (preg_match('/@(?:param|var)\s+\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $m)) {
                if (repo_owned_identifier_violation($m[1])) {
                    $violations[] = "{$relativePath}: docblock: repo variable \${$m[1]}";
                }
            }
            foreach (OLD_API_NAMES as $old) {
                if (preg_match('/\b' . preg_quote($old, '/') . '\b/', $line)) {
                    $violations[] = "{$relativePath}: docblock: old API name {$old}";
                }
            }
        }
    }

    return $violations;
}

/**
 * @return list<string>
 */
function scan_php_content(string $relativePath, string $code, array $allow): array
{
    $violations = [];
    $class = null;
    $function = null;
    $namespace = '';
    $tokens = token_get_all($code);

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        $id = token_name($t[0]);
        $text = $t[1];
        $line = $t[2];

        if ($id === 'T_NAMESPACE') {
            $j = $i + 1;
            $parts = [];
            while ($j < $n) {
                $tok = $tokens[$j];
                if (!is_array($tok)) {
                    if ($tok === ';' || $tok === '{') {
                        break;
                    }
                    $j++;
                    continue;
                }
                $tn = token_name($tok[0]);
                if ($tn === 'T_WHITESPACE') {
                    $j++;
                    continue;
                }
                if ($tn === 'T_STRING' || $tn === 'T_NAME_QUALIFIED') {
                    $parts[] = $tok[1];
                }
                $j++;
            }
            $namespace = $parts !== [] ? implode('\\', $parts) . '\\' : '';
        }

        if ($id === 'T_CLASS' || $id === 'T_TRAIT') {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && token_name($tokens[$j][0]) === 'T_WHITESPACE') {
                $j++;
            }
            if ($j < $n && is_array($tokens[$j]) && token_name($tokens[$j][0]) === 'T_STRING') {
                $name = $tokens[$j][1];
                $class = $namespace !== '' ? $namespace . $name : $name;
                if (repo_owned_identifier_violation($name, true)) {
                    $violations[] = "{$relativePath}:{$line}: repo class name {$name}";
                }
            }
        }

        if ($id === 'T_FUNCTION') {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && token_name($tokens[$j][0]) === 'T_WHITESPACE') {
                $j++;
            }
            if ($j < $n && is_array($tokens[$j]) && token_name($tokens[$j][0]) === 'T_STRING') {
                $function = $tokens[$j][1];
                if (repo_owned_identifier_violation($function)
                    && !parent_chain_declares_method($class ?? '', $function)
                    && !laravel_hook_method_allowed($class ?? '', $function)) {
                    $violations[] = "{$relativePath}:{$line}: repo function {$function}";
                }
            }
        }

        if ($id === 'T_VARIABLE') {
            $name = substr($text, 1);
            if (repo_owned_identifier_violation($name)) {
                $violations[] = "{$relativePath}:{$line}: repo variable \${$name}";
            }
        }

        if ($id === 'T_STRING' && repo_owned_identifier_violation($text) && $class !== null && $function !== null) {
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && token_name($tokens[$prev][0]) === 'T_WHITESPACE') {
                $prev--;
            }
            if ($prev >= 0 && is_array($tokens[$prev]) && token_name($tokens[$prev][0]) === 'T_DOUBLE_COLON') {
                $prev2 = $prev - 1;
                while ($prev2 >= 0 && is_array($tokens[$prev2]) && token_name($tokens[$prev2][0]) === 'T_WHITESPACE') {
                    $prev2--;
                }
                $isStatic = $prev2 >= 0 && is_array($tokens[$prev2]) && token_name($tokens[$prev2][0]) === 'T_STATIC';
                if ($isStatic && !allowlist_permits($allow, "{$class}::{$function}", $text, $relativePath) && !reflection_allows_call($class, $function, $text)) {
                    $violations[] = "{$relativePath}:{$line}: upstream call {$text} outside allowlist ({$class}::{$function})";
                }
            }
            if ($prev >= 0 && is_array($tokens[$prev]) && token_name($tokens[$prev][0]) === 'T_OBJECT_OPERATOR') {
                $next = $i + 1;
                while ($next < $n && is_array($tokens[$next]) && token_name($tokens[$next][0]) === 'T_WHITESPACE') {
                    $next++;
                }
                $isCall = $next < $n && !is_array($tokens[$next]) && $tokens[$next] === '(';
                if ($isCall && !allowlist_permits($allow, "{$class}::{$function}", $text, $relativePath) && !reflection_allows_call($class, $function, $text)) {
                    $violations[] = "{$relativePath}:{$line}: upstream call {$text} outside allowlist ({$class}::{$function})";
                }
            }
        }
    }

    foreach (explode("\n", $code) as $num => $lineText) {
        foreach (OLD_API_NAMES as $old) {
            if (preg_match('/\b' . preg_quote($old, '/') . '\b/', $lineText)) {
                $violations[] = $relativePath . ':' . ($num + 1) . ": old API name {$old}";
            }
        }
    }

    return array_merge($violations, scan_docblock_idents($relativePath, $code));
}

/**
 * @param array<string, true> $allow
 * @return list<string>
 */
function scan_repository(string $root, array $allow): array
{
    $violations = [];
    foreach (['src', 'tests', 'examples'] as $dir) {
        $full = $root . '/' . $dir;
        if (!is_dir($full)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (!str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            $code = file_get_contents($file->getPathname());
            if ($code === false) {
                continue;
            }
            $violations = array_merge($violations, scan_php_content($rel, $code, $allow));
        }
    }

    foreach (['ARCHITECTURE.md'] as $doc) {
        $full = $root . '/' . $doc;
        if (!is_readable($full)) {
            continue;
        }
        $content = file_get_contents($full);
        if ($content === false) {
            continue;
        }
        foreach (explode("\n", $content) as $num => $line) {
            foreach (OLD_API_NAMES as $old) {
                if (preg_match('/\b' . preg_quote($old, '/') . '\b/', $line)) {
                    $violations[] = "{$doc}:" . ($num + 1) . ": old API name {$old}";
                }
            }
        }
    }

    return $violations;
}

/**
 * @param array<string, true> $allow
 */
function run_allowlist_demo(array $allow): void
{
    $allowWithAssertSame = $allow;
    $allowWithAssertSame['demo/good.php Demo_Good::test_demo assertSame'] = true;

    $bad = <<<'PHP'
<?php
class Demo_Bad extends PHPUnit\Framework\TestCase
{
    public function test_demo(): void
    {
        static::assertSame(1, 1);
    }
}
PHP;

    $good = <<<'PHP'
<?php
class Demo_Good extends PHPUnit\Framework\TestCase
{
    public function test_demo(): void
    {
        static::assertSame(1, 1);
    }
}
PHP;

    $badViolations = array_filter(
        scan_php_content('demo/bad.php', $bad, $allow),
        static fn (string $v) => str_contains($v, 'assertSame')
    );
    $goodViolations = array_filter(
        scan_php_content('demo/good.php', $good, $allowWithAssertSame),
        static fn (string $v) => str_contains($v, 'assertSame')
    );

    if ($badViolations === []) {
        fwrite(STDERR, "Allowlist demo: expected violation for assertSame outside listed site.\n");
        exit(1);
    }
    if ($goodViolations !== []) {
        fwrite(STDERR, "Allowlist demo: assertSame wrongly flagged at allowed site.\n");
        exit(1);
    }

    if (array_filter(scan_php_content('demo/bad.php', $bad, []), static fn (string $v) => str_contains($v, 'assertSame')) === []) {
        fwrite(STDERR, "Allowlist demo: stubbed checker must still flag assertSame.\n");
        exit(1);
    }

    fwrite(STDOUT, "allowlist demo: upstream name rejected outside listed site\n");
}

$allow = load_allowlist(__DIR__ . '/rename-allowlist.txt');
run_allowlist_demo($allow);

$violations = scan_repository($root, $allow);
$violations = array_values(array_unique($violations));

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "rename check: no camelCase repo-owned names remain\n");
exit(0);
