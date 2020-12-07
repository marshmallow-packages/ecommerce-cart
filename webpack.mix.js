let mix = require('laravel-mix')

mix.setPublicPath('./')
   .js('resources/js/app.js', 'public/js')
   .sass('resources/css/app.scss', 'public/css')
   .postCss('resources/css/ecommerce.css', 'public/css', [
        //
    ]);
