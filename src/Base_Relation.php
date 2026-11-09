<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
abstract class Base_Relation extends Relation
{
    /**
     * @var Query_Builder
     */
    protected $query;
    /**
     * @var Node_Trait|Model
     */
    protected $parent;
    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $self_join_count = 0;
    /**
     * AncestorsRelation constructor.
     */
    public function __construct(Query_Builder $builder, Model $model)
    {
        if (!Nested_Set::is_node($model)) {
            throw new InvalidArgumentException('Model must be node.');
        }
        parent::__construct($builder, $model);
    }
    /**
     * @param $related
     * @return bool
     */
    abstract protected function matches(Model $model, $related);
    /**
     * @param Query_Builder $query
     * @param Model $model
     *
     * @return void
     */
    abstract protected function addEagerConstraint($query, $model);
    /**
     * @param $hash
     * @param $table
     * @param $lft
     * @param $rgt
     *
     * @return string
     */
    abstract protected function relationExistenceCondition($hash, $table, $lft, $rgt);
    /**
     * @param array $columns
     *
     * @return mixed
     */
    public function getRelationExistenceQuery(EloquentBuilder $query, EloquentBuilder $parent, $columns = ['*'])
    {
        $query = $this->getParent()->replicate()->new_scoped_query()->select($columns);
        $table = $query->getModel()->getTable();
        $query->from($table . ' as ' . $hash = $this->getRelationCountHash());
        $query->getModel()->setTable($hash);
        $grammar = $query->getQuery()->getGrammar();
        $condition = $this->relationExistenceCondition($grammar->wrapTable($hash), $grammar->wrapTable($table), $grammar->wrap($this->parent->get_lft_name()), $grammar->wrap($this->parent->get_rgt_name()));
        return $query->whereRaw($condition);
    }
    /**
     * Initialize the relation on a set of models.
     *
     * @param  string $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        return $models;
    }
    /**
     * Get a relationship join table hash.
     *
     * @param  bool $increment_join_count
     * @return string
     */
    public function getRelationCountHash($increment_join_count = true)
    {
        return 'nested_set_' . ($increment_join_count ? static::$self_join_count++ : static::$self_join_count);
    }
    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return $this->query->get();
    }
    /**
     * Set the constraints for an eager load of the relation.
     *
     *
     */
    public function addEagerConstraints(array $models): void
    {
        $this->query->whereNested(function (Builder $inner) use ($models): void {
            // We will use this query in order to apply constraints to the
            // base query builder
            $outer = $this->parent->newQuery()->setQuery($inner);
            foreach ($models as $model) {
                $this->addEagerConstraint($outer, $model);
            }
        });
    }
    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  string $relation
     *
     * @return array
     */
    public function match(array $models, EloquentCollection $results, $relation)
    {
        foreach ($models as $model) {
            $related = $this->matchForModel($model, $results);
            $model->setRelation($relation, $related);
        }
        return $models;
    }
    /**
     *
     * @return Collection
     */
    protected function matchForModel(Model $model, EloquentCollection $results)
    {
        $result = $this->related->newCollection();
        foreach ($results as $related) {
            if ($this->matches($model, $related)) {
                $result->push($related);
            }
        }
        return $result;
    }
    /**
     * Get the plain foreign key.
     *
     * @return mixed
     */
    public function getForeignKeyName()
    {
        // Return a stub value for relation
        // resolvers which need this function.
        return Nested_Set::PARENT_ID;
    }
}