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

function has_camel_case_boundary(string $ident): bool
{
    return (bool) preg_match('/[a-z][A-Z]/', $ident);
}

function is_studly_snake_class(string $name): bool
{
    return (bool) preg_match('/^[A-Z][a-zA-Z0-9]*(_[A-Z][a-zA-Z0-9]*)+$/', $name);
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

function framework_override_allowed(string $class, string $function): bool
{
    static $allowedNames = [
        'bootNode_Trait' => true,
        'newEloquentBuilder' => true,
        'newCollection' => true,
        'getArrayableRelations' => true,
        'setParentIdAttribute' => true,
        'replicate' => true,
        'create' => true,
        'addConstraints' => true,
        'addEagerConstraint' => true,
        'relationExistenceCondition' => true,
        'getRelationExistenceQuery' => true,
        'initRelation' => true,
        'getRelationCountHash' => true,
        'getResults' => true,
        'addEagerConstraints' => true,
        'matchForModel' => true,
        'getForeignKeyName' => true,
        'register' => true,
        'setUp' => true,
        'tearDown' => true,
        'setUpBeforeClass' => true,
    ];
    if (isset($allowedNames[$function])) {
        return true;
    }
    if ($class === '' || !class_exists($class)) {
        return false;
    }
    try {
        $parent = new ReflectionClass($class);
        while ($parent = $parent->getParentClass()) {
            if ($parent->hasMethod($function)) {
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

    if (!class_exists($class)) {
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

    if (str_ends_with($class, '_Test') || is_subclass_of($class, PHPUnit\Framework\TestCase::class)) {
        if (method_exists(PHPUnit\Framework\Assert::class, $called)) {
            return $cache[$key] = true;
        }
        if (method_exists(PHPUnit\Framework\TestCase::class, $called)) {
            return $cache[$key] = true;
        }
        if (method_exists(PHPUnit\Framework\Assert::class, $called)) {
            return $cache[$key] = true;
        }
    }

    $eloquentTypes = [
        Illuminate\Database\Eloquent\Model::class,
        Illuminate\Database\Eloquent\Builder::class,
        Illuminate\Database\Query\Builder::class,
        Illuminate\Database\Connection::class,
        Illuminate\Database\Capsule\Manager::class,
        Illuminate\Database\Schema\Blueprint::class,
        Illuminate\Support\Collection::class,
        Illuminate\Database\Eloquent\Collection::class,
        Illuminate\Database\Query\Grammars\Grammar::class,
    ];
    $eloquentTypes[] = Illuminate\Database\Eloquent\Relations\Relation::class;
    foreach ($eloquentTypes as $eloquentType) {
        if (method_exists($eloquentType, $called)) {
            return $cache[$key] = true;
        }
    }

    if (
        $class === 'Kalnoy\\Nestedset\\Query_Builder'
        || $class === 'Query_Builder'
        || (class_exists($class) && is_a($class, Illuminate\Database\Eloquent\Builder::class, true))
    ) {
        if (method_exists(Illuminate\Database\Eloquent\Builder::class, $called)) {
            return $cache[$key] = true;
        }
    }

    if (method_exists(\Illuminate\Database\Eloquent\SoftDeletes::class, $called)) {
        return $cache[$key] = true;
    }

    return $cache[$key] = false;
}

/**
 * @return list<string>
 */
function scan_php_content(string $relativePath, string $code, array $allow): array
{
    $violations = [];
    if (str_starts_with($relativePath, 'tests/')) {
        foreach (explode("\n", $code) as $num => $lineText) {
            foreach (OLD_API_NAMES as $old) {
                if (str_contains($lineText, $old)) {
                    $violations[] = $relativePath . ':' . ($num + 1) . ": old API name {$old}";
                }
            }
        }

        return $violations;
    }
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

        if ($id === 'T_CLASS') {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && token_name($tokens[$j][0]) === 'T_WHITESPACE') {
                $j++;
            }
            if ($j < $n && is_array($tokens[$j]) && token_name($tokens[$j][0]) === 'T_STRING') {
                $name = $tokens[$j][1];
                $class = $namespace !== '' ? $namespace . $name : $name;
                if (has_camel_case_boundary($name) && !is_studly_snake_class($name)) {
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
                if (has_camel_case_boundary($function) && !framework_override_allowed($class ?? '', $function)) {
                    $violations[] = "{$relativePath}:{$line}: repo function {$function}";
                }
            }
        }

        if ($id === 'T_VARIABLE' && !str_starts_with($relativePath, 'tests/')) {
            $name = substr($text, 1);
            if (has_camel_case_boundary($name)) {
                $violations[] = "{$relativePath}:{$line}: repo variable \${$name}";
            }
        }

        if ($id === 'T_STRING' && has_camel_case_boundary($text) && $class !== null && $function !== null) {
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
                if (!allowlist_permits($allow, "{$class}::{$function}", $text, $relativePath) && !reflection_allows_call($class, $function, $text)) {
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

    return $violations;
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
                if (str_contains($line, $old)) {
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
        static::assertTrue(true);
    }
}
PHP;

    $badViolations = scan_php_content('demo/bad.php', $bad, $allow);
    $goodViolations = array_filter(
        scan_php_content('demo/good.php', $good, $allow),
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
