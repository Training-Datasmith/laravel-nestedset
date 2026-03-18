<?php

declare(strict_types=1);

namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class NestedSetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Blueprint::macro('nestedSet', function (): void {
            NestedSet::columns($this);
        });

        Blueprint::macro('dropNestedSet', function (): void {
            NestedSet::dropColumns($this);
        });
    }
}
