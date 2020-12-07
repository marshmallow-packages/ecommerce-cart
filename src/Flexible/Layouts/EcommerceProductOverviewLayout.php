<?php

namespace Marshmallow\Ecommerce\Cart\Flexible\Layouts;

use Marshmallow\Nova\Flexible\Layouts\MarshmallowLayout;
use Marshmallow\Ecommerce\Cart\View\Components\EcommerceProductOverviewComponent;

class EcommerceProductOverviewLayout extends MarshmallowLayout
{

    /**
     * The layout's name
     *
     * @var string
     */
    protected $name = 'ecommerce-product-overview';

    /**
     * The displayed title
     *
     * @var string
     */
    protected $title = 'Product overview';

    /**
     * The displayed description
     *
     * @var string
     */
    protected $description = 'Main product overview including filtering and ordering.';

    /**
     * The displayed image
     *
     * @var string
     */
    protected $image = 'https://marshmallow.dev/cdn/flex/default.jpg';

    /**
     * Tags on which this layout can be filtered
     *
     * @var string
     */
    protected $tags = ['e-commerce'];

    /**
     * Get the fields displayed by the layout.
     *
     * @return array
     */
    public function fields()
    {
        return [
            //
        ];
    }


    /**
     * Get the Component class that handles the views
     * for this layout
     *
     * @return string Class of the compontent class
     */
    protected function getComponentClass()
    {
        return EcommerceProductOverviewComponent::class;
    }
}
