<?php

declare(strict_types=1);

namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Model;

class DescendantsRelation extends BaseRelation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (! static::$constraints) {
            return;
        }

        $this->query->whereDescendantOf($this->parent)
        ->applyNestedSetScope();
    }

    /**
     * @param QueryBuilder $query
     * @param Model $model
     */
    protected function addEagerConstraint($query, $model)
    {
        $query->orWhereDescendantOf($model);
    }

    /**
     * @param $related
     * @return mixed
     */
    protected function matches(Model $model, $related)
    {
        return $related->isDescendantOf($model);
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
