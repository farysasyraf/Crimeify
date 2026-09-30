@if ($item->VisibleToEveryone)
    <span class="muted">Everyone</span>
@else
    <div class="badges">
        @forelse ($item->roles as $role)
            <span class="badge badge-role">{{ $role->Name }}</span>
        @empty
            {{-- Its roles were deleted, so nobody sees it until new roles are chosen. --}}
            <span class="text-danger">No one</span>
        @endforelse
    </div>
@endif
