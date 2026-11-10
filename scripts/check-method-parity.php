#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = __DIR__ . '/upstream-public-method-manifest.json';

/** @var array<string, string> */
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
    'bootNode_Trait' => true,
];

/**
 * @return list<string>
 */
function public_method_names(string $phpFile): array
{
    $code = file_get_contents($phpFile);
    if ($code === false) {
        return [];
    }
    preg_match_all('/public (?:static )?function (\w+)/', $code, $matches);

    return $matches[1];
}

/**
 * @param array<string, string> $manifestEntry
 * @return list<string>
 */
function evaluate_manifest_file(string $relativePath, array $manifestEntry, string $root): array
{
    $errors = [];
    $full = $root . '/' . $relativePath;
    if (!is_readable($full)) {
        return ["{$relativePath}: file missing"];
    }
  $local = public_method_names($full);
    $localIndex = array_fill_keys($local, true);

    foreach ($manifestEntry as $upstream => $expectedLocal) {
        $keepUpstream = isset(LARAVEL_CONVENTION_METHODS[$expectedLocal])
            || isset(LARAVEL_CONVENTION_METHODS[$upstream]);
        $want = $keepUpstream ? $upstream : $expectedLocal;
        if ($want === 'bootNodeTrait') {
            $want = 'bootNode_Trait';
        }
        if (!isset($localIndex[$want])) {
            $errors[] = "{$relativePath}: missing public method {$want} (upstream {$upstream})";
        }
    }

    $manifestLocals = [];
    foreach ($manifestEntry as $upstream => $expectedLocal) {
        $keepUpstream = isset(LARAVEL_CONVENTION_METHODS[$expectedLocal])
            || isset(LARAVEL_CONVENTION_METHODS[$upstream]);
        $manifestLocals[$keepUpstream ? $upstream : $expectedLocal] = true;
        if ($keepUpstream && $upstream === 'bootNodeTrait') {
            $manifestLocals['bootNode_Trait'] = true;
        }
    }
    foreach ($local as $method) {
        if (isset($manifestLocals[$method])) {
            continue;
        }
        if (isset(LARAVEL_CONVENTION_METHODS[$method])) {
            continue;
        }
        $errors[] = "{$relativePath}: unexpected public method {$method} (not mapped from upstream)";
    }

    if (count($manifestEntry) !== count($local)) {
        $errors[] = sprintf(
            '%s: public method count %d != upstream manifest %d',
            $relativePath,
            count($local),
            count($manifestEntry)
        );
    }

    return $errors;
}

function run_method_parity_demo(): void
{
    $fakeRoot = dirname(__DIR__);
    $good = evaluate_manifest_file('src/Collection.php', [
        'linkNodes' => 'link_nodes',
        'toTree' => 'to_tree',
        'toFlatTree' => 'to_flat_tree',
    ], $fakeRoot);
    if ($good !== []) {
        fwrite(STDERR, "Parity demo: Collection should pass.\n");
        exit(1);
    }
    $bad = evaluate_manifest_file('src/Collection.php', [
        'linkNodes' => 'link_nodes',
        'toTree' => 'to_tree',
    ], $fakeRoot);
    if ($bad === []) {
        fwrite(STDERR, "Parity demo: missing mapped method must fail.\n");
        exit(1);
    }
    fwrite(STDOUT, "method parity demo: upstream count and 1:1 mapping enforced\n");
}

if (!is_readable($manifestPath)) {
    fwrite(STDERR, "Missing {$manifestPath}\n");
    exit(1);
}

$raw = file_get_contents($manifestPath);
/** @var array<string, array<string, string>>|null $manifest */
$manifest = json_decode($raw ?: '', true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Invalid manifest JSON\n");
    exit(1);
}

run_method_parity_demo();

$errors = [];
foreach ($manifest as $file => $entry) {
    $relative = str_starts_with($file, 'src/') ? $file : 'src/' . $file;
    $errors = array_merge($errors, evaluate_manifest_file($relative, $entry, $root));
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

fwrite(STDOUT, 'method parity check: upstream public methods map 1:1' . "\n");
exit(0);
