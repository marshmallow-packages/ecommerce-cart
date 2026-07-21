<?php

declare(strict_types=1);

arch('the source declares strict types')
    ->expect('Marshmallow\Ecommerce\Cart')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'ddd'])
    ->not->toBeUsed();

arch('events are final')
    ->expect('Marshmallow\Ecommerce\Cart\Events')
    ->toBeFinal();

arch('enums are enums')
    ->expect('Marshmallow\Ecommerce\Cart\Enums')
    ->toBeEnums();

arch('contracts are interfaces')
    ->expect('Marshmallow\Ecommerce\Cart\Contracts')
    ->toBeInterfaces();
