<?php

namespace Nooqta\Larifriqiya;

use Illuminate\Support\ServiceProvider;
use Nooqta\Larifriqiya\Commands\MigrationCommand;
use Nooqta\Larifriqiya\Commands\ModelCommand;

class LarifriqiyaProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->commands([
            MigrationCommand::class,
            ModelCommand::class,
        ]);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
