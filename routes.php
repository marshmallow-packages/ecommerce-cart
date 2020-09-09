<?php

Route::group(['prefix' => 'cart', 'namespace' => 'Marshmallow\Ecommerce\Cart\Http\Controllers'], function(){
	Route::get('/', 'CartController@index');
});