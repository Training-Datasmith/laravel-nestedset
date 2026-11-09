<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
class Nested_Set_Service_Provider extends ServiceProvider
{
    public function register(): void
    {
        Blueprint::macro('nested_set', function (): void {
            Nested_Set::columns($this);
        });
        Blueprint::macro('drop_nested_set', function (): void {
            Nested_Set::drop_columns($this);
        });
    }
}