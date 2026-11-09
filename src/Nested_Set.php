<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
class Nested_Set
{
    /**
     * The name of default lft column.
     */
    public const LFT = '_lft';
    /**
     * The name of default rgt column.
     */
    public const RGT = '_rgt';
    /**
     * The name of default parent id column.
     */
    public const PARENT_ID = 'parent_id';
    /**
     * Insert direction.
     */
    public const BEFORE = 1;
    /**
     * Insert direction.
     */
    public const AFTER = 2;
    /**
     * Add default nested set columns to the table. Also create an index.
     */
    public static function columns(Blueprint $table): void
    {
        $table->unsignedInteger(self::LFT)->default(0);
        $table->unsignedInteger(self::RGT)->default(0);
        $table->unsignedInteger(self::PARENT_ID)->nullable();
        $table->index(static::get_default_columns());
    }
    /**
     * Drop nested-set columns.
     */
    public static function drop_columns(Blueprint $table): void
    {
        $columns = static::get_default_columns();
        $table->dropIndex($columns);
        $table->dropColumn($columns);
    }
    /**
     * Get a list of default columns.
     */
    public static function get_default_columns(): array
    {
        return [static::LFT, static::RGT, static::PARENT_ID];
    }
    /**
     * Replaces instanceof calls for this trait.
     *
     * @param mixed $node
     */
    public static function is_node($node): bool
    {
        return is_object($node) && in_array(Node_Trait::class, (array) $node);
    }
}