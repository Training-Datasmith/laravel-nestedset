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
     * @var QueryBuilder
     */
    protected $query;
    /**
     * @var NodeTrait|Model
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
     * @param QueryBuilder $query
     * @param Model $model
     *
     * @return void
     */
    abstract protected function add_eager_constraint($query, $model);
    /**
     * @param $hash
     * @param $table
     * @param $lft
     * @param $rgt
     *
     * @return string
     */
    abstract protected function relation_existence_condition($hash, $table, $lft, $rgt);
    /**
     * @param array $columns
     *
     * @return mixed
     */
    public function get_relation_existence_query(Eloquent_Builder $query, Eloquent_Builder $parent, $columns = ['*'])
    {
        $query = $this->get_parent()->replicate()->new_scoped_query()->select($columns);
        $table = $query->get_model()->get_table();
        $query->from($table . ' as ' . $hash = $this->get_relation_count_hash());
        $query->get_model()->set_table($hash);
        $grammar = $query->get_query()->get_grammar();
        $condition = $this->relation_existence_condition($grammar->wrap_table($hash), $grammar->wrap_table($table), $grammar->wrap($this->parent->get_lft_name()), $grammar->wrap($this->parent->get_rgt_name()));
        return $query->where_raw($condition);
    }
    /**
     * Initialize the relation on a set of models.
     *
     * @param  string $relation
     * @return array
     */
    public function init_relation(array $models, $relation)
    {
        return $models;
    }
    /**
     * Get a relationship join table hash.
     *
     * @param  bool $incrementJoinCount
     * @return string
     */
    public function get_relation_count_hash($increment_join_count = true)
    {
        return 'nested_set_' . ($increment_join_count ? static::$self_join_count++ : static::$self_join_count);
    }
    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function get_results()
    {
        return $this->query->get();
    }
    /**
     * Set the constraints for an eager load of the relation.
     *
     *
     */
    public function add_eager_constraints(array $models): void
    {
        $this->query->where_nested(function (Builder $inner) use ($models): void {
            // We will use this query in order to apply constraints to the
            // base query builder
            $outer = $this->parent->new_query()->set_query($inner);
            foreach ($models as $model) {
                $this->add_eager_constraint($outer, $model);
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
    public function match(array $models, Eloquent_Collection $results, $relation)
    {
        foreach ($models as $model) {
            $related = $this->match_for_model($model, $results);
            $model->set_relation($relation, $related);
        }
        return $models;
    }
    /**
     *
     * @return Collection
     */
    protected function match_for_model(Model $model, Eloquent_Collection $results)
    {
        $result = $this->related->new_collection();
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
    public function get_foreign_key_name()
    {
        // Return a stub value for relation
        // resolvers which need this function.
        return Nested_Set::PARENT_ID;
    }
}