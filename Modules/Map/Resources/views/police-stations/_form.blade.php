{{-- A police station's details: on Add police station and Edit police station. The police district suggests the
     districts already known in each state ($districts), but any can be typed. --}}
@php($region = old('region', $station->Region))
<div class="field">
    <label for="region">State or federal territory</label>
    <select id="region" name="region" required data-placeholder="Choose one" @class(['is-invalid' => $errors->has('region')])>
        <option value=""></option>
        @foreach (['States' => false, 'Federal territories' => true] as $group => $territory)
            <optgroup label="{{ $group }}">
                @foreach (collect(config('map.states'))->where('territory', $territory)->sortBy('name') as $code => $place)
                    <option value="{{ $code }}" @selected($region === $code)>{{ $place['name'] }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
    @error('region')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="district">Police district</label>
    <input type="text" id="district" name="district" value="{{ old('district', $station->District) }}" required maxlength="80" list="district-list" autocomplete="off" aria-describedby="district-hint" @class(['is-invalid' => $errors->has('district')]) />
    <datalist id="district-list">
        @foreach ($districts->sortKeys() as $code => $names)
            @foreach ($names as $name)
                <option value="{{ $name }}">{{ config("map.states.{$code}.name") }}</option>
            @endforeach
        @endforeach
    </datalist>
    <span class="field-hint" id="district-hint">The district it serves, like Batu Pahat. The Map page shows it with the station.</span>
    @error('district')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="{{ old('name', $station->Name) }}" required maxlength="120" placeholder="Ibu Pejabat Polis Daerah Batu Pahat" @class(['is-invalid' => $errors->has('name')]) />
    @error('name')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="address">Address <span class="muted">(optional)</span></label>
    <textarea id="address" name="address" rows="3" maxlength="300" placeholder="Street, postcode and town, state" @class(['is-invalid' => $errors->has('address')])>{{ old('address', $station->Address) }}</textarea>
    @error('address')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>

<div class="field">
    <label for="phone">Phone <span class="muted">(optional)</span></label>
    <input type="tel" id="phone" name="phone" value="{{ old('phone', $station->Phone) }}" maxlength="30" placeholder="07-436 3300" @class(['is-invalid' => $errors->has('phone')]) />
    @error('phone')
        <span class="field-error">{{ $message }}</span>
    @enderror
</div>
