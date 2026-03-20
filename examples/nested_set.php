<?php

declare(strict_types=1);

/**
 * Example: nested set (MPTT) tree operations with laravel-nestedset.
 *
 * This demonstrates the API without a real database — actual usage requires
 * a Laravel application with a nodes table that has _lft, _rgt, parent_id columns.
 *
 * Run from the laravel-nestedset project root:
 *   php examples/nested_set.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Kalnoy\Nestedset\NodeTrait;
use Kalnoy\Nestedset\Collection;
use Kalnoy\Nestedset\NestedSet;

// --- Show NestedSet constants ---
echo "NestedSet column names:\n";
echo "  LFT: "       . NestedSet::LFT       . "\n";
echo "  RGT: "       . NestedSet::RGT       . "\n";
echo "  PARENT_ID: " . NestedSet::PARENT_ID . "\n\n";

// --- Demonstrate collection toTree() on a flat list of nodes ---
// In a real app: Category::get()->toTree() — the nodes come from DB with _lft/_rgt set.
// We show the Collection class exists and is importable.
$collection = new Collection();
echo "Empty collection toTree() returns: " . get_class($collection->toTree()) . "\n\n";

// --- Show NodeTrait integration point ---
echo "NodeTrait available as: " . NodeTrait::class . "\n";
echo "Typical model setup:\n";
echo <<<'PHP'
    class Category extends Model
    {
        use \Kalnoy\Nestedset\NodeTrait;
    }

    // Append a child:
    $parent->append_node($child);

    // Build full tree:
    Category::get()->toTree();

    // Get ancestors of a node:
    $node->ancestors()->get();

    // Get descendants:
    $node->descendants()->get();
PHP;
echo "\n";
