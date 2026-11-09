<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Kalnoy\Nestedset\Nested_Set;

class Scoped_Node_Test extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('menu_items');

        Capsule::disableQueryLog();

        $schema->create('menu_items', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('menu_id');
            $table->string('title')->nullable();
            Nested_Set::columns($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp(): void
    {
        $data = include __DIR__.'/data/menu_items.php';

        Capsule::table('menu_items')->insert($data);

        Capsule::flushQueryLog();

        Menu_Item::reset_actions_performed();

        date_default_timezone_set('America/Denver');
    }

    public function tearDown(): void
    {
        Capsule::table('menu_items')->truncate();
    }

    public function assert_tree_not_broken($menu_id)
    {
        $this->assertFalse(Menu_Item::scoped([ 'menu_id' => $menu_id ])->is_broken());
    }

    public function test_not_broken()
    {
        $this->assert_tree_not_broken(1);
        $this->assert_tree_not_broken(2);
    }

    public function test_moving_node_not_affecting_other_menu()
    {
        $node = Menu_Item::where('menu_id', '=', 1)->first();

        $node->down();

        $node = Menu_Item::where('menu_id', '=', 2)->first();

        $this->assertEquals(1, $node->get_lft());
    }

    public function test_scoped()
    {
        $node = Menu_Item::scoped([ 'menu_id' => 2 ])->first();

        $this->assertEquals(3, $node->getKey());
    }

    public function test_siblings()
    {
        $node = Menu_Item::find(1);

        $result = $node->get_siblings();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());

        $result = $node->get_next_siblings();

        $this->assertEquals(2, $result->first()->getKey());

        $node = Menu_Item::find(2);

        $result = $node->get_prev_siblings();

        $this->assertEquals(1, $result->first()->getKey());
    }

    public function test_descendants()
    {
        $node = Menu_Item::find(2);

        $result = $node->get_descendants();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(5, $result->first()->getKey());

        $node = Menu_Item::scoped([ 'menu_id' => 1 ])->with('descendants')->find(2);

        $result = $node->descendants;

        $this->assertEquals(1, $result->count());
        $this->assertEquals(5, $result->first()->getKey());
    }

    public function test_ancestors()
    {
        $node = Menu_Item::find(5);

        $result = $node->get_ancestors();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());

        $node = Menu_Item::scoped([ 'menu_id' => 1 ])->with('ancestors')->find(5);

        $result = $node->ancestors;

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());
    }

    public function test_depth()
    {
        $node = Menu_Item::scoped([ 'menu_id' => 1 ])->with_depth()->where('id', '=', 5)->first();

        $this->assertEquals(1, $node->depth);

        $node = Menu_Item::find(2);

        $result = $node->children()->with_depth()->get();

        $this->assertEquals(1, $result->first()->depth);
    }

    public function test_save_as_root()
    {
        $node = Menu_Item::find(5);

        $node->save_as_root();

        $this->assertEquals(5, $node->get_lft());
        $this->assertEquals(null, $node->parent_id);

        $this->assert_other_scope_not_affected();
    }

    public function test_insertion()
    {
        $node = Menu_Item::create([ 'menu_id' => 1, 'parent_id' => 5 ]);

        $this->assertEquals(5, $node->parent_id);
        $this->assertEquals(5, $node->get_lft());

        $this->assert_other_scope_not_affected();
    }

    public function test_insertion_to_parent_from_other_scope()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $node = Menu_Item::create([ 'menu_id' => 2, 'parent_id' => 5 ]);
    }

    public function test_deletion()
    {
        $node = Menu_Item::find(2)->delete();

        $node = Menu_Item::find(1);

        $this->assertEquals(2, $node->get_rgt());

        $this->assert_other_scope_not_affected();
    }

    public function test_moving()
    {
        $node = Menu_Item::find(1);
        $this->assertTrue($node->down());

        $this->assert_other_scope_not_affected();
    }

    protected function assert_other_scope_not_affected()
    {
        $node = Menu_Item::find(3);

        $this->assertEquals(1, $node->get_lft());
    }

    // Commented, cause there is no assertion here and otherwise the test is marked as risky in PHPUnit 7.
    // What's the purpose of this method? @todo: remove/update?
    /*public function test_rebuilds_tree()
    {
        $data = [];
        Menu_Item::scoped([ 'menu_id' => 2 ])->rebuild_tree($data);
    }*/

    public function test_appending_to_another_scope_fails()
    {
        $this->expectException(LogicException::class);

        $a = Menu_Item::find(1);
        $b = Menu_Item::find(3);

        $a->append_to_node($b)->save();
    }

    public function test_inserting_before_another_scope_fails()
    {
        $this->expectException(LogicException::class);

        $a = Menu_Item::find(1);
        $b = Menu_Item::find(3);

        $a->insert_after_node($b);
    }

    public function test_eager_loading_ancestors_with_scope()
    {
        $filtered_nodes = Menu_Item::where('title', 'menu item 3')->with(['ancestors'])->get();

        $this->assertEquals(2, $filtered_nodes->find(5)->ancestors[0]->id);
        $this->assertEquals(4, $filtered_nodes->find(6)->ancestors[0]->id);
    }

    public function test_eager_loading_descendants_with_scope()
    {
        $filtered_nodes = Menu_Item::where('title', 'menu item 2')->with(['descendants'])->get();

        $this->assertEquals(5, $filtered_nodes->find(2)->descendants[0]->id);
        $this->assertEquals(6, $filtered_nodes->find(4)->descendants[0]->id);
    }
}
