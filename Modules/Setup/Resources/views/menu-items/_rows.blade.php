{{-- Table rows for one level of the menu; calls itself for the links under each item. --}}
@foreach ($items as $item)
    <tr>
        <td class="num muted">{{ $item->SortOrder }}</td>
        <td>
            <span class="tree-label tree-level-{{ $level }}">
                @if ($level > 1)
                    <span class="tree-mark" aria-hidden="true">└</span>
                @endif
                <span class="material-icon tree-icon" aria-hidden="true">{{ $item->iconOrDefault() }}</span>
                {{ $item->Label }}
            </span>
        </td>
        <td class="num">{{ $level }}</td>
        <td>@include('setup::menu-items._link')</td>
        <td>@include('setup::menu-items._visibility')</td>
        <td class="actions">
            @if ($level < \App\Models\MenuItem::MaxLevel)
                <a class="btn btn-sm btn-ghost" href="{{ page_url('menu-items/create', ['parent' => $item->Id]) }}">+ Sub-link</a>
            @endif
            <a class="btn btn-sm" href="{{ page_url('menu-items/edit', ['menu_item' => $item]) }}">Edit</a>
            <a class="btn btn-sm btn-danger-ghost" href="{{ page_url('menu-items/delete', ['menu_item' => $item]) }}">Delete</a>
        </td>
    </tr>
    @include('setup::menu-items._rows', ['items' => $item->children, 'level' => $level + 1])
@endforeach
