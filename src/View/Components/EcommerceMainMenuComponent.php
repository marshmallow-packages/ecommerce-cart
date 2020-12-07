<?php

namespace Marshmallow\Ecommerce\Cart\View\Components;

use Illuminate\View\Component;
use Marshmallow\Nova\Flexible\Layouts\MarshmallowLayout;

class EcommerceMainMenuComponent extends Component
{
    protected $menu;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->menu = nova_get_menu('ecommerce-main-menu');
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        return view('ecommerce::components.ecommerce-main-menu')->with([
            'menu' => $this->menu,
        ]);;
    }
}
