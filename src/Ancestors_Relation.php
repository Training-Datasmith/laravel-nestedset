<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;
class Ancestors_Relation extends Base_Relation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (!static::$constraints) {
            return;
        }
        $this->query->where_ancestor_of($this->parent)->apply_nested_set_scope();
    }
    /**
     * @param $related
     * @return bool
     */
    protected function matches(Model $model, $related)
    {
        return $related->is_ancestor_of($model);
    }
    /**
     * @param Query_Builder $query
     * @param Model $model
     *
     * @return void
     */
    protected function addEagerConstraint($query, $model)
    {
        $query->or_where_ancestor_of($model);
    }
    /**
     * @param $hash
     * @param $table
     * @param $lft
     * @param $rgt
     */
    protected function relationExistenceCondition($hash, $table, $lft, $rgt): string
    {
        $key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());
        return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and {$table}.{$key} <> {$hash}.{$key}";
    }
}