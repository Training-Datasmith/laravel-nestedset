#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require $autoload;
}

/** @var list<string> $OLD_API_NAMES */
/** Laravel / PHP identifiers that may appear in docs with camelCase but are not repo-owned. */
const UPSTREAM_MARKDOWN_IDENTIFIERS = [
    'SoftDeletes' => true,
    'ModelNotFoundException' => true,
    'findOrFail' => true,
    'unsignedInteger' => true,
    'softDeletes' => true,
    'dropColumn' => true,
    'dropIndex' => true,
    'HasMany' => true,
    'HasOne' => true,
    'BelongsTo' => true,
    'Blueprint' => true,
    'Schema' => true,
    'InnoDb' => true,
    'MySql' => true,
    'groupBy' => true,
    'pluck' => true,
    'toArray' => true,
    'getKey' => true,
    'whereIn' => true,
];

function is_upstream_markdown_identifier(string $name): bool
{
    return isset(UPSTREAM_MARKDOWN_IDENTIFIERS[$name]);
}

/**
 * @return array<string, true>
 */
function load_laravel_convention_markdown_names(string $repoFnAllowPath): array
{
    $names = [
        'setParentIdAttribute' => true,
        'bootNode_Trait' => true,
    ];
    if (is_readable($repoFnAllowPath)) {
        foreach (file($repoFnAllowPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[2])) {
                $names[$parts[2]] = true;
            }
        }
    }

    return $names;
}

/** @var list<string> */
const MARKDOWN_SKIP_IDENTIFIERS = [
    'if',
    'for',
    'foreach',
    'while',
    'switch',
    'return',
    'echo',
    'function',
    'class',
    'use',
    'new',
    'public',
    'protected',
    'private',
    'static',
    'void',
    'array',
    'string',
    'int',
    'bool',
    'Model',
    'Schema',
    'Blueprint',
];

function markdown_identifier_is_violation(string $ident, array $conventionNames): bool
{
    if ($ident === '' || in_array($ident, MARKDOWN_SKIP_IDENTIFIERS, true)) {
        return false;
    }
    if (is_upstream_markdown_identifier($ident) || isset($conventionNames[$ident])) {
        return false;
    }

    return repo_owned_identifier_violation($ident);
}

/**
 * @return list<string>
 */
function extract_markdown_repo_identifiers(string $line): array
{
    $idents = [];
    if (preg_match_all('/__([a-zA-Z_][a-zA-Z0-9_]*)\(\)__/', $line, $matches)) {
        foreach ($matches[1] as $ident) {
            $idents[] = $ident;
        }
    }
    if (preg_match_all('/`([^`]+)`/', $line, $matches)) {
        foreach ($matches[1] as $chunk) {
            if (str_contains($chunk, '::')) {
                $parts = explode('::', $chunk);
                $method = end($parts);
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $method)) {
                    $idents[] = $method;
                }
            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $chunk)) {
                $idents[] = $chunk;
            }
        }
    }
    if (preg_match_all('/::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $matches)) {
        foreach ($matches[1] as $ident) {
            $idents[] = $ident;
        }
    }
    if (preg_match_all('/->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $matches)) {
        foreach ($matches[1] as $ident) {
            $idents[] = $ident;
        }
    }
    if (preg_match_all('/(?<![:\\w$>])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $matches)) {
        foreach ($matches[1] as $ident) {
            $idents[] = $ident;
        }
    }
    if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $matches)) {
        foreach ($matches[1] as $ident) {
            $idents[] = $ident;
        }
    }

    return array_values(array_unique($idents));
}

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

function upstream_declaring_type(string $type): bool
{
    if (str_starts_with($type, 'PHPUnit\\')) {
        return true;
    }
    if (str_starts_with($type, 'Illuminate\\')) {
        return true;
    }
    if ($type === Illuminate\Database\Eloquent\SoftDeletes::class) {
        return true;
    }

    return false;
}

function parent_chain_declares_method(string $class, string $method): bool
{
    if ($class === '') {
        return false;
    }
    if (!class_exists($class) && !trait_exists($class)) {
        return false;
    }
    try {
        $rc = new ReflectionClass($class);
        while ($parent = $rc->getParentClass()) {
            $rc = $parent;
            if (!$parent->hasMethod($method)) {
                continue;
            }
            $declaring = $parent->getMethod($method)->getDeclaringClass()->getName();
            if (upstream_declaring_type($declaring)) {
                return true;
            }
        }
    } catch (ReflectionException) {
        return false;
    }

    return false;
}

function repo_function_laravel_override(string $class, string $method): bool
{
    return parent_chain_declares_method($class, $method);
}

function reflection_allows_call(string $class, string $function, string $called): bool
{
    static $cache = [];
    $key = "{$class}::{$function}::{$called}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!class_exists($class) && !trait_exists($class)) {
        return $cache[$key] = false;
    }

    return $cache[$key] = parent_chain_declares_method($class, $called);
}

function bootstrap_reflection_classes(string $root): void
{
    require $root . '/phpunit.php';
    require $root . '/tests/Node_Test.php';
    require $root . '/tests/Scoped_Node_Test.php';
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
function scan_markdown_content(string $relativePath, string $content, array $conventionNames = []): array
{
    $violations = [];
    foreach (explode("\n", $content) as $num => $line) {
        foreach (OLD_API_NAMES as $old) {
            if (preg_match('/\b' . preg_quote($old, '/') . '\b/', $line)) {
                $violations[] = "{$relativePath}:" . ($num + 1) . ": old API name {$old}";
            }
        }
        foreach (extract_markdown_repo_identifiers($line) as $ident) {
            if (markdown_identifier_is_violation($ident, $conventionNames)) {
                $violations[] = "{$relativePath}:" . ($num + 1) . ": repo identifier {$ident}";
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
function scan_repository(string $root, array $allow, array $repoFnAllow, array $markdownConvention): array
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
    foreach (['*.md', '*.markdown'] as $pattern) {
        $proc = proc_open(
            ['git', '-C', $root, 'ls-files', $pattern],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            continue;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        foreach (preg_split('/\R/', trim((string) $out)) ?: [] as $rel) {
            if ($rel !== '' && !in_array($rel, $mdFiles, true)) {
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
        $violations = array_merge($violations, scan_markdown_content($doc, $content, $markdownConvention));
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

function run_markdown_demo(array $conventionNames): void
{
    $bad = "- __whereIsRoot()__ and whereDescendantAndSelf(\$id) and `Nested_Set::dropColumns`\n";
    $good = "- __where_is_root()__ and where_descendant_and_self(\$id) and `Nested_Set::drop_columns`\n";
    if (scan_markdown_content('demo.md', $bad, $conventionNames) === []) {
        fwrite(STDERR, "Markdown demo: expected camelCase violation.\n");
        exit(1);
    }
    if (scan_markdown_content('demo.md', $good, $conventionNames) !== []) {
        fwrite(STDERR, "Markdown demo: snake_case doc must pass.\n");
        exit(1);
    }
    fwrite(STDOUT, "markdown demo: expanded contexts flag repo camelCase\n");
}

function run_drop_columns_markdown_demo(array $conventionNames): void
{
    $bad = 'Nested_Set::dropColumns($table)';
    if (!markdown_identifier_is_violation('dropColumns', $conventionNames)) {
        fwrite(STDERR, "dropColumns markdown demo: removed exemption must flag dropColumns.\n");
        exit(1);
    }
    $good = scan_markdown_content('demo.md', "use `Nested_Set::drop_columns`.\n", $conventionNames);
    if ($good !== []) {
        fwrite(STDERR, "dropColumns markdown demo: drop_columns must pass.\n");
        exit(1);
    }
    fwrite(STDOUT, "markdown demo: Nested_Set::drop_columns required over dropColumns\n");
}

function run_convention_markdown_demo(array $conventionNames): void
{
    $sample = scan_markdown_content('demo.md', "`setParentIdAttribute` in prose.\n", $conventionNames);
    if ($sample !== []) {
        fwrite(STDERR, "Convention markdown demo: setParentIdAttribute should pass.\n");
        exit(1);
    }
    fwrite(STDOUT, "markdown demo: Laravel convention names allowed in docs\n");
}

function run_boot_trait_function_demo(array $repoFnAllow): void
{
    $bad = repo_function_laravel_override('Kalnoy\\Nestedset\\Node_Trait', 'bootNode_Trait');
    if ($bad) {
        fwrite(STDERR, "Boot trait demo: bootNode_Trait must not bypass via boot naming hack.\n");
        exit(1);
    }
    if (!repo_function_allowlisted($repoFnAllow, 'src/Node_Trait.php', 'Kalnoy\\Nestedset\\Node_Trait', 'bootNode_Trait')) {
        fwrite(STDERR, "Boot trait demo: bootNode_Trait must be on repo-function allowlist.\n");
        exit(1);
    }
    fwrite(STDOUT, "boot trait demo: bootNode_Trait needs explicit repo-function row\n");
}

function run_reflection_strict_demo(): void
{
    eval('class Ref_Demo_Pass extends PHPUnit\Framework\TestCase { public function test_ok(): void { static::assertSame(1, 1); } }');
    eval('class Ref_Demo_Fail { public function test_bad(): void { static::assertSame(1, 1); } }');

    if (!reflection_allows_call('Ref_Demo_Pass', 'test_ok', 'assertSame')) {
        fwrite(STDERR, "Reflection demo: PHPUnit parent-chain call should be allowed when class is loaded.\n");
        exit(1);
    }
    if (reflection_allows_call('Ref_Demo_Fail', 'test_bad', 'assertSame')) {
        fwrite(STDERR, "Reflection demo: non-TestCase class must not allow assertSame via reflection.\n");
        exit(1);
    }
    if (reflection_allows_call('Ref_Demo_Ghost', 'test_x', 'assertSame')) {
        fwrite(STDERR, "Reflection demo: unloadable class must fail closed.\n");
        exit(1);
    }

    fwrite(STDOUT, "reflection demo: upstream allowed only via loaded parent declaring type\n");
}

$allow = load_allowlist(__DIR__ . '/rename-allowlist.txt');
$repoFnAllow = load_repo_function_allowlist(__DIR__ . '/repo-function-allowlist.txt');
$markdownConvention = load_laravel_convention_markdown_names(__DIR__ . '/repo-function-allowlist.txt');
run_allowlist_demo($allow, $repoFnAllow);
run_markdown_demo($markdownConvention);
run_drop_columns_markdown_demo($markdownConvention);
run_convention_markdown_demo($markdownConvention);
run_boot_trait_function_demo($repoFnAllow);
run_reflection_strict_demo();

bootstrap_reflection_classes($root);

$violations = scan_repository($root, $allow, $repoFnAllow, $markdownConvention);
$violations = array_values(array_unique($violations));

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "rename check: no camelCase repo-owned names remain\n");
exit(0);
