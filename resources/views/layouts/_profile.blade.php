{{-- The signed-in user at the top of the menu, as in AMV's sidebar: opens to My profile and Log out. --}}
@php
    $me = auth()->user();
    $profileUrl = route('profile');
    $onProfile = url()->current() === $profileUrl;
@endphp
<ul class="side-list side-level-1 side-profile">
    <li>
        <div class="side-row">
            <button type="button" class="side-link side-group" data-submenu-toggle aria-expanded="true" aria-controls="side-profile-menu"{!! $onProfile ? ' data-active-branch' : '' !!}>
                @if ($photoVersion = $me->photoVersion())
                    <img class="side-avatar" src="{{ route('profile.photo', ['v' => $photoVersion]) }}" alt="" width="30" height="30" />
                @else
                    <span class="side-avatar" aria-hidden="true">{{ $me->initials() }}</span>
                @endif
                <span class="side-text">{{ $me->Name }}</span>
                @include('layouts._chevron')
            </button>
        </div>
        <ul class="side-list side-level-2" id="side-profile-menu">
            <li>
                <div class="side-row">
                    <a href="{{ $profileUrl }}" @class(['side-link', 'active' => $onProfile]) aria-current="{{ $onProfile ? 'page' : 'false' }}"><span class="material-icon side-icon" aria-hidden="true">person</span><span class="side-text">My profile</span></a>
                </div>
            </li>
            <li>
                <form method="post" action="{{ route('logout') }}" class="side-row">
                    @csrf
                    <button type="submit" class="side-link"><span class="material-icon side-icon" aria-hidden="true">logout</span><span class="side-text">Log out</span></button>
                </form>
            </li>
        </ul>
    </li>
</ul>
