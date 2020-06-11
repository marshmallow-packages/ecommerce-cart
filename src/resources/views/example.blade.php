<h1>Shopping cart example</h1>

example.blade is loaded successfully<br/>
Translation ping: {{ trans('ecommerce::cart.ping') }}<br/>
Config ping: {{ config('cart.ping') }}<br/>
Facade: Ping -> {{ Cart::ping() }}<br/>
<x-ecommerce-cart />