{{-- Up to three police districts to check, by state, as Select2 boxes with a search. One opens its profile; two or
     three, them side by side (AreaController@compare). $chosen: the paths already chosen. --}}
<form method="get" action="{{ route('public.areas.compare') }}" class="area-picker">
    {{-- In the page's language, as every link here keeps it. --}}
    @foreach ($language as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
    @endforeach
    @foreach (range(0, \Modules\Map\Http\Controllers\AreaController::MaxCompared - 1) as $slot)
        <div class="field">
            <label for="area-{{ $slot }}">{{ $slot === 0 ? __('Area') : __('Compare with (optional)') }}</label>
            <select id="area-{{ $slot }}" name="areas[]" data-placeholder="{{ __('Choose a police district') }}" @required($slot === 0)>
                <option value=""></option>
                @foreach ($regions as $region => $districts)
                    <optgroup label="{{ $region }}">
                        @foreach ($districts as $district)
                            <option value="{{ $district['path'] }}" @selected(($chosen[$slot] ?? null) === $district['path'])>{{ $district['name'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
    @endforeach
    <button type="submit" class="btn btn-primary">{{ __('Check') }}</button>
</form>
