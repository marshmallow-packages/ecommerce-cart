<?php

use Faker\Generator as Faker;
use Marshmallow\Example\Models\Example;

/**
 * factory(Marshmallow\Example\Models\Example::class, 10)->create();
 */
$factory->define(Example::class, function (Faker $faker) {
	return [
		'name' => $faker->name,
    ];
});