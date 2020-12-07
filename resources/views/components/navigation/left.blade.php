<ul class="navbar-nav mr-auto">
    @foreach($menu['menuItems'] as $menu_item)
        <li class="nav-item">
            <a class="nav-link" href="{{ $menu_item['value']->route() }}">
                {{ $menu_item['name'] }}
            </a>
        </li>
    @endforeach
</ul>
