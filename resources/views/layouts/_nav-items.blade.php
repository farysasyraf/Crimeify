{{-- One level of the left menu, laid out as in AMV's sidebar; calls itself for the links under each item.
     Every link shows its Material icon; links under a group get a slightly smaller one. --}}
<ul class="side-list side-level-{{ $level }}" @isset($listId) id="{{ $listId }}" @endisset>
    @foreach ($items as $navItem)
        @php
            $current = url()->current();
            $ariaCurrent = $navItem->currentState($current, $navPageHasLink ?? false);
            $hasChildren = $navItem->children->isNotEmpty();
            $subListId = 'side-sub-'.$navItem->Id;
            $lead = '<span class="material-icon side-icon" aria-hidden="true">'.e($navItem->iconOrDefault()).'</span>';
            // Rendered open; sidebar.js then closes groups that don't hold the current page, as AMV opens only that one.
            $toggleAttributes = $hasChildren
                ? 'data-submenu-toggle aria-expanded="true" aria-controls="'.$subListId.'"'
                    .($navItem->leadsTo($current) ? ' data-active-branch' : '')
                : '';
        @endphp
        <li>
            <div class="side-row">
                @if ($navItem->isHeading())
                    <button type="button" class="side-link side-group" {!! $toggleAttributes !!}>{!! $lead !!}<span class="side-text">{{ $navItem->Label }}</span>@include('layouts._chevron')</button>
                @else
                    <a href="{{ url($navItem->Url) }}" @class(['side-link', 'active' => $ariaCurrent !== 'false']) aria-current="{{ $ariaCurrent }}">{!! $lead !!}<span class="side-text">{{ $navItem->Label }}</span></a>
                    @if ($hasChildren)
                        <button type="button" class="side-toggle" aria-label="{{ $navItem->Label }} submenu" {!! $toggleAttributes !!}>
                            @include('layouts._chevron')
                        </button>
                    @endif
                @endif
            </div>
            @if ($hasChildren)
                @include('layouts._nav-items', ['items' => $navItem->children, 'level' => $level + 1, 'listId' => $subListId])
            @endif
        </li>
    @endforeach
</ul>
