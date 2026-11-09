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
function load_repo_function_allowlist(string $path): array
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

function repo_function_allowlisted(array $repoFnAllow, string $relativePath, string $class, string $method): bool
{
    return isset($repoFnAllow["{$relativePath} {$class} {$method}"]);
}

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
    return isset($allow["{$relativePath} {$site} {$name}"]);
}

function method_declared_on_type(string $type, string $method): bool
{
    try {
        if (!class_exists($type) && !trait_exists($type)) {
            return false;
        }

        return (new ReflectionClass($type))->hasMethod($method);
    } catch (ReflectionException) {
        return false;
    }
}

function parent_chain_declares_method(string $class, string $method): bool
{
    if ($class === '') {
        return false;
    }
    if (!class_exists($class) && !trait_exists($class)) {
        if (str_ends_with($class, '_Test')) {
            return method_declared_on_type(PHPUnit\Framework\TestCase::class, $method);
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

function laravel_trait_boot_method(string $class, string $method): bool
{
    if (!trait_exists($class) && !(class_exists($class) && (new ReflectionClass($class))->isTrait())) {
        return false;
    }
    try {
        $rc = new ReflectionClass($class);

        return $method === 'boot' . $rc->getShortName();
    } catch (ReflectionException) {
        return false;
    }
}

function repo_function_laravel_override(string $class, string $method): bool
{
    if (parent_chain_declares_method($class, $method)) {
        return true;
    }
    if (laravel_trait_boot_method($class, $method)) {
        return true;
    }
    if (!class_exists($class) && !trait_exists($class)) {
        return false;
    }
    try {
        $rc = new ReflectionClass($class);
        if ($rc->isTrait() && method_declared_on_type(Illuminate\Database\Eloquent\Model::class, $method)) {
            return true;
        }
        if ($rc->isSubclassOf(Illuminate\Database\Eloquent\Relations\Relation::class) && $rc->hasMethod($method)) {
            $ref = $rc->getMethod($method);
            if ($ref->getDeclaringClass()->getName() !== $class || $ref->isAbstract()) {
                return true;
            }
        }
    } catch (ReflectionException) {
        return false;
    }

    return false;
}

function is_test_class_name(string $class): bool
{
    if (class_exists($class) && is_subclass_of($class, PHPUnit\Framework\TestCase::class)) {
        return true;
    }

    return str_ends_with($class, '_Test');
}

function reflection_allows_call(string $class, string $function, string $called): bool
{
    static $cache = [];
    $key = "{$class}::{$function}::{$called}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (parent_chain_declares_method($class, $called)) {
        return $cache[$key] = true;
    }

    if (class_exists($class) || trait_exists($class)) {
        try {
            $rc = new ReflectionClass($class);
            if ($rc->hasMethod($called)) {
                return $cache[$key] = true;
            }
        } catch (ReflectionException) {
            // continue
        }
    }

    if (is_test_class_name($class)) {
        if (method_declared_on_type(PHPUnit\Framework\Assert::class, $called)) {
            return $cache[$key] = true;
        }
        if (method_declared_on_type(PHPUnit\Framework\TestCase::class, $called)) {
            return $cache[$key] = true;
        }
        foreach ([
            Illuminate\Database\Eloquent\Model::class,
            Illuminate\Database\Eloquent\Builder::class,
            Illuminate\Database\Query\Builder::class,
            Illuminate\Database\Connection::class,
            Illuminate\Database\Capsule\Manager::class,
            Illuminate\Database\Schema\Blueprint::class,
            Illuminate\Database\Schema\Builder::class,
            Illuminate\Database\Query\Grammars\Grammar::class,
            Illuminate\Database\Eloquent\Relations\Relation::class,
        ] as $eloquent_type) {
            if (method_declared_on_type($eloquent_type, $called)) {
                return $cache[$key] = true;
            }
        }
    }

    if (method_declared_on_type(Illuminate\Database\Eloquent\SoftDeletes::class, $called)) {
        return $cache[$key] = true;
    }

    if (class_exists($class) || trait_exists($class)) {
        try {
            $rc = new ReflectionClass($class);
            foreach ($rc->getTraits() as $trait) {
                if ($trait->hasMethod($called)) {
                    return $cache[$key] = true;
                }
            }
            foreach (class_uses_recursive($class) as $traitName) {
                if (method_declared_on_type($traitName, $called)) {
                    return $cache[$key] = true;
                }
            }
        } catch (ReflectionException) {
            // continue
        }
    }

    if (str_starts_with($class, 'Kalnoy\\Nestedset\\') || trait_exists($class) && str_contains($class, 'Nestedset\\')) {
        foreach ([
            Illuminate\Database\Eloquent\Model::class,
            Illuminate\Database\Eloquent\Builder::class,
            Illuminate\Database\Query\Builder::class,
            Illuminate\Database\Connection::class,
            Illuminate\Database\Capsule\Manager::class,
            Illuminate\Database\Schema\Blueprint::class,
            Illuminate\Database\Schema\Builder::class,
            Illuminate\Database\Query\Grammars\Grammar::class,
            Illuminate\Database\Eloquent\Collection::class,
            Illuminate\Support\Collection::class,
            Illuminate\Database\Eloquent\Relations\Relation::class,
            Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            Illuminate\Database\Eloquent\Relations\HasMany::class,
        ] as $eloquent_type) {
            if (method_declared_on_type($eloquent_type, $called)) {
                return $cache[$key] = true;
            }
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
function scan_markdown_content(string $relativePath, string $content): array
{
    $violations = [];
    foreach (explode("\n", $content) as $num => $line) {
        foreach (OLD_API_NAMES as $old) {
            if (preg_match('/\b' . preg_quote($old, '/') . '\b/', $line)) {
                $violations[] = "{$relativePath}:" . ($num + 1) . ": old API name {$old}";
            }
        }
        if (preg_match_all('/`([a-zA-Z_][a-zA-Z0-9_]*)`/', $line, $matches)) {
            foreach ($matches[1] as $ident) {
                if (repo_owned_identifier_violation($ident)) {
                    $violations[] = "{$relativePath}:" . ($num + 1) . ": repo identifier {$ident}";
                }
            }
        }
        if (preg_match_all('/::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $matches)) {
            foreach ($matches[1] as $ident) {
                if (repo_owned_identifier_violation($ident)) {
                    $violations[] = "{$relativePath}:" . ($num + 1) . ": repo method {$ident}";
                }
            }
        }
        if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $matches)) {
            foreach ($matches[1] as $ident) {
                if (repo_owned_identifier_violation($ident)) {
                    $violations[] = "{$relativePath}:" . ($num + 1) . ": repo variable \${$ident}";
                }
            }
        }
    }

    return $violations;
}

/**
 * @return list<string>
 */
function scan_php_content(string $relativePath, string $code, array $allow, array $repoFnAllow): array
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
                    && !repo_function_allowlisted($repoFnAllow, $relativePath, $class ?? '', $function)
                    && !repo_function_laravel_override($class ?? '', $function)) {
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
function scan_repository(string $root, array $allow, array $repoFnAllow): array
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
            $violations = array_merge($violations, scan_php_content($rel, $code, $allow, $repoFnAllow));
        }
    }

    $mdFiles = [];
    $proc = proc_open(
        ['git', '-C', $root, 'ls-files', '*.md'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($proc)) {
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        foreach (preg_split('/\R/', trim((string) $out)) ?: [] as $rel) {
            if ($rel !== '') {
                $mdFiles[] = $rel;
            }
        }
    }
    if ($mdFiles === []) {
        $mdFiles = ['ARCHITECTURE.md'];
    }
    foreach ($mdFiles as $doc) {
        $full = $root . '/' . $doc;
        if (!is_readable($full)) {
            continue;
        }
        $content = file_get_contents($full);
        if ($content === false) {
            continue;
        }
        $violations = array_merge($violations, scan_markdown_content($doc, $content));
    }

    return $violations;
}

/**
 * @param array<string, true> $allow
 */
function run_allowlist_demo(array $allow, array $repoFnAllow): void
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
        scan_php_content('demo/bad.php', $bad, $allow, $repoFnAllow),
        static fn (string $v) => str_contains($v, 'assertSame')
    );
    $goodViolations = array_filter(
        scan_php_content('demo/good.php', $good, $allowWithAssertSame, $repoFnAllow),
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

    $site_only = ['Demo_Bad::test_demo assertSame' => true];
    if (allowlist_permits($site_only, 'Demo_Bad::test_demo', 'assertSame', 'demo/bad.php')) {
        fwrite(STDERR, "Allowlist demo: file+Class::method required; site-only key must not permit.\n");
        exit(1);
    }

    fwrite(STDOUT, "allowlist demo: upstream name rejected outside listed site\n");
}

function run_markdown_demo(): void
{
    $bad = "- Use `Nested_Set::rebuildsTree()` with \$insertPoint\n";
    $good = "- Use `Nested_Set::rebuild_tree()` with \$insert_point\n";
    if (scan_markdown_content('demo.md', $bad) === []) {
        fwrite(STDERR, "Markdown demo: expected camelCase violation.\n");
        exit(1);
    }
    if (scan_markdown_content('demo.md', $good) !== []) {
        fwrite(STDERR, "Markdown demo: snake_case doc must pass.\n");
        exit(1);
    }
    fwrite(STDOUT, "markdown demo: repo camelCase flagged in tracked docs\n");
}

$allow = load_allowlist(__DIR__ . '/rename-allowlist.txt');
$repoFnAllow = load_repo_function_allowlist(__DIR__ . '/repo-function-allowlist.txt');
run_allowlist_demo($allow, $repoFnAllow);
run_markdown_demo();

$violations = scan_repository($root, $allow, $repoFnAllow);
$violations = array_values(array_unique($violations));

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "rename check: no camelCase repo-owned names remain\n");
exit(0);
