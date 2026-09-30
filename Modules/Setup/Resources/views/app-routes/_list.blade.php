{{-- The routes directly under one menu link. Choosing one opens it in the form, like AMV's edit icon. --}}
@if ($routes->isNotEmpty())
    <ul class="route-list">
        @foreach ($routes as $saved)
            <li @class(['route-row', 'is-highlighted' => $saved->Id === $route->Id])>
                <a class="route-main" href="{{ route('routes.edit', [$saved, 'search' => $search ?: null]) }}" @if ($saved->Id === $route->Id) aria-current="true" @endif>
                    <span class="method method-{{ strtolower($saved->HttpMethods) }}">{{ $saved->HttpMethods }}</span>
                    <code class="route-address">{{ $highlight($saved->address()) }}</code>
                    <span class="route-handler">{{ $highlight($saved->handler()) }}</span>
                    @if ($saved->isBroken())
                        <span class="badge badge-danger">Code not found</span>
                    @endif
                    @if ($saved->isLimitedToRoles())
                        <span class="badge badge-role route-roles" title="Only users with these roles can open it">
                            <span class="material-icon" aria-hidden="true">lock</span>{{ $saved->roles->pluck('Name')->implode(', ') ?: 'No one' }}
                        </span>
                    @endif
                </a>
                @if ($saved->canOpen())
                    {{-- Any parameters are optional here, so the bare path opens it. --}}
                    <a class="btn btn-sm btn-ghost" href="{{ url($saved->Path) }}" target="_blank" rel="noopener">Open</a>
                @endif
            </li>
        @endforeach
    </ul>
@endif
