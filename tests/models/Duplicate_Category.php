<?php

declare(strict_types=1);

class Duplicate_Category extends \Illuminate\Database\Eloquent\Model
{
    use \Kalnoy\Nestedset\Node_Trait;

    protected $table = 'categories';

    protected $fillable = [ 'name' ];

    public $timestamps = false;
}
