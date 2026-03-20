<?php

declare (strict_types=1);
namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Service_Provider;
class Nested_Set_Service_Provider extends Service_Provider
{
    public function register(): void
    {
        Blueprint::macro('nestedSet', function (): void {
            Nested_Set::columns($this);
        });
        Blueprint::macro('dropNestedSet', function (): void {
            Nested_Set::drop_columns($this);
        });
    }
}