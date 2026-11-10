### 4.3.4
*   Support Laravel 5.8

### 4.3.3
*   Support Laravel 5.7

### 4.3.2
*   Support Laravel 5.6
*   Added `nested_set` and `drop_nested_set` blueprint macros

### 4.3.0
*   Support Laravel 5.5
*   Added `fix_subtree` and `rebuild_subtree` methods
*   Increased performance of tree rebuilding

### 4.2.7

*   #217: parent_id, lft and rgt are reset when replicating a node

### 4.2.5

*   #208: dirty parent and bounds when making a root
*   #206: fixed where has on descendants
*   refactored ancestors and descendants relations

### 4.2.4

*   Fixed issues related to rebuilding tree when using `SoftDeletes` and scoping

### 4.2.3

*   Added `where_ancestor_or_self`, `ancestors_and_self`, `descendants_or_self`,
    `where_descendant_or_self` helper methods
*   #186: rebuild tree removes nodes softly when model uses SoftDeletes trait
*   #191: added `where_is_leaf` and `leaves` method, added `is_leaf` check on node

### 4.1.0

*   #75: Converted to trait. Following methods were renamed:
    -   `append_to_node` to `append_to_node`
    -   `prepend_to_node` to `prepend_to_node`
    -   `insert_before_node` to `insert_before_node`
    -   `insert_after_node` to `insert_after_node`
    -   `get_next_node` to `get_next_node`
    -   `get_prev_node` to `get_prev_node`
*   #82: Fixing tree now handles case when nodes pointing to non-existing parent
*   The number of missing parent is now returned when using `count_errors`
*   #79: implemented scoping feature
*   #81: moving node now makes model dirty before saving it
*   #45: descendants is now a relation that can be eagerly loaded
*   `has_children` and `has_parent` are now deprecated. Use `has('children')`
    `has('parent')` instead
*   Default order is no longer applied for `siblings()`, `descendants()`,
    `prev_nodes`, `next_nodes`
*   #50: implemented tree rebuilding feature
*   #85: added tree flattening feature

### 3.1.1

*   Fixed #42: model becomes dirty before save when parent is changed and using `append_to_node`,
    `prepend_to_node`, `insert_before_node`, `insert_after_node`.

### 3.1.0

*   Added `fix_tree` method for fixing `lft`/`rgt` values based on inheritance
*   Dropped support of Laravel < 5.1
*   Improved compatibility with different databases

### 3.0.0

*   Support Laravel 5.1.9
*   Renamed `append` to `append_node`, `prepend` to `prepend_node`
*   Renamed `next` to `next_nodes`, `prev` to `prev_nodes`
*   Renamed `after` to `after_node`, `before` to `before_node`

### 2.4.0

*   Added query methods `where_not_descendant_of`, `or_where_descendant_of`, `or_where_not_descendant_of`
*   `where_ancestor_of`, `where_descendant_of` and every method that depends on them can now accept node instance
*   Added `Node::getBounds` that returns an array of node bounds that can be used in `where_node_between`

### 2.3.0

*   Added `link_nodes` method to `Collection` class

### 2.2.0

*   Support Laravel 5

### 2.1.0

*   Added `is_child_of`, `is_ancestor_of`, `is_sibling_of` methods

### 2.0.0

*   Added `insert_after_node`, `insert_before_node` methods.
*   `prepend` and `append` methods now save target model.
*   You can now call `refresh_node` to make sure that node has updated structural
    data (lft and rgt values).
*   The root node is not required now. You can use `save_as_root` or `make_root` method.
    New model is saved as root by default.
*   You can now create as many nodes and in any order as you want within single
    request.
*   Laravel 2 is supported but not required.
*   `ancestors_of` now doesn't include target node into results.
*   New constraint methods `has_parent` and `has_children`.
*   New method `is_descendant_of` that checks if node is a descendant of other node.
*   Default order is not applied by default.
*   New method `descendants_of` that allows to get descendants by id of the node.
*   Added `count_errors` and `is_broken` methods to check whether the tree is broken.
*   `Nested_Set::create_root` has been removed.
*   `Nested_Set::column` doesn't create a foreign key anymore.

### 1.1.0

*   `Collection::to_dictionary` is now obsolete. Use `Collection::groupBy`.
*   Laravel 4.2 is required
