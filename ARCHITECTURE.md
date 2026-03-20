# Architecture: laravel-nestedset

## Purpose
A Laravel/Eloquent implementation of the nested set (modified preorder tree traversal) pattern for storing hierarchical data in a relational database. Supports efficient subtree queries, tree rebalancing, and ancestor/descendant retrieval.

## Directory Structure
```
src/
  Node_Trait.php             # Main trait applied to Eloquent models — all tree manipulation methods
  Nested_Set.php             # Static helpers and constants (_lft, _rgt, parent_id column names)
  Query_Builder.php          # Custom Eloquent query builder with tree-aware scopes
  Collection.php             # Extended Eloquent Collection with toTree(), toFlatTree() methods
  Base_Relation.php          # Abstract base for tree relations
  Ancestors_Relation.php     # Eloquent relation: model → its ancestors (parent chain)
  Descendants_Relation.php   # Eloquent relation: model → its descendants (subtree)
  Nested_Set_Service_Provider.php
```

## Key Design Decisions
- **Nested set model** — each node stores `_lft` (left value) and `_rgt` (right value) integers. Subtrees are retrieved in O(1) queries (`WHERE _lft BETWEEN parent._lft AND parent._rgt`), avoiding recursive CTEs.
- **Node_Trait** — applied as a trait, preserving full model inheritance freedom. The trait overrides `save()`, `delete()`, and provides tree manipulation methods (`appendToNode`, `prependToNode`, `insertAfterNode`, `insertBeforeNode`).
- **Auto-rebalancing** — every insert/move/delete recalculates the `_lft`/`_rgt` columns for affected nodes in a single `UPDATE` statement using arithmetic expressions.
- **Custom query builder** — `Query_Builder` adds scopes like `withDepth()`, `hasParent()`, `whereIsRoot()`, `whereDescendantOf()`, `whereAncestorOf()`, keeping queries fluent.
- **Collection extensions** — `toTree()` converts a flat result set into a nested tree structure in memory (O(n)), suitable for recursive rendering without additional queries.

## Extension Points
- Override `getLftName()`, `getRgtName()`, and `getParentIdName()` to use custom column names.
- Use `NestedSet::rebuildsTree()` to repair corrupted `_lft`/`_rgt` values.
- Combine with `Collection::toTree()` and a recursive Blade component for tree rendering.

## Dependency Flow
```
$category->appendToNode($parent)->save()
  └─ Node_Trait::save()
       └─ Query_Builder: UPDATE categories SET _lft = ..., _rgt = ... WHERE _lft >= $insertPoint
            └─ INSERT/UPDATE the node with its new _lft / _rgt / parent_id

Category::whereDescendantOf($parent)->get()
  └─ WHERE _lft >= $parent->_lft AND _rgt <= $parent->_rgt
       └─ Collection::toTree() → nested PHP array of Category models
```
