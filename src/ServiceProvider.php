<?php

namespace Ockle\Extasset;

use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $app = $this->app;

        $this->app[Kernel::class]->command('extasset:update', function () use ($app) {
            $app[Extasset::class]->update(new Client);
        });

        $this->publishes([
            __DIR__ . '/../config/extasset.php' => config_path('extasset.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Extasset::class, function () {
            return new Extasset(
                $this->app['config'],
                $this->app['cache'],
                $this->app['filesystem'],
                $this->app['log']
            );
        });
    }
}
