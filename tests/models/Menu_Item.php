<?php

declare(strict_types=1);

class Menu_Item extends \Illuminate\Database\Eloquent\Model
{
    use \Kalnoy\Nestedset\Node_Trait;

    public $timestamps = false;

    protected $table = 'menu_items';

    protected $fillable = ['menu_id','parent_id'];

    public static function reset_actions_performed()
    {
        static::$actions_performed = 0;
    }

    protected function get_scope_attributes()
    {
        return ['menu_id'];
    }

}
