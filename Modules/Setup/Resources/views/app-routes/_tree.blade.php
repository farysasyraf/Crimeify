{{-- One level of menu links on the Routes page, each with its routes; calls itself for the links under each. --}}
<ul class="route-tree">
    @foreach ($items as $menu)
        <li>
            @if ($menu->appRoutes->isEmpty() && $menu->children->isEmpty())
                <span class="route-group-label">{{ $highlight($menu->Label) }}</span>
            @else
                <details class="route-group" {{ $search !== '' || in_array($menu->Id, $openMenuIds) ? 'open' : '' }}>
                    <summary>@include('setup::app-routes._toggle') {{ $highlight($menu->Label) }}</summary>
                    @include('setup::app-routes._list', ['routes' => $menu->appRoutes])
                    @if ($menu->children->isNotEmpty())
                        @include('setup::app-routes._tree', ['items' => $menu->children])
                    @endif
                </details>
            @endif
        </li>
    @endforeach
</ul>
