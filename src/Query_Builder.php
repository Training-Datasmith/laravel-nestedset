<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use LogicException;
class Query_Builder extends Builder
{
    /**
     * @var Node_Trait|Model
     */
    protected $model;
    /**
     * Get node's `lft` and `rgt` values.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param bool $required
     *
     * @return array
     */
    public function get_node_data($id, $required = false)
    {
        $query = $this->toBase();
        $query->where($this->model->getKeyName(), '=', $id);
        $data = $query->first([$this->model->get_lft_name(), $this->model->get_rgt_name()]);
        if (!$data && $required) {
            throw new ModelNotFoundException();
        }
        return (array) $data;
    }
    /**
     * Get plain node data.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param bool $required
     *
     * @return array
     */
    public function get_plain_node_data($id, $required = false)
    {
        return array_values($this->get_node_data($id, $required));
    }
    /**
     * Scope limits query to select just root node.
     *
     * @return $this
     */
    public function where_is_root()
    {
        $this->query->whereNull($this->model->get_parent_id_name());
        return $this;
    }
    /**
     * Limit results to ancestors of specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param bool $and_self
     *
     * @param string $boolean
     *
     * @return $this
     */
    public function where_ancestor_of($id, $and_self = false, $boolean = 'and')
    {
        $key_name = $this->model->getTable() . '.' . $this->model->getKeyName();
        $model = null;
        if (Nested_Set::is_node($id)) {
            $model = $id;
            $value = '?';
            $this->query->addBinding($id->get_rgt());
            $id = $id->getKey();
        } else {
            $value_query = $this->model->newQuery()->toBase()->select('_.' . $this->model->get_rgt_name())->from($this->model->getTable() . ' as _')->where($this->model->getKeyName(), '=', $id)->limit(1);
            $this->query->mergeBindings($value_query);
            $value = '(' . $value_query->toSql() . ')';
        }
        $this->query->whereNested(function ($inner) use ($model, $value, $and_self, $id, $key_name): void {
            [$lft, $rgt] = $this->wrapped_columns();
            $wrapped_table = $this->query->getGrammar()->wrapTable($this->model->getTable());
            $inner->whereRaw("{$value} between {$wrapped_table}.{$lft} and {$wrapped_table}.{$rgt}");
            if (!$and_self) {
                $inner->where($key_name, '<>', $id);
            }
            if ($model !== null) {
                // we apply scope only when Node was passed as $id.
                // In other cases, according to docs, query should be scoped() before calling this method
                $model->apply_nested_set_scope($inner);
            }
        }, $boolean);
        return $this;
    }
    /**
     * @param $id
     * @param bool $and_self
     *
     * @return $this
     */
    public function or_where_ancestor_of($id, $and_self = false)
    {
        return $this->where_ancestor_of($id, $and_self, 'or');
    }
    /**
     * @param $id
     *
     * @return Query_Builder
     */
    public function where_ancestor_or_self($id)
    {
        return $this->where_ancestor_of($id, true);
    }
    /**
     * Get ancestors of specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function ancestors_of($id, array $columns = ['*'])
    {
        return $this->where_ancestor_of($id)->get($columns);
    }
    /**
     * @param $id
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function ancestors_and_self($id, array $columns = ['*'])
    {
        return $this->where_ancestor_of($id, true)->get($columns);
    }
    /**
     * Add node selection statement between specified range.
     *
     * @since 2.0
     *
     * @param array $values
     * @param string $boolean
     * @param bool $not
     * @param Query $query
     *
     * @return $this
     */
    public function where_node_between($values, $boolean = 'and', $not = false, $query = null)
    {
        ($query ?? $this->query)->whereBetween($this->model->getTable() . '.' . $this->model->get_lft_name(), $values, $boolean, $not);
        return $this;
    }
    /**
     * Add node selection statement between specified range joined with `or` operator.
     *
     * @since 2.0
     *
     * @param array $values
     *
     * @return $this
     */
    public function or_where_node_between($values)
    {
        return $this->where_node_between($values, 'or');
    }
    /**
     * Add constraint statement to descendants of specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param string $boolean
     * @param bool $not
     * @param bool $and_self
     *
     * @return $this
     */
    public function where_descendant_of($id, $boolean = 'and', $not = false, $and_self = false)
    {
        $this->query->whereNested(function (Query $inner) use ($id, $and_self, $not) {
            if (Nested_Set::is_node($id)) {
                $id->apply_nested_set_scope($inner);
                $data = $id->get_bounds();
            } else {
                // we apply scope only when Node was passed as $id.
                // In other cases, according to docs, query should be scoped() before calling this method
                $data = $this->model->new_nested_set_query()->get_plain_node_data($id, true);
            }
            // Don't include the node
            if (!$and_self) {
                ++$data[0];
            }
            return $this->where_node_between($data, 'and', $not, $inner);
        }, $boolean);
        return $this;
    }
    /**
     * @param mixed $id
     *
     * @return Query_Builder
     */
    public function where_not_descendant_of($id)
    {
        return $this->where_descendant_of($id, 'and', true);
    }
    /**
     * @param mixed $id
     *
     * @return Query_Builder
     */
    public function or_where_descendant_of($id)
    {
        return $this->where_descendant_of($id, 'or');
    }
    /**
     * @param mixed $id
     *
     * @return Query_Builder
     */
    public function or_where_not_descendant_of($id)
    {
        return $this->where_descendant_of($id, 'or', true);
    }
    /**
     * @param $id
     * @param string $boolean
     * @param bool $not
     *
     * @return $this
     */
    public function where_descendant_and_self($id)
    {
        return $this->where_descendant_of($id, 'and', false, true);
    }

    public function where_descendant_or_self($id, $boolean = 'and', $not = false)
    {
        return $this->where_descendant_of($id, $boolean, $not, true);
    }
    /**
     * Get descendants of specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param bool $and_self
     * @return Collection
     */
    public function descendants_of($id, array $columns = ['*'], $and_self = false)
    {
        try {
            return $this->where_descendant_of($id, 'and', false, $and_self)->get($columns);
        } catch (ModelNotFoundException) {
            return $this->model->newCollection();
        }
    }
    /**
     * @param $id
     *
     * @return Collection
     */
    public function descendants_and_self($id, array $columns = ['*'])
    {
        return $this->descendants_of($id, $columns, true);
    }
    /**
     * @param $id
     * @param $operator
     * @param $boolean
     *
     * @return $this
     */
    protected function where_is_before_or_after($id, $operator, $boolean)
    {
        if (Nested_Set::is_node($id)) {
            $value = '?';
            $this->query->addBinding($id->get_lft());
        } else {
            $value_query = $this->model->newQuery()->toBase()->select('_n.' . $this->model->get_lft_name())->from($this->model->getTable() . ' as _n')->where('_n.' . $this->model->getKeyName(), '=', $id);
            $this->query->mergeBindings($value_query);
            $value = '(' . $value_query->toSql() . ')';
        }
        list($lft, ) = $this->wrapped_columns();
        $this->query->whereRaw("{$lft} {$operator} {$value}", [], $boolean);
        return $this;
    }
    /**
     * Constraint nodes to those that are after specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param string $boolean
     *
     * @return $this
     */
    public function where_is_after($id, $boolean = 'and')
    {
        return $this->where_is_before_or_after($id, '>', $boolean);
    }
    /**
     * Constraint nodes to those that are before specified node.
     *
     * @since 2.0
     *
     * @param mixed $id
     * @param string $boolean
     *
     * @return $this
     */
    public function where_is_before($id, $boolean = 'and')
    {
        return $this->where_is_before_or_after($id, '<', $boolean);
    }
    /**
     * @return $this
     */
    public function where_is_leaf()
    {
        [$lft, $rgt] = $this->wrapped_columns();
        return $this->whereRaw("{$lft} = {$rgt} - 1");
    }
    /**
     * @return Collection
     */
    public function leaves(array $columns = ['*'])
    {
        return $this->where_is_leaf()->get($columns);
    }
    /**
     * Include depth level into the result.
     *
     * @param string $as
     *
     * @return $this
     */
    public function with_depth($as = 'depth')
    {
        if ($this->query->columns === null) {
            $this->query->columns = ['*'];
        }
        $table = $this->wrapped_table();
        [$lft, $rgt] = $this->wrapped_columns();
        $alias = '_d';
        $wrapped_alias = $this->query->getGrammar()->wrapTable($alias);
        $query = $this->model->new_scoped_query('_d')->toBase()->selectRaw('count(1) - 1')->from($this->model->getTable() . ' as ' . $alias)->whereRaw("{$table}.{$lft} between {$wrapped_alias}.{$lft} and {$wrapped_alias}.{$rgt}");
        $this->query->selectSub($query, $as);
        return $this;
    }
    /**
     * Get wrapped `lft` and `rgt` column names.
     *
     * @since 2.0
     *
     * @return array
     */
    protected function wrapped_columns()
    {
        $grammar = $this->query->getGrammar();
        return [$grammar->wrap($this->model->get_lft_name()), $grammar->wrap($this->model->get_rgt_name())];
    }
    /**
     * Get a wrapped table name.
     *
     * @since 2.0
     *
     * @return string
     */
    protected function wrapped_table()
    {
        return $this->query->getGrammar()->wrapTable($this->getQuery()->from);
    }
    /**
     * Wrap model's key name.
     *
     * @since 2.0
     *
     * @return string
     */
    protected function wrapped_key()
    {
        return $this->query->getGrammar()->wrap($this->model->getKeyName());
    }
    /**
     * Exclude root node from the result.
     *
     * @return $this
     */
    public function without_root()
    {
        $this->query->whereNotNull($this->model->get_parent_id_name());
        return $this;
    }
    /**
     * Equivalent of `withoutRoot`.
     *
     * @since 2.0
     * @deprecated since v4.1
     *
     * @return $this
     */
    public function has_parent()
    {
        $this->query->whereNotNull($this->model->get_parent_id_name());
        return $this;
    }
    /**
     * Get only nodes that have children.
     *
     * @since 2.0
     * @deprecated since v4.1
     *
     * @return $this
     */
    public function has_children()
    {
        [$lft, $rgt] = $this->wrapped_columns();
        $this->query->whereRaw("{$rgt} > {$lft} + 1");
        return $this;
    }
    /**
     * Order by node position.
     *
     * @param string $dir
     *
     * @return $this
     */
    public function default_order($dir = 'asc')
    {
        $this->query->orders = null;
        $this->query->orderBy($this->model->get_lft_name(), $dir);
        return $this;
    }
    /**
     * Order by reversed node position.
     *
     * @return $this
     */
    public function reversed()
    {
        return $this->default_order('desc');
    }
    /**
     * Move a node to the new position.
     *
     * @param mixed $key
     * @param int $position
     *
     * @return int
     */
    public function move_node($key, $position)
    {
        [$lft, $rgt] = $this->model->new_nested_set_query()->get_plain_node_data($key, true);
        if ($lft < $position && $position <= $rgt) {
            throw new LogicException('Cannot move node into itself.');
        }
        // Get boundaries of nodes that should be moved to new position
        $from = min($lft, $position);
        $to = max($rgt, $position - 1);
        // The height of node that is being moved
        $height = $rgt - $lft + 1;
        // The distance that our node will travel to reach it's destination
        $distance = $to - $from + 1 - $height;
        // If no distance to travel, just return
        if ($distance === 0) {
            return 0;
        }
        if ($position > $lft) {
            $height *= -1;
        } else {
            $distance *= -1;
        }
        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance');
        $boundary = [$from, $to];
        $query = $this->toBase()->where(function (Query $inner) use ($boundary): void {
            $inner->whereBetween($this->model->get_lft_name(), $boundary);
            $inner->orWhereBetween($this->model->get_rgt_name(), $boundary);
        });
        return $query->update($this->patch($params));
    }
    /**
     * Make or remove gap in the tree. Negative height will remove gap.
     *
     * @since 2.0
     *
     * @param int $cut
     * @param int $height
     *
     * @return int
     */
    public function make_gap($cut, $height)
    {
        $params = compact('cut', 'height');
        $query = $this->toBase()->whereNested(function (Query $inner) use ($cut): void {
            $inner->where($this->model->get_lft_name(), '>=', $cut);
            $inner->orWhere($this->model->get_rgt_name(), '>=', $cut);
        });
        return $query->update($this->patch($params));
    }
    /**
     * Get patch for columns.
     *
     * @since 2.0
     *
     *
     * @return array
     */
    protected function patch(array $params)
    {
        $grammar = $this->query->getGrammar();
        $columns = [];
        foreach ([$this->model->get_lft_name(), $this->model->get_rgt_name()] as $col) {
            $columns[$col] = $this->column_patch($grammar->wrap($col), $params);
        }
        return $columns;
    }
    /**
     * Get patch for single column.
     *
     * @since 2.0
     *
     * @param string $col
     *
     * @return string
     */
    protected function column_patch($col, array $params)
    {
        extract($params);
        /** @var int $height */
        if ($height > 0) {
            $height = '+' . $height;
        }
        if (isset($cut)) {
            return new Expression("case when {$col} >= {$cut} then {$col}{$height} else {$col} end");
        }
        /** @var int $distance */
        /** @var int $lft */
        /** @var int $rgt */
        /** @var int $from */
        /** @var int $to */
        if ($distance > 0) {
            $distance = '+' . $distance;
        }
        return new Expression('case ' . "when {$col} between {$lft} and {$rgt} then {$col}{$distance} " . "when {$col} between {$from} and {$to} then {$col}{$height} " . "else {$col} end");
    }
    /**
     * Get statistics of errors of the tree.
     *
     * @since 2.0
     *
     * @return array
     */
    public function count_errors()
    {
        $checks = [];
        // Check if lft and rgt values are ok
        $checks['oddness'] = $this->get_odness_query();
        // Check if lft and rgt values are unique
        $checks['duplicates'] = $this->get_duplicates_query();
        // Check if parent_id is set correctly
        $checks['wrong_parent'] = $this->get_wrong_parent_query();
        // Check for nodes that have missing parent
        $checks['missing_parent'] = $this->get_missing_parent_query();
        $query = $this->query->newQuery();
        foreach ($checks as $key => $inner) {
            $inner->selectRaw('count(1)');
            $query->selectSub($inner, $key);
        }
        return (array) $query->first();
    }
    /**
     * @return BaseQueryBuilder
     */
    protected function get_odness_query()
    {
        return $this->model->new_nested_set_query()->toBase()->whereNested(function (Query $inner): void {
            [$lft, $rgt] = $this->wrapped_columns();
            $inner->whereRaw("{$lft} >= {$rgt}")->orWhereRaw("({$rgt} - {$lft}) % 2 = 0");
        });
    }
    /**
     * @return BaseQueryBuilder
     */
    protected function get_duplicates_query()
    {
        $table = $this->wrapped_table();
        $key_name = $this->wrapped_key();
        $first_alias = 'c1';
        $second_alias = 'c2';
        $wa_first = $this->query->getGrammar()->wrapTable($first_alias);
        $wa_second = $this->query->getGrammar()->wrapTable($second_alias);
        $query = $this->model->new_nested_set_query($first_alias)->toBase()->from($this->query->raw("{$table} as {$wa_first}, {$table} {$wa_second}"))->whereRaw("{$wa_first}.{$key_name} < {$wa_second}.{$key_name}")->whereNested(function (Query $inner) use ($wa_first, $wa_second): void {
            [$lft, $rgt] = $this->wrapped_columns();
            $inner->orWhereRaw("{$wa_first}.{$lft}={$wa_second}.{$lft}")->orWhereRaw("{$wa_first}.{$rgt}={$wa_second}.{$rgt}")->orWhereRaw("{$wa_first}.{$lft}={$wa_second}.{$rgt}")->orWhereRaw("{$wa_first}.{$rgt}={$wa_second}.{$lft}");
        });
        return $this->model->apply_nested_set_scope($query, $second_alias);
    }
    /**
     * @return BaseQueryBuilder
     */
    protected function get_wrong_parent_query()
    {
        $table = $this->wrapped_table();
        $key_name = $this->wrapped_key();
        $grammar = $this->query->getGrammar();
        $parent_id_name = $grammar->wrap($this->model->get_parent_id_name());
        $parent_alias = 'p';
        $child_alias = 'c';
        $interm_alias = 'i';
        $wa_parent = $grammar->wrapTable($parent_alias);
        $wa_child = $grammar->wrapTable($child_alias);
        $wa_interm = $grammar->wrapTable($interm_alias);
        $query = $this->model->new_nested_set_query('c')->toBase()->from($this->query->raw("{$table} as {$wa_child}, {$table} as {$wa_parent}, {$table} as {$wa_interm}"))->whereRaw("{$wa_child}.{$parent_id_name}={$wa_parent}.{$key_name}")->whereRaw("{$wa_interm}.{$key_name} <> {$wa_parent}.{$key_name}")->whereRaw("{$wa_interm}.{$key_name} <> {$wa_child}.{$key_name}")->whereNested(function (Query $inner) use ($wa_interm, $wa_child, $wa_parent): void {
            [$lft, $rgt] = $this->wrapped_columns();
            $inner->whereRaw("{$wa_child}.{$lft} not between {$wa_parent}.{$lft} and {$wa_parent}.{$rgt}")->orWhereRaw("{$wa_child}.{$lft} between {$wa_interm}.{$lft} and {$wa_interm}.{$rgt}")->whereRaw("{$wa_interm}.{$lft} between {$wa_parent}.{$lft} and {$wa_parent}.{$rgt}");
        });
        $this->model->apply_nested_set_scope($query, $parent_alias);
        $this->model->apply_nested_set_scope($query, $interm_alias);
        return $query;
    }
    /**
     * @return $this
     */
    protected function get_missing_parent_query()
    {
        return $this->model->new_nested_set_query()->toBase()->whereNested(function (Query $inner): void {
            $grammar = $this->query->getGrammar();
            $table = $this->wrapped_table();
            $key_name = $this->wrapped_key();
            $parent_id_name = $grammar->wrap($this->model->get_parent_id_name());
            $alias = 'p';
            $wrapped_alias = $grammar->wrapTable($alias);
            $exists_check = $this->model->new_nested_set_query()->toBase()->selectRaw('1')->from($this->query->raw("{$table} as {$wrapped_alias}"))->whereRaw("{$table}.{$parent_id_name} = {$wrapped_alias}.{$key_name}")->limit(1);
            $this->model->apply_nested_set_scope($exists_check, $alias);
            $inner->whereRaw("{$parent_id_name} is not null")->addWhereExistsQuery($exists_check, 'and', true);
        });
    }
    /**
     * Get the number of total errors of the tree.
     *
     * @since 2.0
     *
     * @return int
     */
    public function get_total_errors()
    {
        return array_sum($this->count_errors());
    }
    /**
     * Get whether the tree is broken.
     *
     * @since 2.0
     *
     * @return bool
     */
    public function is_broken()
    {
        return $this->get_total_errors() > 0;
    }
    /**
     * Fixes the tree based on parentage info.
     *
     * Nodes with invalid parent are saved as roots.
     *
     * @param null|Node_Trait|Model $root
     *
     * @return int The number of changed nodes
     */
    public function fix_tree($root = null)
    {
        $columns = [$this->model->getKeyName(), $this->model->get_parent_id_name(), $this->model->get_lft_name(), $this->model->get_rgt_name()];
        $dictionary = $this->model->new_nested_set_query()->when($root, fn(self $query) => $query->where_descendant_of($root))->default_order()->get($columns)->groupBy($this->model->get_parent_id_name())->all();
        return $this->fix_nodes($dictionary, $root);
    }
    /**
     * @param Node_Trait|Model $root
     *
     * @return int
     */
    public function fix_subtree($root)
    {
        return $this->fix_tree($root);
    }
    /**
     * @param Node_Trait|Model|null $parent
     * @return int
     */
    protected function fix_nodes(array &$dictionary, $parent = null)
    {
        $parent_id = $parent ? $parent->getKey() : null;
        $cut = $parent ? $parent->get_lft() + 1 : 1;
        $updated = [];
        $moved = 0;
        $cut = self::reorder_nodes($dictionary, $updated, $parent_id, $cut);
        // Save nodes that have invalid parent as roots
        while (!empty($dictionary)) {
            $dictionary[null] = reset($dictionary);
            unset($dictionary[key($dictionary)]);
            $cut = self::reorder_nodes($dictionary, $updated, $parent_id, $cut);
        }
        if ($parent && ($grown = $cut - $parent->get_rgt()) != 0) {
            $moved = $this->model->new_scoped_query()->make_gap($parent->get_rgt() + 1, $grown);
            $updated[] = $parent->raw_node($parent->get_lft(), $cut, $parent->get_parent_id());
        }
        foreach ($updated as $model) {
            $model->save();
        }
        return count($updated) + $moved;
    }
    /**
     * @param $parent_id
     * @param int $cut
     * @return int
     * @internal param int $fixed
     */
    protected static function reorder_nodes(array &$dictionary, array &$updated, $parent_id = null, $cut = 1)
    {
        if (!isset($dictionary[$parent_id])) {
            return $cut;
        }
        /** @var Model|Node_Trait $model */
        foreach ($dictionary[$parent_id] as $model) {
            $lft = $cut;
            $cut = self::reorder_nodes($dictionary, $updated, $model->getKey(), $cut + 1);
            if ($model->raw_node($lft, $cut, $parent_id)->isDirty()) {
                $updated[] = $model;
            }
            ++$cut;
        }
        unset($dictionary[$parent_id]);
        return $cut;
    }
    /**
     * Rebuild the tree based on raw data.
     *
     * If item data does not contain primary key, new node will be created.
     *
     * @param bool $delete Whether to delete nodes that exists but not in the data
     *                     array
     *
     * @return int
     */
    public function rebuild_tree(array $data, $delete = false, $root = null)
    {
        if ($this->model->uses_soft_delete()) {
            $this->withTrashed();
        }
        $existing = $this->when($root, fn(self $query) => $query->where_descendant_of($root))->get()->getDictionary();
        $dictionary = [];
        $parent_id = $root ? $root->getKey() : null;
        $this->build_rebuild_dictionary($dictionary, $data, $existing, $parent_id);
        /** @var Model|Node_Trait $model */
        if (!empty($existing)) {
            if ($delete && !$this->model->uses_soft_delete()) {
                $this->model->new_scoped_query()->whereIn($this->model->getKeyName(), array_keys($existing))->delete();
            } else {
                foreach ($existing as $model) {
                    $dictionary[$model->get_parent_id()][] = $model;
                    if ($delete && $this->model->uses_soft_delete() && !$model->{$model->getDeletedAtColumn()}) {
                        $time = $this->model->fromDateTime($this->model->freshTimestamp());
                        $model->{$model->getDeletedAtColumn()} = $time;
                    }
                }
            }
        }
        return $this->fix_nodes($dictionary, $root);
    }
    /**
     * @param $root
     * @param bool $delete
     * @return int
     */
    public function rebuild_subtree($root, array $data, $delete = false)
    {
        return $this->rebuild_tree($data, $delete, $root);
    }
    /**
     * @param mixed $parent_id
     */
    protected function build_rebuild_dictionary(array &$dictionary, array $data, array &$existing, $parent_id = null)
    {
        $key_name = $this->model->getKeyName();
        foreach ($data as $item_data) {
            /** @var Node_Trait|Model $model */
            if (!isset($item_data[$key_name])) {
                $model = $this->model->newInstance($this->model->getAttributes());
                // Set some values that will be fixed later
                $model->raw_node(0, 0, $parent_id);
            } else {
                if (!isset($existing[$key = $item_data[$key_name]])) {
                    throw new ModelNotFoundException();
                }
                $model = $existing[$key];
                // Disable any tree actions
                $model->raw_node($model->get_lft(), $model->get_rgt(), $parent_id);
                unset($existing[$key]);
            }
            $model->fill(Arr::except($item_data, 'children'))->save();
            $dictionary[$parent_id][] = $model;
            if (!isset($item_data['children'])) {
                continue;
            }
            $this->build_rebuild_dictionary($dictionary, $item_data['children'], $existing, $model->getKey());
        }
    }
    /**
     * @param string|null $table
     *
     * @return $this
     */
    public function apply_nested_set_scope($table = null)
    {
        return $this->model->apply_nested_set_scope($this, $table);
    }
    /**
     * Get the root node.
     *
     *
     * @return self
     */
    public function root(array $columns = ['*'])
    {
        return $this->where_is_root()->first($columns);
    }
}