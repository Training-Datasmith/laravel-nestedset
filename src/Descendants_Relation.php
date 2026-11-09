<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;
class Descendants_Relation extends Base_Relation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (!static::$constraints) {
            return;
        }
        $this->query->where_descendant_of($this->parent)->apply_nested_set_scope();
    }
    /**
     * @param Query_Builder $query
     * @param Model $model
     */
    protected function addEagerConstraint($query, $model)
    {
        $query->or_where_descendant_of($model);
    }
    /**
     * @param $related
     * @return mixed
     */
    protected function matches(Model $model, $related)
    {
        return $related->is_descendant_of($model);
    }
    /**
     * @param $hash
     * @param $table
     * @param $lft
     * @param $rgt
     */
    protected function relationExistenceCondition($hash, $table, $lft, $rgt): string
    {
        return "{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}";
    }
}