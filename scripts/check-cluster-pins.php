#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** @var array<string, array{alias: string, reference: string, version: string}> $expected */
$expected = [
    'psr/clock' => ['alias' => '1.0.0', 'reference' => '3d3053da340ec84ff02c3765946a19ac021e0b1d', 'version' => 'dev-remote-test-2026-10-07'],
    'psr/container' => ['alias' => '2.0.2', 'reference' => '57d8cac2ff3db652075e2d6e44e0ede70720e8e5', 'version' => 'dev-remote-test-2026-10-07'],
    'psr/simple-cache' => ['alias' => '3.0.0', 'reference' => 'd03ac6ca8193e8b800f667a63745f1db7806f412', 'version' => 'dev-remote-test-2026-11-08'],
    'symfony/polyfill-ctype' => ['alias' => '1.33.0', 'reference' => '178d8180c34087830dbcdb5e67fa8b61d28016ac', 'version' => 'dev-remote-test-2026-10-07'],
    'symfony/polyfill-intl-grapheme' => ['alias' => '1.33.0', 'reference' => '137f223b5cb3ba256d8c52adea5e0e8594708e71', 'version' => 'dev-remote-test-2026-10-07'],
    'symfony/polyfill-intl-normalizer' => ['alias' => '1.33.0', 'reference' => 'c6839534bfcaf6a8b566e44e5bb971e672a678cd', 'version' => 'dev-remote-test-2026-10-07'],
    'symfony/polyfill-mbstring' => ['alias' => '1.33.0', 'reference' => '2cecae254e4ffc2c20ac379c5cd23d91c82109df', 'version' => 'dev-remote-test-2026-10-07'],
    'voku/portable-ascii' => ['alias' => '2.0.3', 'reference' => '7671a909020d1c6a25dcb2f6e65e8b1776eaa2f5', 'version' => 'dev-remote-test-2026-11-09'],
];

/** @var array<string, list<string>> $packagistOnly */
$packagistOnly = [
    'phpunit/phpunit' => [
        'https://github.com/sebastianbergmann/phpunit.git',
        'https://github.com/sebastianbergmann/phpunit/',
    ],
    'nesbot/carbon' => [
        'https://github.com/briannesbitt/Carbon.git',
        'https://github.com/CarbonPHP/carbon.git',
    ],
    'doctrine/inflector' => [
        'https://github.com/doctrine/inflector.git',
    ],
];

/** @var array<string, list<string>> $forbiddenIndexReferences */
$forbiddenIndexReferences = [
    'phpunit/phpunit' => [
        'a37d66fabf01af0eedabffe271b70dbe71723001',
        '698cbbc1e75a1ef1968f9cbfec89be056b288d0f',
    ],
    'nesbot/carbon' => [
        'd912961eaba732f841601cba835730f2926a7820',
    ],
    'doctrine/inflector' => [
        '37c71ea111d7bd4fc42a4402ccdaaad8a2c9e405',
        '7a4d5f45d684553363a45a6805dbb0a834be6f17',
    ],
];

/**
 * @param array<string, mixed> $installedPhp
 * @param array<string, mixed> $installedJson
 * @param array<string, array{alias: string, reference: string, version: string}> $expectedMap
 * @return list<array{side: string, package: string, message: string}>
 */
function evaluate_cluster_pins(array $installedPhp, array $installedJson, array $expectedMap): array
{
    $errors = [];

    $versions = $installedPhp['versions'] ?? [];
    $jsonPackages = [];
    foreach (array_merge($installedJson['packages'] ?? [], $installedJson['packages-dev'] ?? []) as $package) {
        if (!isset($package['name'])) {
            continue;
        }
        $jsonPackages[$package['name']] = $package;
    }

    foreach ($expectedMap as $package => $want) {
        if (!isset($versions[$package])) {
            $errors[] = ['side' => 'installed.php', 'package' => $package, 'message' => 'package is not installed'];
            continue;
        }
        $entry = $versions[$package];
        $aliases = $entry['aliases'] ?? [];
        if (!in_array($want['alias'], $aliases, true)) {
            $errors[] = [
                'side' => 'installed.php',
                'package' => $package,
                'message' => sprintf('alias mismatch (expected %s in %s)', $want['alias'], json_encode($aliases)),
            ];
        }
        $reference = isset($entry['reference']) ? strtolower((string) $entry['reference']) : '';
        if ($reference === '') {
            $errors[] = ['side' => 'installed.php', 'package' => $package, 'message' => 'missing reference'];
        } elseif ($reference !== strtolower($want['reference'])) {
            $errors[] = [
                'side' => 'installed.php',
                'package' => $package,
                'message' => "reference {$reference} != expected {$want['reference']}",
            ];
        }

        if (!isset($jsonPackages[$package])) {
            $errors[] = ['side' => 'installed.json', 'package' => $package, 'message' => 'package is not installed'];
            continue;
        }
        $json = $jsonPackages[$package];
        $jsonRef = isset($json['source']['reference']) ? strtolower((string) $json['source']['reference']) : '';
        if ($jsonRef === '') {
            $errors[] = ['side' => 'installed.json', 'package' => $package, 'message' => 'missing source.reference'];
        } elseif ($jsonRef !== strtolower($want['reference'])) {
            $errors[] = [
                'side' => 'installed.json',
                'package' => $package,
                'message' => "source.reference {$jsonRef} != expected {$want['reference']}",
            ];
        }
        $jsonVersion = (string) ($json['version'] ?? '');
        if ($jsonVersion !== $want['version']) {
            $errors[] = [
                'side' => 'installed.json',
                'package' => $package,
                'message' => "version {$jsonVersion} != expected {$want['version']}",
            ];
        }
    }

    return $errors;
}

/**
 * @return array{installedPhp: array<string, mixed>, installedJson: array<string, mixed>}
 */
function load_vendor_pin_sources(string $root): array
{
    $installedPhpPath = $root . '/vendor/composer/installed.php';
    $installedJsonPath = $root . '/vendor/composer/installed.json';
    if (!is_readable($installedPhpPath)) {
        fwrite(STDERR, "Missing {$installedPhpPath}; run composer update first.\n");
        exit(1);
    }
    if (!is_readable($installedJsonPath)) {
        fwrite(STDERR, "Missing {$installedJsonPath}; run composer update first.\n");
        exit(1);
    }

    /** @var array<string, mixed> $installedPhp */
    $installedPhp = require $installedPhpPath;
    $raw = file_get_contents($installedJsonPath);
    if ($raw === false) {
        fwrite(STDERR, "Could not read {$installedJsonPath}.\n");
        exit(1);
    }
    /** @var array<string, mixed>|null $installedJson */
    $installedJson = json_decode($raw, true);
    if (!is_array($installedJson)) {
        fwrite(STDERR, "Invalid JSON in {$installedJsonPath}.\n");
        exit(1);
    }

    return ['installedPhp' => $installedPhp, 'installedJson' => $installedJson];
}

/**
 * @return array{installedPhp: array<string, mixed>, installedJson: array<string, mixed>}
 */
function demo_fixture(): array
{
    $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $installedPhp = [
        'root' => __DIR__,
        'versions' => [
            'demo/pkg' => [
                'pretty_version' => 'dev-demo as 1.2.3',
                'version' => 'dev-demo',
                'reference' => $sha,
                'aliases' => ['1.2.3'],
            ],
        ],
    ];
    $installedJson = [
        'packages' => [],
        'packages-dev' => [
            [
                'name' => 'demo/pkg',
                'version' => 'dev-demo',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/Training-Datasmith/demo.git',
                    'reference' => $sha,
                ],
            ],
        ],
    ];

    return ['installedPhp' => $installedPhp, 'installedJson' => $installedJson];
}

function run_wrong_sha_demos(): void
{
    $fixture = demo_fixture();
    $map = [
        'demo/pkg' => ['alias' => '1.2.3', 'reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'version' => 'dev-demo'],
    ];

    $phpWrong = $fixture;
    $phpWrong['installedPhp']['versions']['demo/pkg']['reference'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    $errsPhp = evaluate_cluster_pins($phpWrong['installedPhp'], $phpWrong['installedJson'], $map);
    $phpSides = array_unique(array_column($errsPhp, 'side'));
    if ($errsPhp === [] || $phpSides !== ['installed.php']) {
        fwrite(STDERR, "Wrong-SHA demo: installed.php mutation must fail only installed.php side.\n");
        exit(1);
    }

    $jsonWrong = $fixture;
    $jsonWrong['installedJson']['packages-dev'][0]['source']['reference'] = 'cccccccccccccccccccccccccccccccccccccccc';
    $errsJson = evaluate_cluster_pins($jsonWrong['installedPhp'], $jsonWrong['installedJson'], $map);
    $jsonSides = array_unique(array_column($errsJson, 'side'));
    if ($jsonSides === [] || in_array('installed.php', $jsonSides, true)) {
        fwrite(STDERR, "Wrong-SHA demo: installed.json mutation must fail only installed.json side.\n");
        exit(1);
    }

    $versionWrong = $fixture;
    $versionWrong['installedJson']['packages-dev'][0]['version'] = 'dev-wrong';
    $errsVer = evaluate_cluster_pins($versionWrong['installedPhp'], $versionWrong['installedJson'], $map);
    if ($errsVer === []) {
        fwrite(STDERR, "Wrong-SHA demo: installed.json version mutation must fail.\n");
        exit(1);
    }

    $stub_never_reports = static fn (array $installedPhp, array $installedJson, array $expectedMap): array => [];
    if ($stub_never_reports($phpWrong['installedPhp'], $phpWrong['installedJson'], $map) !== []
        || evaluate_cluster_pins($phpWrong['installedPhp'], $phpWrong['installedJson'], $map) === []) {
        fwrite(STDERR, "Wrong-SHA demo: checker must reject mutated installed.php reference.\n");
        exit(1);
    }

    fwrite(STDOUT, "Wrong-SHA demo failed on installed.php only.\n");
    fwrite(STDOUT, "Wrong-SHA demo failed on installed.json only.\n");
}

/**
 * @param array<string, mixed> $installedPhp
 * @param array<string, mixed> $installedJson
 * @param array<string, list<string>> $packagistOnly
 * @param array<string, list<string>> $forbiddenIndexReferences
 */
function assert_packagist_gaps(
    array $installedPhp,
    array $installedJson,
    array $packagistOnly,
    array $forbiddenIndexReferences
): void {
    $failed = false;
    $phpVersions = $installedPhp['versions'] ?? [];

    foreach (array_merge($installedJson['packages'] ?? [], $installedJson['packages-dev'] ?? []) as $package) {
        $name = $package['name'] ?? null;
        if (!is_string($name) || !isset($packagistOnly[$name])) {
            continue;
        }

        $url = (string) ($package['source']['url'] ?? '');
        foreach ($packagistOnly[$name] as $allowed) {
            if (str_starts_with($url, $allowed)) {
                continue 2;
            }
        }
        if (stripos($url, 'github.com/Training-Datasmith/') !== false) {
            fwrite(STDERR, "Packagist gap violated for {$name}: Training-Datasmith source {$url}\n");
            $failed = true;
        }

        $jsonRef = strtolower((string) ($package['source']['reference'] ?? ''));
        foreach ($forbiddenIndexReferences[$name] ?? [] as $forbidden) {
            if ($jsonRef === strtolower($forbidden)) {
                fwrite(STDERR, "Packagist gap violated for {$name}: index SHA in installed.json ({$jsonRef})\n");
                $failed = true;
            }
        }

        $phpEntry = $phpVersions[$name] ?? null;
        if (is_array($phpEntry)) {
            $phpRef = strtolower((string) ($phpEntry['reference'] ?? ''));
            foreach ($forbiddenIndexReferences[$name] ?? [] as $forbidden) {
                if ($phpRef === strtolower($forbidden)) {
                    fwrite(STDERR, "Packagist gap violated for {$name}: index SHA in installed.php ({$phpRef})\n");
                    $failed = true;
                }
            }
        }
    }
    if ($failed) {
        exit(1);
    }
}

run_wrong_sha_demos();

$vendor = load_vendor_pin_sources($root);
$errors = evaluate_cluster_pins($vendor['installedPhp'], $vendor['installedJson'], $expected);
assert_packagist_gaps($vendor['installedPhp'], $vendor['installedJson'], $packagistOnly, $forbiddenIndexReferences);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(
            STDERR,
            sprintf(
                "Cluster pin check failed (%s) for %s: %s\n",
                $error['side'],
                $error['package'],
                $error['message']
            )
        );
    }
    exit(1);
}

fwrite(STDOUT, 'Cluster pin check passed for ' . count($expected) . " packages.\n");
exit(0);
