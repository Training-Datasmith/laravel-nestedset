<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use \Kalnoy\Nestedset\NodeTrait;

    protected $fillable = ['name', 'parent_id'];

    public $timestamps = false;

    public static function resetActionsPerformed()
    {
        static::$actionsPerformed = 0;
    }
}
