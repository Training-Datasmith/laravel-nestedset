### Upgrading from 4.0 to 4.1

Nested sets feature has been moved to trait `Kalnoy\Nestedset\Node_Trait`, but
old `Kalnoy\Nestedset\Node` class is still available.

Some methods on trait were renamed (see changelog), but still available on legacy
node class.

Default order is no longer applied for `siblings()`, `descendants()`, 
`prev_nodes`, `next_nodes`.

### Upgrading to 3.0

Some methods were renamed, see changelog for more details.

### Upgrading to 2.0

Calling `$parent->append($node)` and `$parent->prepend($node)` now automatically
saves `$node`. Those functions returns whether the node was saved.

`ancestors_of` now return ancestors only, not including target node.

Default order is not applied automatically, so if you need nodes to be in tree-order
you should call `default_order` on the query.

Since root node is not required now, `Nested_Set::create_root` method has been removed.

`Nested_Set::columns` now doesn't create a foreign key for a `parent_id` column.
