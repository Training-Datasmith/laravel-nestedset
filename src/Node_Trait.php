<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use LogicException;
trait Node_Trait
{
    /**
     * Pending operation.
     *
     * @var array
     */
    protected $pending;
    /**
     * Whether the node has moved since last save.
     *
     * @var bool
     */
    protected $moved = false;
    /**
     * @var \Carbon\Carbon
     */
    public static $deleted_at;
    /**
     * Keep track of the number of performed operations.
     *
     * @var int
     */
    public static $actions_performed = 0;
    /**
     * Sign on model events.
     */
    public static function bootNode_Trait(): void
    {
        static::saving(fn($model) => $model->call_pending_action());
        static::deleting(function ($model): void {
            // We will need fresh data to delete node safely
            $model->refresh_node();
        });
        static::deleted(function ($model): void {
            $model->delete_descendants();
        });
        if (static::uses_soft_delete()) {
            static::restoring(function ($model): void {
                static::$deleted_at = $model->{$model->getDeletedAtColumn()};
            });
            static::restored(function ($model): void {
                $model->restore_descendants(static::$deleted_at);
            });
        }
    }
    /**
     * Set an action.
     *
     * @param string $action
     *
     * @return $this
     */
    protected function set_node_action($action)
    {
        $this->pending = func_get_args();
        return $this;
    }
    /**
     * Call pending action.
     */
    protected function call_pending_action()
    {
        $this->moved = false;
        if (!$this->pending && !$this->exists) {
            $this->make_root();
        }
        if (!$this->pending) {
            return;
        }
        $token = array_shift($this->pending);
        $method = 'action_' . $token;
        $parameters = $this->pending;
        $this->pending = null;
        $this->moved = call_user_func_array([$this, $method], $parameters);
    }
    /**
     * @return bool
     */
    public static function uses_soft_delete()
    {
        static $soft_delete;
        if (is_null($soft_delete)) {
            $instance = new static();
            return $soft_delete = method_exists($instance, 'bootSoftDeletes');
        }
        return $soft_delete;
    }
    protected function action_raw(): bool
    {
        return true;
    }
    /**
     * Make a root node.
     */
    protected function action_root()
    {
        // Simplest case that do not affect other nodes.
        if (!$this->exists) {
            $cut = $this->get_lower_bound() + 1;
            $this->set_lft($cut);
            $this->set_rgt($cut + 1);
            return true;
        }
        return $this->insert_at($this->get_lower_bound() + 1);
    }
    /**
     * Get the lower bound.
     */
    protected function get_lower_bound(): int
    {
        return (int) $this->new_nested_set_query()->max($this->get_rgt_name());
    }
    /**
     * Append or prepend a node to the parent.
     *
     * @param bool $prepend
     *
     */
    protected function action_append_or_prepend(self $parent, $prepend = false): bool
    {
        $parent->refresh_node();
        $cut = $prepend ? $parent->get_lft() + 1 : $parent->get_rgt();
        if (!$this->insert_at($cut)) {
            return false;
        }
        $parent->refresh_node();
        return true;
    }
    /**
     * Apply parent model.
     *
     * @param Model|null $value
     *
     * @return $this
     */
    protected function set_parent($value)
    {
        $this->set_parent_id($value ? $value->getKey() : null)->setRelation('parent', $value);
        return $this;
    }
    /**
     * Insert node before or after another node.
     *
     * @param bool $after
     * @return bool
     */
    protected function action_before_or_after(self $node, $after = false)
    {
        $node->refresh_node();
        return $this->insert_at($after ? $node->get_rgt() + 1 : $node->get_lft());
    }
    /**
     * Refresh node's crucial attributes.
     */
    public function refresh_node(): void
    {
        if (!$this->exists || static::$actions_performed === 0) {
            return;
        }
        $attributes = $this->new_nested_set_query()->get_node_data($this->getKey());
        $this->attributes = array_merge($this->attributes, $attributes);
        //        $this->original = array_merge($this->original, $attributes);
    }
    /**
     * Relation to the parent.
     *
     * @return BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo($this::class, $this->get_parent_id_name())->setModel($this);
    }
    /**
     * Relation to children.
     *
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany($this::class, $this->get_parent_id_name())->setModel($this);
    }
    /**
     * Get query for descendants of the node.
     */
    public function descendants(): \Kalnoy\Nestedset\Descendants_Relation
    {
        return new Descendants_Relation($this->newQuery(), $this);
    }
    /**
     * Get query for siblings of the node.
     *
     * @return Query_Builder
     */
    public function siblings()
    {
        return $this->new_scoped_query()->where($this->getKeyName(), '<>', $this->getKey())->where($this->get_parent_id_name(), '=', $this->get_parent_id());
    }
    /**
     * Get the node siblings and the node itself.
     *
     * @return \Kalnoy\Nestedset\Query_Builder
     */
    public function siblings_and_self()
    {
        return $this->new_scoped_query()->where($this->get_parent_id_name(), '=', $this->get_parent_id());
    }
    /**
     * Get query for the node siblings and the node itself.
     *
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get_siblings_and_self(array $columns = ['*'])
    {
        return $this->siblings_and_self()->get($columns);
    }
    /**
     * Get query for siblings after the node.
     *
     * @return Query_Builder
     */
    public function next_siblings()
    {
        return $this->next_nodes()->where($this->get_parent_id_name(), '=', $this->get_parent_id());
    }
    /**
     * Get query for siblings before the node.
     *
     * @return Query_Builder
     */
    public function prev_siblings()
    {
        return $this->prev_nodes()->where($this->get_parent_id_name(), '=', $this->get_parent_id());
    }
    /**
     * Get query for nodes after current node.
     *
     * @return Query_Builder
     */
    public function next_nodes()
    {
        return $this->new_scoped_query()->where($this->get_lft_name(), '>', $this->get_lft());
    }
    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return Query_Builder
     */
    public function prev_nodes()
    {
        return $this->new_scoped_query()->where($this->get_lft_name(), '<', $this->get_lft());
    }
    /**
     * Get query ancestors of the node.
     */
    public function ancestors(): \Kalnoy\Nestedset\Ancestors_Relation
    {
        return new Ancestors_Relation($this->newQuery(), $this);
    }
    /**
     * Make this node a root node.
     *
     * @return $this
     */
    public function make_root()
    {
        $this->set_parent(null)->dirty_bounds();
        return $this->set_node_action('root');
    }
    /**
     * Save node as root.
     *
     * @return bool
     */
    public function save_as_root()
    {
        if ($this->exists && $this->is_root()) {
            return $this->save();
        }
        return $this->make_root()->save();
    }
    /**
     * Append and save a node.
     *
     *
     * @return bool
     */
    public function append_node(self $node)
    {
        return $node->append_to_node($this)->save();
    }
    /**
     * Prepend and save a node.
     *
     *
     * @return bool
     */
    public function prepend_node(self $node)
    {
        return $node->prepend_to_node($this)->save();
    }
    /**
     * Append a node to the new parent.
     *
     *
     * @return $this
     */
    public function append_to_node(self $parent)
    {
        return $this->append_or_prepend_to($parent);
    }
    /**
     * Prepend a node to the new parent.
     *
     *
     * @return $this
     */
    public function prepend_to_node(self $parent)
    {
        return $this->append_or_prepend_to($parent, true);
    }
    /**
     * @param bool $prepend
     * @return self
     */
    public function append_or_prepend_to(self $parent, $prepend = false)
    {
        $this->assert_node_exists($parent)->assert_not_descendant($parent)->assert_same_scope($parent);
        $this->set_parent($parent)->dirty_bounds();
        return $this->set_node_action('append_or_prepend', $parent, $prepend);
    }
    /**
     * Insert self after a node.
     *
     *
     * @return $this
     */
    public function after_node(self $node)
    {
        return $this->before_or_after_node($node, true);
    }
    /**
     * Insert self before node.
     *
     *
     * @return $this
     */
    public function before_node(self $node)
    {
        return $this->before_or_after_node($node);
    }
    /**
     * @param bool $after
     * @return self
     */
    public function before_or_after_node(self $node, $after = false)
    {
        $this->assert_node_exists($node)->assert_not_descendant($node)->assert_same_scope($node);
        if (!$this->is_sibling_of($node)) {
            $this->set_parent($node->getRelationValue('parent'));
        }
        $this->dirty_bounds();
        return $this->set_node_action('before_or_after', $node, $after);
    }
    /**
     * Insert self after a node and save.
     *
     *
     * @return bool
     */
    public function insert_after_node(self $node)
    {
        return $this->after_node($node)->save();
    }
    /**
     * Insert self before a node and save.
     *
     *
     */
    public function insert_before_node(self $node): bool
    {
        if (!$this->before_node($node)->save()) {
            return false;
        }
        // We'll update the target node since it will be moved
        $node->refresh_node();
        return true;
    }
    /**
     * @param $lft
     * @param $rgt
     * @param $parentId
     *
     * @return $this
     */
    public function raw_node($lft, $rgt, $parent_id)
    {
        $this->set_lft($lft)->set_rgt($rgt)->set_parent_id($parent_id);
        return $this->set_node_action('raw');
    }
    /**
     * Move node up given amount of positions.
     *
     * @param int $amount
     *
     * @return bool
     */
    public function up($amount = 1)
    {
        $sibling = $this->prev_siblings()->default_order('desc')->skip($amount - 1)->first();
        if (!$sibling) {
            return false;
        }
        return $this->insert_before_node($sibling);
    }
    /**
     * Move node down given amount of positions.
     *
     * @param int $amount
     *
     * @return bool
     */
    public function down($amount = 1)
    {
        $sibling = $this->next_siblings()->default_order()->skip($amount - 1)->first();
        if (!$sibling) {
            return false;
        }
        return $this->insert_after_node($sibling);
    }
    /**
     * Insert node at specific position.
     *
     * @param  int $position
     *
     * @return bool
     */
    protected function insert_at($position)
    {
        ++static::$actions_performed;
        return $this->exists ? $this->move_node($position) : $this->insert_node($position);
    }
    /**
     * Move a node to the new position.
     *
     * @since 2.0
     *
     * @param int $position
     *
     * @return int
     */
    protected function move_node($position)
    {
        $updated = $this->new_nested_set_query()->move_node($this->getKey(), $position) > 0;
        if ($updated) {
            $this->refresh_node();
        }
        return $updated;
    }
    /**
     * Insert new node at specified position.
     *
     * @since 2.0
     *
     * @param int $position
     */
    protected function insert_node($position): bool
    {
        $this->new_nested_set_query()->make_gap($position, 2);
        $height = $this->get_node_height();
        $this->set_lft($position);
        $this->set_rgt($position + $height - 1);
        return true;
    }
    /**
     * Update the tree when the node is removed physically.
     */
    protected function delete_descendants()
    {
        $lft = $this->get_lft();
        $rgt = $this->get_rgt();
        $method = $this->uses_soft_delete() && $this->forceDeleting ? 'forceDelete' : 'delete';
        $this->descendants()->{$method}();
        if ($this->hard_deleting()) {
            $height = $rgt - $lft + 1;
            $this->new_nested_set_query()->make_gap($rgt + 1, -$height);
            // In case if user wants to re-create the node
            $this->make_root();
            static::$actions_performed++;
        }
    }
    /**
     * Restore the descendants.
     *
     * @param $deletedAt
     */
    protected function restore_descendants($deleted_at)
    {
        $this->descendants()->where($this->getDeletedAtColumn(), '>=', $deleted_at)->restore();
    }
    /**
     * {@inheritdoc}
     *
     * @since 2.0
     */
    public function newEloquentBuilder($query): \Kalnoy\Nestedset\Query_Builder
    {
        return new Query_Builder($query);
    }
    /**
     * Get a new base query that includes deleted nodes.
     *
     * @since 1.1
     *
     * @return Query_Builder
     */
    public function new_nested_set_query($table = null)
    {
        $builder = $this->uses_soft_delete() ? $this->withTrashed() : $this->newQuery();
        return $this->apply_nested_set_scope($builder, $table);
    }
    /**
     * @param string|null $table
     *
     * @return Query_Builder
     */
    public function new_scoped_query($table = null)
    {
        return $this->apply_nested_set_scope($this->newQuery(), $table);
    }
    /**
     * @param mixed $query
     * @param string|null $table
     *
     * @return mixed
     */
    public function apply_nested_set_scope($query, $table = null)
    {
        if (!$scoped = $this->get_scope_attributes()) {
            return $query;
        }
        if (!$table) {
            $table = $this->getTable();
        }
        foreach ($scoped as $attribute) {
            $query->where($table . '.' . $attribute, '=', $this->getAttributeValue($attribute));
        }
        return $query;
    }
    /**
     * @return array
     */
    protected function get_scope_attributes()
    {
        return null;
    }
    /**
     * @return Query_Builder
     */
    public static function scoped(array $attributes)
    {
        $instance = new static();
        $instance->setRawAttributes($attributes);
        return $instance->new_scoped_query();
    }
    /**
     * {@inheritdoc}
     */
    public function newCollection(array $models = []): \Kalnoy\Nestedset\Collection
    {
        return new Collection($models);
    }
    /**
     * {@inheritdoc}
     *
     * Use `children` key on `$attributes` to create child nodes.
     *
     * @param self|null $parent
     */
    public static function create(array $attributes = [], ?self $parent = null)
    {
        $children = Arr::pull($attributes, 'children');
        $instance = new static($attributes);
        if ($parent) {
            $instance->append_to_node($parent);
        }
        $instance->save();
        // Now create children
        $relation = new EloquentCollection();
        foreach ((array) $children as $child) {
            $relation->add($child = static::create($child, $instance));
            $child->setRelation('parent', $instance);
        }
        $instance->refresh_node();
        return $instance->setRelation('children', $relation);
    }
    /**
     * Get node height (rgt - lft + 1).
     *
     * @return int
     */
    public function get_node_height(): int|float
    {
        if (!$this->exists) {
            return 2;
        }
        return $this->get_rgt() - $this->get_lft() + 1;
    }
    /**
     * Get number of descendant nodes.
     */
    public function get_descendant_count(): float
    {
        return ceil($this->get_node_height() / 2) - 1;
    }
    /**
     * Set the value of model's parent id key.
     *
     * Behind the scenes node is appended to found parent node.
     *
     * @param int $value
     *
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute($value): void
    {
        if ($this->get_parent_id() == $value) {
            return;
        }
        if ($value) {
            $this->append_to_node($this->new_scoped_query()->findOrFail($value));
        } else {
            $this->make_root();
        }
    }
    /**
     * Get whether node is root.
     */
    public function is_root(): bool
    {
        return is_null($this->get_parent_id());
    }
    public function is_leaf(): bool
    {
        return $this->get_lft() + 1 == $this->get_rgt();
    }
    /**
     * Get the lft key name.
     */
    public function get_lft_name(): string
    {
        return Nested_Set::LFT;
    }
    /**
     * Get the rgt key name.
     */
    public function get_rgt_name(): string
    {
        return Nested_Set::RGT;
    }
    /**
     * Get the parent id key name.
     */
    public function get_parent_id_name(): string
    {
        return Nested_Set::PARENT_ID;
    }
    /**
     * Get the value of the model's lft key.
     *
     * @return  integer
     */
    public function get_lft()
    {
        return $this->getAttributeValue($this->get_lft_name());
    }
    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function get_rgt()
    {
        return $this->getAttributeValue($this->get_rgt_name());
    }
    /**
     * Get the value of the model's parent id key.
     *
     * @return  integer
     */
    public function get_parent_id()
    {
        return $this->getAttributeValue($this->get_parent_id_name());
    }
    /**
     * Returns node that is next to current node without constraining to siblings.
     *
     * This can be either a next sibling or a next sibling of the parent node.
     *
     *
     * @return self
     */
    public function get_next_node(array $columns = ['*'])
    {
        return $this->next_nodes()->default_order()->first($columns);
    }
    /**
     * Returns node that is before current node without constraining to siblings.
     *
     * This can be either a prev sibling or parent node.
     *
     *
     * @return self
     */
    public function get_prev_node(array $columns = ['*'])
    {
        return $this->prev_nodes()->default_order('desc')->first($columns);
    }
    /**
     * @return Collection
     */
    public function get_ancestors(array $columns = ['*'])
    {
        return $this->ancestors()->get($columns);
    }
    /**
     * @return Collection|self[]
     */
    public function get_descendants(array $columns = ['*'])
    {
        return $this->descendants()->get($columns);
    }
    /**
     * @return Collection|self[]
     */
    public function get_siblings(array $columns = ['*'])
    {
        return $this->siblings()->get($columns);
    }
    /**
     * @return Collection|self[]
     */
    public function get_next_siblings(array $columns = ['*'])
    {
        return $this->next_siblings()->get($columns);
    }
    /**
     * @return Collection|self[]
     */
    public function get_prev_siblings(array $columns = ['*'])
    {
        return $this->prev_siblings()->get($columns);
    }
    /**
     * @return self
     */
    public function get_next_sibling(array $columns = ['*'])
    {
        return $this->next_siblings()->default_order()->first($columns);
    }
    /**
     * @return self
     */
    public function get_prev_sibling(array $columns = ['*'])
    {
        return $this->prev_siblings()->default_order('desc')->first($columns);
    }
    /**
     * Get whether a node is a descendant of other node.
     *
     *
     */
    public function is_descendant_of(self $other): bool
    {
        return $this->get_lft() > $other->get_lft() && $this->get_lft() < $other->get_rgt() && $this->is_same_scope($other);
    }
    /**
     * Get whether a node is itself or a descendant of other node.
     *
     *
     */
    public function is_self_or_descendant_of(self $other): bool
    {
        return $this->get_lft() >= $other->get_lft() && $this->get_lft() < $other->get_rgt();
    }
    /**
     * Get whether the node is immediate children of other node.
     *
     *
     */
    public function is_child_of(self $other): bool
    {
        return $this->get_parent_id() == $other->getKey();
    }
    /**
     * Get whether the node is a sibling of another node.
     *
     *
     */
    public function is_sibling_of(self $other): bool
    {
        return $this->get_parent_id() == $other->get_parent_id();
    }
    /**
     * Get whether the node is an ancestor of other node, including immediate parent.
     *
     *
     * @return bool
     */
    public function is_ancestor_of(self $other)
    {
        return $other->is_descendant_of($this);
    }
    /**
     * Get whether the node is itself or an ancestor of other node, including immediate parent.
     *
     *
     * @return bool
     */
    public function is_self_or_ancestor_of(self $other)
    {
        return $other->is_self_or_descendant_of($this);
    }
    /**
     * Get whether the node has moved since last save.
     *
     * @return bool
     */
    public function has_moved()
    {
        return $this->moved;
    }
    /**
     * @return array
     */
    protected function getArrayableRelations()
    {
        $result = parent::get_arrayable_relations();
        // To fix #17 when converting tree to json falling to infinite recursion.
        unset($result['parent']);
        return $result;
    }
    /**
     * Get whether user is intended to delete the model from database entirely.
     */
    protected function hard_deleting(): bool
    {
        if (!$this->uses_soft_delete()) {
            return true;
        }
        return (bool) $this->forceDeleting;
    }
    public function get_bounds(): array
    {
        return [$this->get_lft(), $this->get_rgt()];
    }
    /**
     * @param $value
     *
     * @return $this
     */
    public function set_lft($value)
    {
        $this->attributes[$this->get_lft_name()] = $value;
        return $this;
    }
    /**
     * @param $value
     *
     * @return $this
     */
    public function set_rgt($value)
    {
        $this->attributes[$this->get_rgt_name()] = $value;
        return $this;
    }
    /**
     * @param $value
     *
     * @return $this
     */
    public function set_parent_id($value)
    {
        $this->attributes[$this->get_parent_id_name()] = $value;
        return $this;
    }
    /**
     * @return $this
     */
    protected function dirty_bounds()
    {
        $this->original[$this->get_lft_name()] = null;
        $this->original[$this->get_rgt_name()] = null;
        return $this;
    }
    /**
     * @return $this
     */
    protected function assert_not_descendant(self $node)
    {
        if ($node == $this || $node->is_descendant_of($this)) {
            throw new LogicException('Node must not be a descendant.');
        }
        return $this;
    }
    /**
     * @return $this
     */
    protected function assert_node_exists(self $node)
    {
        if (!$node->get_lft() || !$node->get_rgt()) {
            throw new LogicException('Node must exists.');
        }
        return $this;
    }
    protected function assert_same_scope(self $node)
    {
        if (!$scoped = $this->get_scope_attributes()) {
            return;
        }
        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                throw new LogicException('Nodes must be in the same scope');
            }
        }
    }
    protected function is_same_scope(self $node): bool
    {
        if (!$scoped = $this->get_scope_attributes()) {
            return true;
        }
        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                return false;
            }
        }
        return true;
    }
    /**
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function replicate(?array $except = null)
    {
        $defaults = [$this->get_parent_id_name(), $this->get_lft_name(), $this->get_rgt_name()];
        $except = $except ? array_unique(array_merge($except, $defaults)) : $defaults;
        return parent::replicate($except);
    }
}