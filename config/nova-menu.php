<?php

use Marshmallow\MultiLanguage\Models\Language;

return [
    /*
    |--------------------------------------------------------------------------
    | Resource
    |--------------------------------------------------------------------------
    |
    | Optionally override the original Menu resource.
    */

    'resource' => OptimistDigital\MenuBuilder\Http\Resources\MenuResource::class,

    /*
    |--------------------------------------------------------------------------
    | MenuItem Model
    |--------------------------------------------------------------------------
    |
    | Optionally override the original Menu Item model.
    */

    'menu_item_model' => OptimistDigital\MenuBuilder\Models\MenuItem::class,


    /*
    |--------------------------------------------------------------------------
    | Menus table name
    |--------------------------------------------------------------------------
    */

    'menus_table_name' => 'nova_menu_menus',


    /*
    |--------------------------------------------------------------------------
    | Menu items table name
    |--------------------------------------------------------------------------
    */

    'menu_items_table_name' => 'nova_menu_menu_items',


    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Set all the available locales as either [key => name] pairs, a closure
    | or a callable (ie 'locales' => 'nova_lang_get_all_locales').
    */

    'locales' => function() {
        return Language::getLanguageArray();
    },


    /*
    |--------------------------------------------------------------------------
    | Linkable models
    |--------------------------------------------------------------------------
    |
    | Set all the linkable models in an array.
    */

    'linkable_models' => [],

    /*
    |--------------------------------------------------------------------------
    | Auto-load migrations
    |--------------------------------------------------------------------------
    |
    | Allow auto-loading of migrations (without the need to publish them)
    */

    'auto_load_migrations' => true,
];
