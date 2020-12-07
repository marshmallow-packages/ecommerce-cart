<?php

namespace Marshmallow\Ecommerce\Cart\View\Components;

use Illuminate\View\Component;
use Marshmallow\Nova\Flexible\Layouts\MarshmallowLayout;

class EcommerceProductOverviewComponent extends Component
{
    protected $layout;
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(MarshmallowLayout $layout)
    {
        $this->layout = $layout;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        return view('ecommerce::components.ecommerce-product-overview')->with([
            'layout' => $this->layout,
        ]);;
    }
}
