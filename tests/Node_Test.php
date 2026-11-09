<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Kalnoy\Nestedset\Nested_Set;

class Node_Test extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('categories');

        Capsule::disableQueryLog();

        $schema->create('categories', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
            Nested_Set::columns($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp(): void
    {
        $data = include __DIR__.'/data/categories.php';

        Capsule::table('categories')->insert($data);

        Capsule::flushQueryLog();

        Category::reset_actions_performed();

        date_default_timezone_set('America/Denver');
    }

    public function tearDown(): void
    {
        Capsule::table('categories')->truncate();
    }

    // public static function tearDownAfterClass()
    // {
    //     $log = Capsule::getQueryLog();
    //     foreach ($log as $item) {
    //         echo $item['query']." with ".implode(', ', $item['bindings'])."\n";
    //     }
    // }

    public function assert_tree_not_broken($table = 'categories')
    {
        $checks = [];

        $connection = Capsule::connection();

        $table = $connection->getQueryGrammar()->wrapTable($table);

        // Check if lft and rgt values are ok
        $checks[] = "from $table where _lft >= _rgt or (_rgt - _lft) % 2 = 0";

        // Check if lft and rgt values are unique
        $checks[] = "from $table c1, $table c2 where c1.id <> c2.id and ".
            '(c1._lft=c2._lft or c1._rgt=c2._rgt or c1._lft=c2._rgt or c1._rgt=c2._lft)';

        // Check if parent_id is set correctly
        $checks[] = "from $table c, $table p, $table m where c.parent_id=p.id and m.id <> p.id and m.id <> c.id and ".
             '(c._lft not between p._lft and p._rgt or c._lft between m._lft and m._rgt and m._lft between p._lft and p._rgt)';

        foreach ($checks as $i => $check) {
            $checks[$i] = 'select 1 as error '.$check;
        }

        $sql = 'select max(error) as errors from ('.implode(' union ', $checks).') _';

        $actual = $connection->selectOne($sql);

        $this->assertEquals(null, $actual->errors, "The tree structure of $table is broken!");
        $actual = (array)Capsule::connection()->selectOne($sql);

        $this->assertEquals(['errors' => null], $actual, "The tree structure of $table is broken!");
    }

    public function dump_tree($items = null)
    {
        if (! $items) {
            $items = Category::withTrashed()->default_order()->get();
        }

        foreach ($items as $item) {
            echo PHP_EOL.($item->trashed() ? '-' : '+').' '.$item->name.' '.$item->getKey().' '.$item->get_lft().' '.$item->get_rgt().' '.$item->get_parent_id();
        }
    }

    public function assert_node_receives_valid_values($node)
    {
        $lft = $node->get_lft();
        $rgt = $node->get_rgt();
        $node_in_db = $this->find_category($node->name);

        $this->assertEquals(
            [ $node_in_db->get_lft(), $node_in_db->get_rgt() ],
            [ $lft, $rgt ],
            'Node is not synced with database after save.'
        );
    }

    /**
     * @param $name
     *
     * @return \Category
     */
    public function find_category($name, $withTrashed = false)
    {
        $q = new Category();

        $q = $withTrashed ? $q->withTrashed() : $q->newQuery();

        return $q->whereName($name)->first();
    }

    public function test_tree_not_broken()
    {
        $this->assert_tree_not_broken();
        $this->assertFalse(Category::is_broken());
    }

    public function node_values($node)
    {
        return [$node->_lft, $node->_rgt, $node->parent_id];
    }

    public function test_gets_node_data()
    {
        $data = Category::get_node_data(3);

        $this->assertEquals([ '_lft' => 3, '_rgt' => 4 ], $data);
    }

    public function test_gets_plain_node_data()
    {
        $data = Category::get_plain_node_data(3);

        $this->assertEquals([ 3, 4 ], $data);
    }

    public function test_receives_valid_values_when_appended_to()
    {
        $node = new Category([ 'name' => 'test' ]);
        $root = Category::root();

        $accepted = [$root->_rgt, $root->_rgt + 1, $root->id];

        $root->append_node($node);

        $this->assertTrue($node->has_moved());
        $this->assertEquals($accepted, $this->node_values($node));
        $this->assert_tree_not_broken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->is_descendant_of($root));
    }

    public function test_receives_valid_values_when_prepended_to()
    {
        $root = Category::root();
        $node = new Category([ 'name' => 'test' ]);
        $root->prepend_node($node);

        $this->assertTrue($node->has_moved());
        $this->assertEquals([$root->_lft + 1, $root->_lft + 2, $root->id], $this->node_values($node));
        $this->assert_tree_not_broken();
        $this->assertTrue($node->is_descendant_of($root));
        $this->assertTrue($root->is_ancestor_of($node));
        $this->assertTrue($node->is_child_of($root));
    }

    public function test_receives_valid_values_when_inserted_after()
    {
        $target = $this->find_category('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->after_node($target)->save();

        $this->assertTrue($node->has_moved());
        $this->assertEquals([$target->_rgt + 1, $target->_rgt + 2, $target->parent->id], $this->node_values($node));
        $this->assert_tree_not_broken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->is_sibling_of($target));
    }

    public function test_receives_valid_values_when_inserted_before()
    {
        $target = $this->find_category('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->before_node($target)->save();

        $this->assertTrue($node->has_moved());
        $this->assertEquals([$target->_lft, $target->_lft + 1, $target->parent->id], $this->node_values($node));
        $this->assert_tree_not_broken();
    }

    public function test_category_moves_down()
    {
        $node = $this->find_category('apple');
        $target = $this->find_category('mobile');

        $target->append_node($node);

        $this->assertTrue($node->has_moved());
        $this->assert_node_receives_valid_values($node);
        $this->assert_tree_not_broken();
    }

    public function test_category_moves_up()
    {
        $node = $this->find_category('samsung');
        $target = $this->find_category('notebooks');

        $target->append_node($node);

        $this->assertTrue($node->has_moved());
        $this->assert_tree_not_broken();
        $this->assert_node_receives_valid_values($node);
    }

    public function test_fails_to_insert_into_child()
    {
        $this->expectException(Exception::class);

        $node = $this->find_category('notebooks');
        $target = $node->children()->first();

        $node->after_node($target)->save();
    }

    public function test_fails_to_append_into_itself()
    {
        $this->expectException(Exception::class);

        $node = $this->find_category('notebooks');

        $node->append_to_node($node)->save();
    }

    public function test_fails_to_prepend_into_itself()
    {
        $this->expectException(Exception::class);

        $node = $this->find_category('notebooks');

        $node->prepend_to_node($node)->save();
    }

    public function test_without_root_works()
    {
        $result = Category::without_root()->pluck('name');

        $this->assertNotEquals('store', $result);
    }

    public function test_ancestors_returns_ancestors_without_node_itself()
    {
        $node = $this->find_category('apple');
        $path = all_items($node->ancestors()->pluck('name'));

        $this->assertEquals(['store', 'notebooks'], $path);
    }

    public function test_gets_ancestors_by_static()
    {
        $path = all_items(Category::ancestors_of(3)->pluck('name'));

        $this->assertEquals(['store', 'notebooks'], $path);
    }

    public function test_gets_ancestors_direct()
    {
        $path = all_items(Category::find(8)->get_ancestors()->pluck('id'));

        $this->assertEquals([1, 5, 7], $path);
    }

    public function test_descendants()
    {
        $node = $this->find_category('mobile');
        $descendants = all_items($node->descendants()->pluck('name'));
        $expected = ['nokia', 'samsung', 'galaxy', 'sony', 'lenovo'];

        $this->assertEquals($expected, $descendants);

        $descendants = all_items($node->get_descendants()->pluck('name'));

        $this->assertEquals(count($descendants), $node->get_descendant_count());
        $this->assertEquals($expected, $descendants);

        $descendants = all_items(Category::descendants_and_self(7)->pluck('name'));
        $expected = [ 'samsung', 'galaxy' ];

        $this->assertEquals($expected, $descendants);
    }

    public function test_with_depth_works()
    {
        $nodes = all_items(Category::with_depth()->limit(4)->pluck('depth'));

        $this->assertEquals([0, 1, 2, 2], $nodes);
    }

    public function test_with_depth_with_custom_key_works()
    {
        $node = Category::where_is_root()->with_depth('level')->first();

        $this->assertTrue(isset($node['level']));
    }

    public function test_with_depth_works_along_with_default_keys()
    {
        $node = Category::with_depth()->first();

        $this->assertTrue(isset($node->name));
    }

    public function test_parent_id_attribute_accessor_appends_node()
    {
        $node = new Category(['name' => 'lg', 'parent_id' => 5]);
        $node->save();

        $this->assertEquals(5, $node->parent_id);
        $this->assertEquals(5, $node->get_parent_id());

        $node->parent_id = null;
        $node->save();

        $node->refresh_node();

        $this->assertEquals(null, $node->parent_id);
        $this->assertTrue($node->is_root());
    }

    public function test_fails_to_save_node_until_not_inserted()
    {
        $this->expectException(Exception::class);

        $node = new Category();
        $node->save();
    }

    public function test_node_is_deleted_with_descendants()
    {
        $node = $this->find_category('mobile');
        $node->forceDelete();

        $this->assert_tree_not_broken();

        $nodes = Category::whereIn('id', [5, 6, 7, 8, 9])->count();
        $this->assertEquals(0, $nodes);

        $root = Category::root();
        $this->assertEquals(8, $root->get_rgt());
    }

    public function test_node_is_soft_deleted()
    {
        $root = Category::root();

        $samsung = $this->find_category('samsung');
        $samsung->delete();

        $this->assert_tree_not_broken();

        $this->assertNull($this->find_category('galaxy'));

        sleep(1);

        $node = $this->find_category('mobile');
        $node->delete();

        $nodes = Category::whereIn('id', [5, 6, 7, 8, 9])->count();
        $this->assertEquals(0, $nodes);

        $original_rgt = $root->get_rgt();
        $root->refresh_node();

        $this->assertEquals($original_rgt, $root->get_rgt());

        $node = $this->find_category('mobile', true);

        $node->restore();

        $this->assertNull($this->find_category('samsung'));
        $this->assertNotNull($this->find_category('nokia'));
    }

    public function test_soft_deleted_nodeis_deleted_when_parent_is_deleted()
    {
        $this->find_category('samsung')->delete();

        $this->find_category('mobile')->forceDelete();

        $this->assert_tree_not_broken();

        $this->assertNull($this->find_category('samsung', true));
        $this->assertNull($this->find_category('sony'));
    }

    public function test_fails_to_save_node_until_parent_is_saved()
    {
        $this->expectException(Exception::class);

        $node = new Category(['title' => 'Node']);
        $parent = new Category(['title' => 'Parent']);

        $node->append_to_node($parent)->save();
    }

    public function test_siblings()
    {
        $node = $this->find_category('samsung');
        $siblings = all_items($node->siblings()->pluck('id'));
        $next = all_items($node->next_siblings()->pluck('id'));
        $prev = all_items($node->prev_siblings()->pluck('id'));

        $this->assertEquals([6, 9, 10], $siblings);
        $this->assertEquals([9, 10], $next);
        $this->assertEquals([6], $prev);

        $siblings = all_items($node->get_siblings()->pluck('id'));
        $next = all_items($node->get_next_siblings()->pluck('id'));
        $prev = all_items($node->get_prev_siblings()->pluck('id'));

        $this->assertEquals([6, 9, 10], $siblings);
        $this->assertEquals([9, 10], $next);
        $this->assertEquals([6], $prev);

        $next = $node->get_next_sibling();
        $prev = $node->get_prev_sibling();

        $this->assertEquals(9, $next->id);
        $this->assertEquals(6, $prev->id);
    }

    public function test_fetches_reversed()
    {
        $node = $this->find_category('sony');
        $siblings = $node->prev_siblings()->reversed()->value('id');

        $this->assertEquals(7, $siblings);
    }

    public function test_to_tree_builds_with_default_order()
    {
        $tree = Category::whereBetween('_lft', [8, 17])->default_order()->get()->to_tree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
    }

    public function test_to_tree_builds_with_custom_order()
    {
        $tree = Category::whereBetween('_lft', [8, 17])
            ->orderBy('title')
            ->get()
            ->to_tree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
        $this->assertEquals($root, $root->children->first()->parent);
    }

    public function test_to_tree_with_specified_root()
    {
        $node = $this->find_category('mobile');
        $nodes = Category::whereBetween('_lft', [8, 17])->get();

        $tree1 = \Kalnoy\Nestedset\Collection::make($nodes)->to_tree(5);
        $tree2 = \Kalnoy\Nestedset\Collection::make($nodes)->to_tree($node);

        $this->assertEquals(4, $tree1->count());
        $this->assertEquals(4, $tree2->count());
    }

    public function test_to_tree_builds_with_default_order_and_multiple_root_nodes()
    {
        $tree = Category::without_root()->get()->to_tree();

        $this->assertEquals(2, count($tree));
    }

    public function test_to_tree_builds_with_root_item_id_provided()
    {
        $tree = Category::whereBetween('_lft', [8, 17])->get()->to_tree(5);

        $this->assertEquals(4, count($tree));

        $root = $tree[1];
        $this->assertEquals('samsung', $root->name);
        $this->assertEquals(1, count($root->children));
    }

    public function test_retrieves_next_node()
    {
        $node = $this->find_category('apple');
        $next = $node->next_nodes()->first();

        $this->assertEquals('lenovo', $next->name);
    }

    public function test_retrieves_prev_node()
    {
        $node = $this->find_category('apple');
        $next = $node->get_prev_node();

        $this->assertEquals('notebooks', $next->name);
    }

    public function test_multiple_appendage_works()
    {
        $parent = $this->find_category('mobile');

        $child = new Category([ 'name' => 'test' ]);

        $parent->append_node($child);

        $child->append_node(new Category([ 'name' => 'sub' ]));

        $parent->append_node(new Category([ 'name' => 'test2' ]));

        $this->assert_tree_not_broken();
    }

    public function test_default_category_is_saved_as_root()
    {
        $node = new Category([ 'name' => 'test' ]);
        $node->save();

        $this->assertEquals(23, $node->_lft);
        $this->assert_tree_not_broken();

        $this->assertTrue($node->is_root());
    }

    public function test_existing_category_saved_as_root()
    {
        $node = $this->find_category('apple');
        $node->save_as_root();

        $this->assert_tree_not_broken();
        $this->assertTrue($node->is_root());
    }

    public function test_node_moves_down_several_positions()
    {
        $node = $this->find_category('nokia');

        $this->assertTrue($node->down(2));

        $this->assertEquals($node->_lft, 15);
    }

    public function test_node_moves_up_several_positions()
    {
        $node = $this->find_category('sony');

        $this->assertTrue($node->up(2));

        $this->assertEquals($node->_lft, 9);
    }

    public function test_counts_tree_errors()
    {
        $errors = Category::count_errors();

        $this->assertEquals([ 'oddness' => 0,
                              'duplicates' => 0,
                              'wrong_parent' => 0,
                              'missing_parent' => 0 ], $errors);

        Category::where('id', '=', 5)->update([ '_lft' => 14 ]);
        Category::where('id', '=', 8)->update([ 'parent_id' => 2 ]);
        Category::where('id', '=', 11)->update([ '_lft' => 20 ]);
        Category::where('id', '=', 4)->update([ 'parent_id' => 24 ]);

        $errors = Category::count_errors();

        $this->assertEquals(1, $errors['oddness']);
        $this->assertEquals(2, $errors['duplicates']);
        $this->assertEquals(1, $errors['missing_parent']);
    }

    public function test_creates_node()
    {
        $node = Category::create([ 'name' => 'test' ]);

        $this->assertEquals(23, $node->get_lft());
    }

    public function test_creates_via_relationship()
    {
        $node = $this->find_category('apple');

        $child = $node->children()->create([ 'name' => 'test' ]);

        $this->assert_tree_not_broken();
    }

    public function test_creates_tree()
    {
        $node = Category::create(
            [
            'name' => 'test',
            'children' =>
            [
                [ 'name' => 'test2' ],
                [ 'name' => 'test3' ],
            ],
        ]
        );

        $this->assert_tree_not_broken();

        $this->assertTrue(isset($node->children));

        $node = $this->find_category('test');

        $this->assertCount(2, $node->children);
        $this->assertEquals('test2', $node->children[0]->name);
    }

    public function test_descendants_of_non_existing_node()
    {
        $node = new Category();

        $this->assertTrue($node->get_descendants()->isEmpty());
    }

    public function test_where_descendants_of()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Category::where_descendant_of(124)->get();
    }

    public function test_ancestors_by_node()
    {
        $category = $this->find_category('apple');
        $ancestors = all_items(Category::where_ancestor_of($category)->pluck('id'));

        $this->assertEquals([ 1, 2 ], $ancestors);
    }

    public function test_descendants_by_node()
    {
        $category = $this->find_category('notebooks');
        $res = all_items(Category::where_descendant_of($category)->pluck('id'));

        $this->assertEquals([ 3, 4 ], $res);
    }

    public function test_multiple_deletions_do_not_brake_tree()
    {
        $category = $this->find_category('mobile');

        foreach ($category->children()->take(2)->get() as $child) {
            $child->forceDelete();
        }

        $this->assert_tree_not_broken();
    }

    public function test_tree_is_fixed()
    {
        Category::where('id', '=', 5)->update([ '_lft' => 14 ]);
        Category::where('id', '=', 8)->update([ 'parent_id' => 2 ]);
        Category::where('id', '=', 11)->update([ '_lft' => 20 ]);
        Category::where('id', '=', 2)->update([ 'parent_id' => 24 ]);

        $fixed = Category::fix_tree();

        $this->assertTrue($fixed > 0);
        $this->assert_tree_not_broken();

        $node = Category::find(8);

        $this->assertEquals(2, $node->get_parent_id());

        $node = Category::find(2);

        $this->assertEquals(null, $node->get_parent_id());
    }

    public function test_subtree_is_fixed()
    {
        Category::where('id', '=', 8)->update([ '_lft' => 11 ]);

        $fixed = Category::fix_subtree(Category::find(5));
        $this->assertEquals($fixed, 1);
        $this->assert_tree_not_broken();
        $this->assertEquals(Category::find(8)->get_lft(), 12);
    }

    public function test_parent_id_dirtiness()
    {
        $node = $this->find_category('apple');
        $node->parent_id = 5;

        $this->assertTrue($node->isDirty('parent_id'));

        $node = $this->find_category('apple');
        $node->parent_id = null;

        $this->assertTrue($node->isDirty('parent_id'));
    }

    public function test_is_dirty_movement()
    {
        $node = $this->find_category('apple');
        $otherNode = $this->find_category('samsung');

        $this->assertFalse($node->isDirty());

        $node->after_node($otherNode);

        $this->assertTrue($node->isDirty());

        $node = $this->find_category('apple');
        $otherNode = $this->find_category('samsung');

        $this->assertFalse($node->isDirty());

        $node->append_to_node($otherNode);

        $this->assertTrue($node->isDirty());
    }

    public function test_root_nodes_moving()
    {
        $node = $this->find_category('store');
        $node->down();

        $this->assertEquals(3, $node->get_lft());
    }

    public function test_descendants_relation()
    {
        $node = $this->find_category('notebooks');
        $result = $node->descendants;

        $this->assertEquals(2, $result->count());
        $this->assertEquals('apple', $result->first()->name);
    }

    public function test_descendants_eagerly_loaded()
    {
        $nodes = Category::whereIn('id', [ 2, 5 ])->get();

        $nodes->load('descendants');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('descendants'));
    }

    public function test_descendants_relation_query()
    {
        $nodes = Category::has('descendants')->whereIn('id', [ 2, 3 ])->get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals(2, $nodes->first()->getKey());

        $nodes = Category::has('descendants', '>', 2)->get();

        $this->assertEquals(2, $nodes->count());
        $this->assertEquals(1, $nodes[0]->getKey());
        $this->assertEquals(5, $nodes[1]->getKey());
    }

    public function test_parent_relation_query()
    {
        $nodes = Category::has('parent')->whereIn('id', [ 1, 2 ]);

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals(2, $nodes->first()->getKey());
    }

    public function test_rebuild_tree()
    {
        $fixed = Category::rebuild_tree([
            [
                'id' => 1,
                'children' => [
                    [ 'id' => 10 ],
                    [ 'id' => 3, 'name' => 'apple v2', 'children' => [ [ 'name' => 'new node' ] ] ],
                    [ 'id' => 2 ],

                ],
            ],
        ]);

        $this->assertTrue($fixed > 0);
        $this->assert_tree_not_broken();

        $node = Category::find(3);

        $this->assertEquals(1, $node->get_parent_id());
        $this->assertEquals('apple v2', $node->name);
        $this->assertEquals(4, $node->get_lft());

        $node = $this->find_category('new node');

        $this->assertNotNull($node);
        $this->assertEquals(3, $node->get_parent_id());
    }

    public function test_rebuild_subtree()
    {
        $fixed = Category::rebuild_subtree(Category::find(7), [
            [ 'name' => 'new node' ],
            [ 'id' => '8' ],
        ]);

        $this->assertTrue($fixed > 0);
        $this->assert_tree_not_broken();

        $node = $this->find_category('new node');

        $this->assertNotNull($node);
        $this->assertEquals($node->get_lft(), 12);
    }

    public function test_rebuild_tree_with_deletion()
    {
        Category::rebuild_tree([ [ 'name' => 'all deleted' ] ], true);

        $this->assert_tree_not_broken();

        $nodes = Category::get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals('all deleted', $nodes->first()->name);

        $nodes = Category::withTrashed()->get();

        $this->assertTrue($nodes->count() > 1);
    }

    public function test_rebuild_fails_with_invalid_pk()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Category::rebuild_tree([ [ 'id' => 24 ] ]);
    }

    public function test_flat_tree()
    {
        $node = $this->find_category('mobile');
        $tree = $node->descendants()->orderBy('name')->get()->to_flat_tree();

        $this->assertCount(5, $tree);
        $this->assertEquals('samsung', $tree[2]->name);
        $this->assertEquals('galaxy', $tree[3]->name);
    }

    // Commented, cause there is no assertion here and otherwise the test is marked as risky in PHPUnit 7.
    // What's the purpose of this method? @todo: remove/update?
    /*public function test_several_nodes_model_work()
    {
        $category = new Category;

        $category->name = 'test';

        $category->save_as_root();

        $duplicate = new Duplicate_Category;

        $duplicate->name = 'test';

        $duplicate->save_as_root();
    }*/

    public function test_where_is_leaf()
    {
        $categories = Category::leaves();

        $this->assertEquals(7, $categories->count());
        $this->assertEquals('apple', $categories->first()->name);
        $this->assertTrue($categories->first()->is_leaf());

        $category = Category::where_is_root()->first();

        $this->assertFalse($category->is_leaf());
    }

    public function test_eager_load_ancestors()
    {
        $query_log_count = count(Capsule::connection()->getQueryLog());
        $categories = Category::with('ancestors')->orderBy('name')->get();

        $this->assertEquals($query_log_count + 2, count(Capsule::connection()->getQueryLog()));

        $expected_shape = [
            'apple (3)}' => 'store (1) > notebooks (2)',
            'galaxy (8)}' => 'store (1) > mobile (5) > samsung (7)',
            'lenovo (4)}' => 'store (1) > notebooks (2)',
            'lenovo (10)}' => 'store (1) > mobile (5)',
            'mobile (5)}' => 'store (1)',
            'nokia (6)}' => 'store (1) > mobile (5)',
            'notebooks (2)}' => 'store (1)',
            'samsung (7)}' => 'store (1) > mobile (5)',
            'sony (9)}' => 'store (1) > mobile (5)',
            'store (1)}' => '',
            'store_2 (11)}' => '',
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function ($cat) {
                    return "{$cat->name} ({$cat->id})";
                })->toArray())
                : '';
        }

        $this->assertEquals($expected_shape, $output);
    }

    public function test_lazy_load_ancestors()
    {
        $query_log_count = count(Capsule::connection()->getQueryLog());
        $categories = Category::orderBy('name')->get();

        $this->assertEquals($query_log_count + 1, count(Capsule::connection()->getQueryLog()));

        $expected_shape = [
            'apple (3)}' => 'store (1) > notebooks (2)',
            'galaxy (8)}' => 'store (1) > mobile (5) > samsung (7)',
            'lenovo (4)}' => 'store (1) > notebooks (2)',
            'lenovo (10)}' => 'store (1) > mobile (5)',
            'mobile (5)}' => 'store (1)',
            'nokia (6)}' => 'store (1) > mobile (5)',
            'notebooks (2)}' => 'store (1)',
            'samsung (7)}' => 'store (1) > mobile (5)',
            'sony (9)}' => 'store (1) > mobile (5)',
            'store (1)}' => '',
            'store_2 (11)}' => '',
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function ($cat) {
                    return "{$cat->name} ({$cat->id})";
                })->toArray())
                : '';
        }

        // assert that there is number of original query + 1 + number of rows to fulfill the relation
        $this->assertEquals($query_log_count + 12, count(Capsule::connection()->getQueryLog()));

        $this->assertEquals($expected_shape, $output);
    }

    public function test_where_has_count_query_for_ancestors()
    {
        $categories = all_items(Category::has('ancestors', '>', 2)->pluck('name'));

        $this->assertEquals([ 'galaxy' ], $categories);

        $categories = all_items(Category::whereHas('ancestors', function ($query) {
            $query->where('id', 5);
        })->pluck('name'));

        $this->assertEquals([ 'nokia', 'samsung', 'galaxy', 'sony', 'lenovo' ], $categories);
    }

    public function test_replication()
    {
        $category = $this->find_category('nokia');
        $category = $category->replicate();
        $category->save();
        $category->refresh_node();

        $this->assertNull($category->get_parent_id());

        $category = $this->find_category('nokia');
        $category = $category->replicate();
        $category->parent_id = 1;
        $category->save();

        $category->refresh_node();

        $this->assertEquals(1, $category->get_parent_id());
    }

}

function all_items($items)
{
    return is_array($items) ? $items : $items->all();
}
