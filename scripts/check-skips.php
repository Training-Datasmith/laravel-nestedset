#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: check-skips.php path/to/phpunit.junit.xml\n");
    exit(2);
}

$junitPath = $argv[1];
$allowPath = __DIR__ . '/allowed-skips.txt';

/**
 * @return array<string, true>
 */
function load_skip_allowlist(string $allowPath): array
{
    /** @var array<string, true> $allowed */
    $allowed = [];
    foreach (file($allowPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $allowed[$line] = true;
    }

    return $allowed;
}

/**
 * @return list<string>
 */
function parse_skips(string $junitPath): array
{
    $xml = new DOMDocument();
    if (!$xml->load($junitPath)) {
        fwrite(STDERR, "Invalid JUnit XML\n");
        exit(2);
    }
    $xpath = new DOMXPath($xml);
    $skips = [];
    foreach ($xpath->query('//testcase[skipped]') as $case) {
        if (!$case instanceof DOMElement) {
            continue;
        }
        $class = $case->getAttribute('class');
        $name = $case->getAttribute('name');
        if ($class === '' || $name === '') {
            continue;
        }
        $short = $class;
        if (($pos = strrpos($class, '\\')) !== false) {
            $short = substr($class, $pos + 1);
        }
        if (str_contains($name, ' with data set ')) {
            $name = strstr($name, ' with data set ', true) ?: $name;
        }
        $skips[] = $short . '::' . $name;
    }

    return $skips;
}

/**
 * @param list<string> $skips
 * @param array<string, true> $allowed
 */
function evaluate_skips(array $skips, array $allowed, bool $quiet = false): bool
{
    $failed = false;
    foreach ($skips as $skip) {
        if (!isset($allowed[$skip])) {
            if (!$quiet) {
                fwrite(STDERR, "Unlisted skipped test: {$skip}\n");
            }
            $failed = true;
        }
    }
    foreach (array_keys($allowed) as $method) {
        if (!in_array($method, $skips, true)) {
            if (!$quiet) {
                fwrite(STDERR, "Allowed skip missing from run: {$method}\n");
            }
            $failed = true;
        }
    }

    return $failed;
}

function demo_skips(): void
{
    $base = '<testsuites><testsuite name="Demo" tests="2" assertions="2">';
    $unlistedXml = $base . '<testcase class="Node_Test" name="test_foo"><skipped/></testcase></testsuite></testsuites>';
    $missingListedXml = $base . '</testsuite></testsuites>';

    $tmp = tempnam(sys_get_temp_dir(), 'junit');
    file_put_contents($tmp, $unlistedXml);
    $skips = parse_skips($tmp);
    if ($skips !== ['Node_Test::test_foo']) {
        fwrite(STDERR, "Skip demo: unexpected unlisted parse\n");
        exit(1);
    }
    if (!evaluate_skips($skips, [], true)) {
        fwrite(STDERR, "Skip demo: unlisted skip must fail gate\n");
        exit(1);
    }
    fwrite(STDOUT, "Skip demo: unlisted skip would fail gate\n");

    file_put_contents($tmp, $missingListedXml);
    $skips = parse_skips($tmp);
    $allow = ['Node_Test::test_missing' => true];
    if (!evaluate_skips($skips, $allow, true)) {
        fwrite(STDERR, "Skip demo: missing listed skip must fail gate\n");
        exit(1);
    }
    fwrite(STDOUT, "Skip demo: missing listed skip would fail gate\n");

    unlink($tmp);
}

demo_skips();

$allowed = load_skip_allowlist($allowPath);
$skips = parse_skips($junitPath);
if (evaluate_skips($skips, $allowed)) {
    exit(1);
}

fwrite(STDOUT, 'Skip check passed (' . count($skips) . " skipped)\n");
exit(0);
