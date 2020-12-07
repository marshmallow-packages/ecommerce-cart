<?php

namespace Marshmallow\Ecommerce\Cart\Http\Controllers;

use Illuminate\Routing\Controller;

class IndexController extends Controller
{
    public function __invoke()
    {
        return view('ecommerce::layout');
    }
}
