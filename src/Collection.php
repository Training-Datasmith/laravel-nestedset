<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Eloquent\Collection as BaseCollection;
use Illuminate\Database\Eloquent\Model;
class Collection extends BaseCollection
{
    /**
     * Fill `parent` and `children` relationships for every node in the collection.
     *
     * This will overwrite any previously set relations.
     *
     * @return $this
     */
    public function link_nodes(): static
    {
        if ($this->isEmpty()) {
            return $this;
        }
        $grouped_nodes = $this->groupBy($this->first()->get_parent_id_name());
        /** @var Node_Trait|Model $node */
        foreach ($this->items as $node) {
            if (!$node->get_parent_id()) {
                $node->setRelation('parent', null);
            }
            $children = $grouped_nodes->get($node->getKey(), []);
            /** @var Model|Node_Trait $child */
            foreach ($children as $child) {
                $child->setRelation('parent', $node);
            }
            $node->setRelation('children', BaseCollection::make($children));
        }
        return $this;
    }
    /**
     * Build a tree from a list of nodes. Each item will have set children relation.
     *
     * To successfully build tree "id", "_lft" and "parent_id" keys must present.
     *
     * If `$root` is provided, the tree will contain only descendants of that node.
     *
     * @param  int|false $root Root node ID to filter by, or false to return all root-level nodes.
     * @return static          A new Collection containing only the root-level nodes, with children nested.
     */
    public function to_tree($root = false): static
    {
        if ($this->isEmpty()) {
            return new static();
        }
        $this->link_nodes();
        $items = [];
        $root = $this->get_root_node_id($root);
        /** @var Model|Node_Trait $node */
        foreach ($this->items as $node) {
            if ($node->get_parent_id() == $root) {
                $items[] = $node;
            }
        }
        return new static($items);
    }
    /**
     * @param mixed $root
     *
     * @return int
     */
    protected function get_root_node_id($root = false)
    {
        if (Nested_Set::is_node($root)) {
            return $root->getKey();
        }
        if ($root !== false) {
            return $root;
        }
        // If root node is not specified we take parent id of node with
        // least lft value as root node id.
        $least_value = null;
        /** @var Model|Node_Trait $node */
        foreach ($this->items as $node) {
            if ($least_value === null || $node->get_lft() < $least_value) {
                $least_value = $node->get_lft();
                $root = $node->get_parent_id();
            }
        }
        return $root;
    }
    /**
     * Build a list of nodes that retain the order that they were pulled from
     * the database.
     *
     * @param bool $root
     *
     * @return static
     */
    public function to_flat_tree($root = false)
    {
        $result = new static();
        if ($this->isEmpty()) {
            return $result;
        }
        $grouped_nodes = $this->groupBy($this->first()->get_parent_id_name());
        return $result->flatten_tree($grouped_nodes, $this->get_root_node_id($root));
    }
    /**
     * Flatten a tree into a non recursive array.
     *
     * @param mixed $parent_id
     * @return $this
     */
    protected function flatten_tree(self $grouped_nodes, $parent_id)
    {
        foreach ($grouped_nodes->get($parent_id, []) as $node) {
            $this->push($node);
            $this->flatten_tree($grouped_nodes, $node->getKey());
        }
        return $this;
    }
}