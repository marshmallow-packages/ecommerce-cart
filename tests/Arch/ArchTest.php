<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Marshmallow\Ecommerce\Cart\Exceptions\CartException;
use Marshmallow\Ecommerce\Cart\Support\Price;

arch('the source declares strict types')
    ->expect('Marshmallow\Ecommerce\Cart')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect('Marshmallow\Ecommerce\Cart')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'ddd']);

arch('the php preset holds')
    ->preset()->php()->ignoring('Marshmallow\Payable');

arch('the security preset holds')
    ->preset()->security()->ignoring('Marshmallow\Payable');

arch('events are final')
    ->expect('Marshmallow\Ecommerce\Cart\Events')
    ->toBeFinal();

arch('enums are enums')
    ->expect('Marshmallow\Ecommerce\Cart\Enums')
    ->toBeEnums();

arch('contracts are interfaces')
    ->expect('Marshmallow\Ecommerce\Cart\Contracts')
    ->toBeInterfaces();

arch('models are eloquent models a host application may extend')
    ->expect('Marshmallow\Ecommerce\Cart\Models')
    ->toExtend(Model::class)
    ->not->toBeFinal();

arch('every exception descends from the base cart exception')
    ->expect('Marshmallow\Ecommerce\Cart\Exceptions')
    ->toExtend(CartException::class)
    ->ignoring(CartException::class);

arch('the price value object is final and immutable')
    ->expect(Price::class)
    ->toBeFinal()
    ->toBeReadonly();

arch('listeners expose a handle method')
    ->expect('Marshmallow\Ecommerce\Cart\Listeners')
    ->toHaveMethod('handle');

arch('facades extend the laravel facade')
    ->expect('Marshmallow\Ecommerce\Cart\Facades')
    ->toExtend(Facade::class);

arch('the core carries no admin panel dependency')
    ->expect('Marshmallow\Ecommerce\Cart')
    ->not->toUse(['Laravel\Nova', 'Filament', 'Marshmallow\Nova', 'Marshmallow\Priceable', 'Marshmallow\Product']);

arch('configuration is read through config(), never env()')
    ->expect('Marshmallow\Ecommerce\Cart')
    ->not->toUse('env');
