@if ($item->isHeading())
    <span class="muted">None · heading</span>
@else
    <code>{{ $item->Url }}</code>
@endif
