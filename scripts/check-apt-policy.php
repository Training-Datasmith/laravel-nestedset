#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;

$dockerfiles = glob($root . '/docker/**/Dockerfile', GLOB_BRACE) ?: [];
$dockerfiles = array_merge($dockerfiles, glob($root . '/docker/*/Dockerfile') ?: []);

foreach (array_unique($dockerfiles) as $dockerfile) {
    $content = file_get_contents($dockerfile);
    if ($content === false) {
        fwrite(STDERR, "Cannot read {$dockerfile}\n");
        $failed = true;
        continue;
    }
    if (preg_match('/\bhttp:\/\/(?!localhost)/', $content)) {
        fwrite(STDERR, "{$dockerfile}: insecure http:// apt or download URL\n");
        $failed = true;
    }
    if (preg_match('/AllowUnauthenticated|AllowInsecureRepositories|trusted=yes|Acquire::AllowInsecureRepositories/i', $content)) {
        fwrite(STDERR, "{$dockerfile}: apt signature bypass directive\n");
        $failed = true;
    }
    if (preg_match('/Acquire::Check-Valid-Until\s+"false"/', $content) && !str_contains($content, 'archive.debian.org')) {
        fwrite(STDERR, "{$dockerfile}: global Check-Valid-Until false outside archive.debian.org\n");
        $failed = true;
    }
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
  $rel = substr($file->getPathname(), strlen($root) + 1);
    if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, '.git/')) {
        continue;
    }
    if (!preg_match('/\.(php|sh|yml|yaml|md|xml|json)$/', $rel)) {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        continue;
    }
    if (preg_match('/display_errors\s*=/', $content)) {
        fwrite(STDERR, "{$rel}: display_errors override\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "apt/docker policy check passed\n");
exit(0);
