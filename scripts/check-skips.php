#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: check-skips.php path/to/phpunit.junit.xml\n");
    exit(2);
}

$junitPath = $argv[1];
$allowPath = __DIR__ . '/allowed-skips.txt';

/** @var array<string, true> $allowed */
$allowed = [];
foreach (file($allowPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $allowed[$line] = true;
}

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

function demo_skips(): void
{
    $base = '<testsuites><testsuite name="Demo" tests="2" assertions="2">';
    $unlisted = $base . '<testcase class="Node_Test" name="test_foo"><skipped/></testcase></testsuite></testsuites>';
    $listedMissing = $base . '</testsuite></testsuites>';

    $tmp = tempnam(sys_get_temp_dir(), 'junit');
    file_put_contents($tmp, $unlisted);
    $skips = parse_skips($tmp);
    if ($skips !== ['Node_Test::test_foo']) {
        fwrite(STDERR, "Skip demo: unexpected unlisted parse\n");
        exit(1);
    }
    $allow = [];
    if (!isset($allow['Node_Test::test_foo'])) {
        fwrite(STDOUT, "Skip demo: unlisted skip would fail gate\n");
    }

    file_put_contents($tmp, $listedMissing);
    $allow = ['Node_Test::test_missing' => true];
    $skips = parse_skips($tmp);
    if ($skips === [] && isset($allow['Node_Test::test_missing'])) {
        fwrite(STDOUT, "Skip demo: missing listed skip would fail gate\n");
    }
    unlink($tmp);
}

demo_skips();

$skips = parse_skips($junitPath);
$failed = false;
foreach ($skips as $skip) {
    if (!isset($allowed[$skip])) {
        fwrite(STDERR, "Unlisted skipped test: {$skip}\n");
        $failed = true;
    }
}
foreach (array_keys($allowed) as $method) {
    if (!in_array($method, $skips, true)) {
        fwrite(STDERR, "Allowed skip missing from run: {$method}\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, 'Skip check passed (' . count($skips) . " skipped)\n");
exit(0);
