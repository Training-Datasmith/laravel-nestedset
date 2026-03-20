<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;
class Ancestors_Relation extends Base_Relation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function add_constraints(): void
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
     * @param QueryBuilder $query
     * @param Model $model
     *
     * @return void
     */
    protected function add_eager_constraint($query, $model)
    {
        $query->or_where_ancestor_of($model);
    }
    /**
     * @param $hash
     * @param $table
     * @param $lft
     * @param $rgt
     */
    protected function relation_existence_condition($hash, $table, $lft, $rgt): string
    {
        $key = $this->get_base_query()->get_grammar()->wrap($this->parent->get_key_name());
        return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and {$table}.{$key} <> {$hash}.{$key}";
    }
}