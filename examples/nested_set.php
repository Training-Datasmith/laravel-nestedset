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

use Kalnoy\Nestedset\Node_Trait;
use Kalnoy\Nestedset\Collection;
use Kalnoy\Nestedset\Nested_Set;

// --- Show Nested_Set constants ---
echo "Nested_Set column names:\n";
echo "  LFT: "       . Nested_Set::LFT       . "\n";
echo "  RGT: "       . Nested_Set::RGT       . "\n";
echo "  PARENT_ID: " . Nested_Set::PARENT_ID . "\n\n";

// --- Demonstrate collection to_tree() on a flat list of nodes ---
// In a real app: Category::get()->to_tree() — the nodes come from DB with _lft/_rgt set.
// We show the Collection class exists and is importable.
$collection = new Collection();
echo "Empty collection to_tree() returns: " . get_class($collection->to_tree()) . "\n\n";

// --- Show Node_Trait integration point ---
echo "Node_Trait available as: " . Node_Trait::class . "\n";
echo "Typical model setup:\n";
echo <<<'PHP'
    class Category extends Model
    {
        use \Kalnoy\Nestedset\Node_Trait;
    }

    // Append a child:
    $parent->append_node($child);

    // Build full tree:
    Category::get()->to_tree();

    // Get ancestors of a node:
    $node->ancestors()->get();

    // Get descendants:
    $node->descendants()->get();
PHP;
echo "\n";
