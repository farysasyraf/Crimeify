{{-- What a change does to a police station, detail by detail: for a changed one, each detail that differs, before →
     after; for one added or deleted, its address and phone number. The upload's review shows what a file would do, and
     the update log what was done. Takes $action, and the details $old and $new (null for none). --}}
@php
    $labels = ['Name' => 'Name', 'Region' => 'State', 'District' => 'Police district', 'Address' => 'Address', 'Phone' => 'Phone'];
    $shown = fn (string $field, ?string $value) => match (true) {
        $value === null || $value === '' => '—',
        $field === 'Region' => config("map.states.{$value}.name", $value),
        default => $value,
    };
@endphp
<dl class="station-review-fields">
    @foreach ($labels as $field => $label)
        @if ($action === 'changed' && ($old[$field] ?? null) !== ($new[$field] ?? null))
            <div><dt>{{ $label }}</dt><dd><span class="muted">{{ $shown($field, $old[$field] ?? null) }}</span> → {{ $shown($field, $new[$field] ?? null) }}</dd></div>
        @elseif ($action === 'added' && in_array($field, ['Address', 'Phone'], true))
            <div><dt>{{ $label }}</dt><dd>{{ $shown($field, $new[$field] ?? null) }}</dd></div>
        @elseif ($action === 'deleted' && in_array($field, ['Address', 'Phone'], true))
            <div><dt>{{ $label }}</dt><dd class="muted">{{ $shown($field, $old[$field] ?? null) }}</dd></div>
        @endif
    @endforeach
</dl>
