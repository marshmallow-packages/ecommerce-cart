<?php

namespace Marshmallow\Example\Database\Seeds;

use Illuminate\Database\Seeder;

/**
 * php artisan db:seed --class=Marshmallow\\Example\\Database\\Seeds\\ExampleSeeder
 */

class ExampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        factory(\Marshmallow\Example\Models\Example::class, 10)->create();
    }
}
