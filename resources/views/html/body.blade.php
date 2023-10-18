<body class="d-flex flex-column h-100">
    <x-ecommerce-main-menu/>
    @foreach ($layouts as $layout)
        {{ $layout->render() }}
    @endforeach

    @include('ecommerce::html.footer')
</body>
