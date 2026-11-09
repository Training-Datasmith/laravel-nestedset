<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use \Kalnoy\Nestedset\Node_Trait;

    protected $fillable = ['name', 'parent_id'];

    public $timestamps = false;

    public static function reset_actions_performed()
    {
        static::$actions_performed = 0;
    }
}
