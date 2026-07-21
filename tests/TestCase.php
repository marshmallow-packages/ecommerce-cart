<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Application;
use Marshmallow\Addressable\ServiceProvider as AddressableServiceProvider;
use Marshmallow\Datasets\Country\ServiceProvider as CountryServiceProvider;
use Marshmallow\Ecommerce\Cart\CartServiceProvider;
use Marshmallow\Payable\PayableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(function (string $model): string {
            $namespace = str_starts_with($model, 'Workbench\\')
                ? 'Workbench\\Database\\Factories\\'
                : 'Marshmallow\\Ecommerce\\Cart\\Database\\Factories\\';

            return $namespace.class_basename($model).'Factory';
        });

        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AddressableServiceProvider::class,
            CountryServiceProvider::class,
            PayableServiceProvider::class,
            CartServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cart.models.user', User::class);
        $app['config']->set('cart.models.product', Product::class);
        $app['config']->set('auth.providers.users.model', User::class);
    }
}
