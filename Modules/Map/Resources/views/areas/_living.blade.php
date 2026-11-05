{{-- "Living here" on a police district's profile: income, spending, poverty and unemployment beside its state's and
     Malaysia's, then schools and amenities, for the administrative district its pin is in (AreaLiving), which says which
     that is, as it isn't the police district. Nothing here is about crime, and the note says so. --}}
<section class="area-card area-wide" aria-labelledby="area-living-heading">
    <h2 id="area-living-heading">{{ __('Living here') }}</h2>
    <p class="area-sub area-living-intro">
        {{ __('For :area, the administrative district this police district\'s pin is in.', ['area' => $living->name()]) }}
        @if ($living->sharedWith !== [])
            {{ __('It also takes in :districts.', ['districts' => implode(', ', $living->sharedWith)]) }}
        @endif
    </p>

    <div class="area-table-wrap">
        <table class="area-table">
            <thead>
                <tr>
                    <td></td>
                    <th scope="col">{{ $living->name() }}</th>
                    @unless ($living->isWholeState())
                        <th scope="col">{{ $living->stateName() }}</th>
                    @endunless
                    <th scope="col">Malaysia</th>
                </tr>
            </thead>
            <tbody>
                @foreach (\Modules\Map\Support\AreaLiving::Compared as $measure)
                    @continue($living->value($measure) === null)
                    <tr>
                        <th scope="row">{{ \Modules\Map\Support\AreaLiving::label($measure) }} <span class="area-sub">({{ $living->year($measure) }})</span></th>
                        <td><strong>{{ \Modules\Map\Support\AreaLiving::format($measure, $living->value($measure)) }}</strong></td>
                        @unless ($living->isWholeState())
                            <td>{{ \Modules\Map\Support\AreaLiving::format($measure, $living->stateValue($measure)) }}</td>
                        @endunless
                        <td>{{ \Modules\Map\Support\AreaLiving::format($measure, $living->nationalValue($measure)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <dl class="area-stats area-living-stats">
        @foreach (['schools_primary', 'schools_secondary', 'schools_tertiary'] as $measure)
            @continue($living->value($measure) === null)
            <div class="area-stat">
                <dt>{{ \Modules\Map\Support\AreaLiving::label($measure) }}</dt>
                <dd><strong>{{ number_format($living->value($measure)) }}</strong> <span class="area-sub">{{ $living->year($measure) }}</span></dd>
            </div>
        @endforeach
        @if ($living->value('piped_water') !== null)
            <div class="area-stat">
                <dt>{{ __('Homes with') }}</dt>
                <dd>
                    @foreach (['piped_water', 'sanitation', 'electricity'] as $measure)
                        @continue($living->value($measure) === null)
                        <span class="area-sub">{{ \Modules\Map\Support\AreaLiving::label($measure) }}: {{ \Modules\Map\Support\AreaLiving::format($measure, $living->value($measure)) }}</span><br />
                    @endforeach
                </dd>
            </div>
        @endif
    </dl>

    <p class="area-note">{{ __('Figures for the whole administrative district, from the Department of Statistics Malaysia and the Ministry of Education. They describe the area, not its crime: they don\'t explain it.') }}</p>
</section>
