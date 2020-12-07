<body class="d-flex flex-column h-100">
    <x-ecommerce-main-menu/>
    <livewire:shopping-cart />
    <livewire:product-to-cart />
    @foreach ($layouts as $layout)
        {{ $layout->render() }}
    @endforeach

    @include('ecommerce::html.footer')
    @livewireScripts
</body>
