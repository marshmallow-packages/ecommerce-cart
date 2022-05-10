<?php

namespace Marshmallow\Ecommerce\Cart\Nova;

use App\Nova\Resource;
use Eminiarts\Tabs\Tabs;
use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Textarea;
use Marshmallow\Nova\Flexible\Flexible;
use Marshmallow\Product\Models\Product;
use Marshmallow\Product\Models\ProductCategory;
use Marshmallow\NovaGenerateString\GenerateString;
use Sloveniangooner\SearchableSelect\SearchableSelect;
use Epartment\NovaDependencyContainer\NovaDependencyContainer;
use Marshmallow\Ecommerce\Cart\Helpers\DiscountProductSelector;
use Marshmallow\Ecommerce\Cart\Helpers\DiscountCustomerSelector;
use Marshmallow\Ecommerce\Cart\Models\Discount as ModelsDiscount;
use Marshmallow\Ecommerce\Cart\Helpers\DiscountProductCategorySelector;

class Discount extends Resource
{

    public static function group()
    {
        return __('Discount');
    }

    public static $group_icon = '<svg aria-hidden="true" focusable="false" data-prefix="far" data-icon="badge-percent" class= "svg-inline--fa fa-badge-percent sidebar-icon" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="var(--sidebar-icon)" d="M160 192C160 174.3 174.3 160 192 160C209.7 160 224 174.3 224 192C224 209.7 209.7 224 192 224C174.3 224 160 209.7 160 192zM352 320C352 337.7 337.7 352 320 352C302.3 352 288 337.7 288 320C288 302.3 302.3 288 320 288C337.7 288 352 302.3 352 320zM208.1 336.1C199.6 346.3 184.4 346.3 175 336.1C165.7 327.6 165.7 312.4 175 303L303 175C312.4 165.7 327.6 165.7 336.1 175C346.3 184.4 346.3 199.6 336.1 208.1L208.1 336.1zM344.1 43.41C377 39.1 411.6 49.59 437 74.98C462.4 100.4 472.9 134.1 468.6 167.9C494.1 188.2 512 220.1 512 256C512 291.9 494.1 323.8 468.6 344.1C472.9 377 462.4 411.6 437 437C411.6 462.4 377 472.9 344.1 468.6C323.8 494.1 291.9 512 256 512C220.1 512 188.2 494.1 167.9 468.6C134.1 472.9 100.4 462.4 74.98 437C49.6 411.6 39.1 377 43.41 344.1C17.04 323.8 0 291.9 0 256C0 220.1 17.04 188.2 43.42 167.9C39.1 134.1 49.6 100.4 74.98 74.98C100.4 49.6 134.1 39.1 167.9 43.41C188.2 17.04 220.1 0 256 0C291.9 0 323.8 17.04 344.1 43.41L344.1 43.41zM190.1 99.07L172 93.25C150.4 86.6 125.1 91.87 108.9 108.9C91.87 125.1 86.6 150.4 93.25 172L99.07 190.1L81.55 200.3C61.54 210.9 48 231.9 48 256C48 280.1 61.54 301.1 81.55 311.7L99.07 321L93.25 339.1C86.6 361.6 91.87 386 108.9 403.1C125.1 420.1 150.4 425.4 172 418.7L190.1 412.9L200.3 430.5C210.9 450.5 231.9 464 256 464C280.1 464 301.1 450.5 311.7 430.5L321 412.9L339.1 418.8C361.6 425.4 386 420.1 403.1 403.1C420.1 386 425.4 361.6 418.7 339.1L412.9 321L430.5 311.7C450.5 301.1 464 280.1 464 256C464 231.9 450.5 210.9 430.5 200.3L412.9 190.1L418.7 172C425.4 150.4 420.1 125.1 403.1 108.9C386 91.87 361.6 86.6 339.1 93.25L321 99.07L311.7 81.55C301.1 61.54 280.1 48 256 48C231.9 48 210.9 61.54 200.3 81.55L190.1 99.07z"></path></svg>';



    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = 'Marshmallow\Ecommerce\Cart\Models\Discount';

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [

            (new Tabs('Discount', [
                'Setup'    => [
                    ID::make(),
                    Heading::make(__('Voucher code')),
                    GenerateString::make(__('Code'), 'discount_code')
                        ->creationRules('required', 'string', 'min:' . config('cart-discount.voucher.min_length'))
                        ->updateRules('nullable', 'string', 'min:' . config('cart-discount.voucher.min_length'))
                        ->length(config('cart-discount.voucher.min_length'))
                        ->excludeRules(config('cart-discount.voucher.exclude_rules')),

                    Heading::make(__('Types')),
                    Select::make(__('Discount type'), 'discount_type')->options([
                        ModelsDiscount::TYPE_PERCENTAGE => __('Percentage'),
                        ModelsDiscount::TYPE_FIXED_AMOUNT => __('Fixed Amount'),
                        ModelsDiscount::TYPE_FREE_SHIPPING => __('Free Shipping'),
                    ])
                        ->withMeta($this->discount_type ? [] : ['value' => ModelsDiscount::TYPE_PERCENTAGE])
                        ->required()
                        ->rules('required')
                        ->displayUsingLabels(),

                    NovaDependencyContainer::make([
                        Heading::make(__('Amount')),
                        Currency::make(__('Fixed amount'), 'fixed_amount')->required()
                            ->rules('required')
                            ->resolveUsing(function ($value) {
                                if ($value) {
                                    return $value / 100;
                                }
                            }),
                    ])->dependsOn('discount_type', 'fixed_amount'),
                    NovaDependencyContainer::make([
                        Heading::make(__('Amount')),
                        Number::make(__('Percentage amount'), 'percentage_amount')->required()
                            ->rules('required'),
                    ])->dependsOn('discount_type', 'percentage'),

                    Boolean::make(__('Active'), 'is_active')
                        ->withMeta($this->is_active !== null ? [] : ['value' => true])
                ],
                __('Applies to') => [
                    Select::make(__('Applies to'), 'applies_to')->options([
                        ModelsDiscount::APPLIES_TO_ALL => __('All products'),
                        ModelsDiscount::APPLIES_TO_CATEGORIES => __('Specific categories'),
                        ModelsDiscount::APPLIES_TO_PRODUCTS => __('Specific products'),
                    ])
                        ->withMeta($this->applies_to ? [] : ['value' => ModelsDiscount::APPLIES_TO_ALL])
                        ->displayUsingLabels()
                        ->required()
                        ->rules('required'),

                    NovaDependencyContainer::make([
                        SearchableSelect::make(__('Categories'), 'applies_to_product_categories')
                            ->resource(config('cart.nova.resources.product_category'))
                            ->multiple()
                            ->displayUsingLabels()
                    ])->dependsOn('applies_to', 'specific_categories'),

                    NovaDependencyContainer::make([
                        SearchableSelect::make(__('Products'), 'applies_to_products')
                            ->resource(config('cart.nova.resources.product'))
                            ->multiple()
                            ->displayUsingLabels()
                    ])->dependsOn('applies_to', 'specific_products'),
                ],
                __('Requirements') => [
                    Select::make(__('Minimum requirements'), 'prerequisite_type')->options([
                        ModelsDiscount::PREREQUISITE_NONE => __('None'),
                        ModelsDiscount::PREREQUISITE_PURCHASE_AMOUNT => __('Minimum purchase amount (€)'),
                        ModelsDiscount::PREREQUISITE_QUANTITY => __('Minimum quantity of items'),
                    ])
                        ->withMeta($this->prerequisite_type ? [] : ['value' => ModelsDiscount::PREREQUISITE_NONE])
                        ->displayUsingLabels()
                        ->required()
                        ->rules('required'),

                    NovaDependencyContainer::make([
                        Currency::make(__('Minimum purchase amount'), 'prerequisite_purchase_amount')
                            ->hideFromIndex()
                            ->required()
                            ->rules('required')
                            ->resolveUsing(function ($value) {
                                if ($value) {
                                    return $value / 100;
                                }
                            }),
                    ])->dependsOn('prerequisite_type', 'prerequisite_purchase_amount'),

                    NovaDependencyContainer::make([
                        Number::make(__('Minimum quantity of items'), 'prerequisite_quantity')
                            ->hideFromIndex()
                            ->required()
                            ->rules('required'),
                    ])->dependsOn('prerequisite_type', 'prerequisite_quantity'),


                ],
                __('Eligble for') => [
                    Select::make(__('Customer eligibility'), 'eligible_for')->options([
                        ModelsDiscount::ELIGIBLE_FOR_ALL => __('All customers'),
                        ModelsDiscount::ELIGIBLE_FOR_CUSTOMERS => __('Specific customers'),
                        ModelsDiscount::ELIGIBLE_FOR_EMAILS => __('Specific emailaddresses'),
                    ])
                        ->withMeta($this->eligible_for ? [] : ['value' => ModelsDiscount::ELIGIBLE_FOR_ALL])
                        ->displayUsingLabels()
                        ->required()->rules('required'),

                    NovaDependencyContainer::make([
                        SearchableSelect::make(__('Customers'), 'eligible_for_customers')
                            ->resource(config('cart.nova.resources.customer'))
                            ->multiple()
                            ->displayUsingLabels()
                            ->required()
                            ->rules('required')
                    ])->dependsOn('eligible_for', 'eligible_for_customers'),

                    NovaDependencyContainer::make([
                        Textarea::make(__('Emails'), 'eligible_for_emails')->help(__('Please add one emailaddress per row.'))->resolveUsing(function ($value) {
                            $value = is_array($value) ? $value : json_decode($value, true);
                            if ($value) {
                                return join("\n", $value);
                            }
                        })->required()->rules('required'),
                    ])->dependsOn('eligible_for', 'eligible_for_emails'),
                ],

                __('Limits') => [
                    Number::make(__('Total usage limit'), 'total_usage_limit'),
                    Boolean::make(__('Once per customer'), 'is_once_per_customer'),

                    Heading::make(__('Active dates')),
                    DateTime::make(__('Starts at'), 'starts_at')->nullable(),
                    DateTime::make(__('Starts at'), 'ends_at')->nullable(),
                ],
            ]))->withToolbar(),
        ];
    }

    /**
     * Get the cards available for the request.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function cards(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function filters(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function lenses(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function actions(NovaRequest $request)
    {
        return [];
    }
}
