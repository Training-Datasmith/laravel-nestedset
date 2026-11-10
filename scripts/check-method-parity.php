#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** Upstream kalnoy/nestedset tag pinned for public API parity. */
const UPSTREAM_TAG = 'v6.0.6';
const UPSTREAM_REPO = 'https://github.com/lazychaser/laravel-nestedset.git';

/** @var array<string, string> local relative path => upstream src basename */
const UPSTREAM_FILE_MAP = [
    'src/Query_Builder.php' => 'QueryBuilder.php',
    'src/Node_Trait.php' => 'NodeTrait.php',
    'src/Collection.php' => 'Collection.php',
    'src/Nested_Set.php' => 'NestedSet.php',
    'src/Base_Relation.php' => 'BaseRelation.php',
    'src/Ancestors_Relation.php' => 'AncestorsRelation.php',
    'src/Descendants_Relation.php' => 'DescendantsRelation.php',
    'src/Nested_Set_Service_Provider.php' => 'NestedSetServiceProvider.php',
];

/** @var array<string, true> upstream public method names kept as-is locally */
const LARAVEL_CONVENTION_METHODS = [
    'setParentIdAttribute' => true,
    'newEloquentBuilder' => true,
    'newCollection' => true,
    'getArrayableRelations' => true,
    'getRelationExistenceQuery' => true,
    'addEagerConstraints' => true,
    'getForeignKeyName' => true,
    'getRelationCountHash' => true,
    'initRelation' => true,
    'getResults' => true,
    'match' => true,
    'register' => true,
    'addConstraints' => true,
    '__construct' => true,
];

/** @var array<string, string> upstream method => local method when not snake_case */
const UPSTREAM_TO_LOCAL_OVERRIDES = [
    'bootNodeTrait' => 'bootNode_Trait',
];

function camel_to_snake(string $name): string
{
    return strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $name) ?? $name);
}

function expected_local_method_name(string $upstreamMethod): string
{
    if (isset(UPSTREAM_TO_LOCAL_OVERRIDES[$upstreamMethod])) {
        return UPSTREAM_TO_LOCAL_OVERRIDES[$upstreamMethod];
    }
    if (isset(LARAVEL_CONVENTION_METHODS[$upstreamMethod])) {
        return $upstreamMethod;
    }

    return camel_to_snake($upstreamMethod);
}

/**
 * @return list<string>
 */
function public_method_names_from_source(string $code): array
{
    preg_match_all('/public (?:static )?function (\w+)/', $code, $matches);

    return $matches[1];
}

/**
 * @return array{upstream: list<string>, expectedLocal: array<string, true>, local: list<string>}
 */
function build_file_parity(string $localRelative, string $upstreamSource): array
{
    $localPath = dirname(__DIR__) . '/' . $localRelative;
    $localCode = file_get_contents($localPath);
    if ($localCode === false) {
        return ['upstream' => [], 'expectedLocal' => [], 'local' => []];
    }
    $upstream = public_method_names_from_source($upstreamSource);
    $local = public_method_names_from_source($localCode);
    $expectedLocal = [];
    foreach ($upstream as $method) {
        $expectedLocal[expected_local_method_name($method)] = true;
    }

    return ['upstream' => $upstream, 'expectedLocal' => $expectedLocal, 'local' => $local];
}

/**
 * @param array<string, true> $expectedLocal
 * @return list<string>
 */
function evaluate_local_against_expected(string $relativePath, array $expectedLocal, array $local): array
{
    $errors = [];
    $localIndex = array_fill_keys($local, true);
    $expectedCount = count($expectedLocal);
    $localCount = count($local);

    foreach ($expectedLocal as $want => $_) {
        if (!isset($localIndex[$want])) {
            $errors[] = "{$relativePath}: missing public method {$want}";
        }
    }
    foreach ($local as $method) {
        if (!isset($expectedLocal[$method])) {
            $errors[] = "{$relativePath}: unexpected public method {$method}";
        }
    }
    if ($expectedCount !== $localCount) {
        $errors[] = sprintf(
            '%s: public method count local %d != upstream %d',
            $relativePath,
            $localCount,
            $expectedCount
        );
    }

    return $errors;
}

function upstream_checkout_is_valid(string $checkoutDir): bool
{
    $probe = $checkoutDir . '/src/QueryBuilder.php';

    return is_readable($probe);
}

function ensure_upstream_checkout(string $checkoutDir): bool
{
    if (is_dir($checkoutDir) && !upstream_checkout_is_valid($checkoutDir)) {
        $proc = proc_open(
            ['rm', '-rf', $checkoutDir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($proc)) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }
    if (upstream_checkout_is_valid($checkoutDir)) {
        return true;
    }
    if (is_dir($checkoutDir)) {
        return false;
    }
    $parent = dirname($checkoutDir);
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        return false;
    }
    $command = sprintf(
        'git clone --depth 1 --branch %s --single-branch %s %s 2>&1',
        escapeshellarg(UPSTREAM_TAG),
        escapeshellarg(UPSTREAM_REPO),
        escapeshellarg($checkoutDir)
    );
    exec($command, $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, implode("\n", $output) . "\n");

        return false;
    }

    return upstream_checkout_is_valid($checkoutDir);
}

/**
 * @return array<string, string> upstream basename => file contents
 */
function load_upstream_sources(string $checkoutDir): array
{
    $out = [];
    foreach (UPSTREAM_FILE_MAP as $upstreamBase) {
        $path = $checkoutDir . '/src/' . $upstreamBase;
        if (!is_readable($path)) {
            fwrite(STDERR, "Upstream missing src/{$upstreamBase} under tag " . UPSTREAM_TAG . "\n");
            exit(1);
        }
        $code = file_get_contents($path);
        if ($code === false) {
            fwrite(STDERR, "Could not read upstream src/{$upstreamBase}\n");
            exit(1);
        }
        $out[$upstreamBase] = $code;
    }

    return $out;
}

/**
 * @param array<string, true> $expectedLocal
 * @return array{extra: list<string>, missing: list<string>}
 */
function parity_diff(array $expectedLocal, array $local): array
{
    $localIndex = array_fill_keys($local, true);
    $missing = [];
    foreach ($expectedLocal as $name => $_) {
        if (!isset($localIndex[$name])) {
            $missing[] = $name;
        }
    }
    $extra = [];
    foreach ($local as $name) {
        if (!isset($expectedLocal[$name])) {
            $extra[] = $name;
        }
    }

    return ['extra' => $extra, 'missing' => $missing];
}

function run_method_parity_demos(): void
{
    $root = dirname(__DIR__);
    $expected = ['link_nodes' => true, 'to_tree' => true, 'to_flat_tree' => true];
    $goodLocal = ['link_nodes', 'to_tree', 'to_flat_tree'];
    if (evaluate_local_against_expected('src/Collection.php', $expected, $goodLocal) !== []) {
        fwrite(STDERR, "Parity demo: matching trio should pass.\n");
        exit(1);
    }

    $withExtra = [...$goodLocal, 'where_descendant_and_self'];
    $diffExtra = parity_diff($expected, $withExtra);
    if ($diffExtra['extra'] === []) {
        fwrite(STDERR, "Parity demo: extra public method must be detected.\n");
        exit(1);
    }
    fwrite(STDOUT, "method parity demo: extra public method rejected\n");

    $withMissing = ['link_nodes', 'to_tree'];
    $diffMissing = parity_diff($expected, $withMissing);
    if ($diffMissing['missing'] === [] || count($withMissing) === count($expected)) {
        fwrite(STDERR, "Parity demo: missing public method must be detected.\n");
        exit(1);
    }
    fwrite(STDOUT, "method parity demo: missing public method rejected\n");
}

$checkoutDir = sys_get_temp_dir() . '/nestedset-upstream-' . UPSTREAM_TAG;
if (!ensure_upstream_checkout($checkoutDir)) {
    fwrite(STDERR, 'Could not clone upstream tag ' . UPSTREAM_TAG . " into {$checkoutDir}\n");
    exit(1);
}

run_method_parity_demos();

$upstreamSources = load_upstream_sources($checkoutDir);
$errors = [];
foreach (UPSTREAM_FILE_MAP as $localRel => $upstreamBase) {
    $parity = build_file_parity($localRel, $upstreamSources[$upstreamBase]);
    $errors = array_merge(
        $errors,
        evaluate_local_against_expected($localRel, $parity['expectedLocal'], $parity['local'])
    );
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "method parity check: tag " . UPSTREAM_TAG . " public methods map 1:1\n");
exit(0);
