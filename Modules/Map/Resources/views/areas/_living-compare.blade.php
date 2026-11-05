{{-- "Living here" with areas side by side: each one's administrative district (AreaLiving) and its figures. Police
     districts in the same administrative district have the same figures, and the note says so. $livings: an AreaLiving
     or null for each of $profiles. --}}
@php
    $areas = collect($livings)->filter()->map(fn ($living) => $living->area);
    $sameArea = $areas->count() !== $areas->unique()->count();
@endphp
<section class="area-card area-wide" aria-labelledby="area-living-heading">
    <h2 id="area-living-heading">{{ __('Living here') }}</h2>
    <div class="area-table-wrap">
        <table class="area-table">
            <thead>
                <tr>
                    <td></td>
                    @foreach ($profiles as $at => $profile)
                        <th scope="col"><span class="area-swatch-inline"><span class="area-swatch" style="--swatch: {{ $colours[$at] }}"></span>{{ $profile->district->Name }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row">{{ __('Administrative district') }}</th>
                    @foreach ($livings as $living)<td>{{ $living?->name() ?? '–' }}</td>@endforeach
                </tr>
                @foreach ([...\Modules\Map\Support\AreaLiving::Compared, 'schools_primary', 'schools_secondary'] as $measure)
                    <tr>
                        <th scope="row">{{ \Modules\Map\Support\AreaLiving::label($measure) }}</th>
                        @foreach ($livings as $living)
                            <td>
                                @if (str_starts_with($measure, 'schools_'))
                                    {{ $living?->value($measure) === null ? '–' : number_format($living->value($measure)) }}
                                @else
                                    {{ \Modules\Map\Support\AreaLiving::format($measure, $living?->value($measure)) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($sameArea)
        <p class="area-note">{{ __('Police districts in the same administrative district have the same figures here.') }}</p>
    @endif
    <p class="area-note">{{ __('Figures for the whole administrative district, from the Department of Statistics Malaysia and the Ministry of Education. They describe the area, not its crime: they don\'t explain it.') }}</p>
</section>
