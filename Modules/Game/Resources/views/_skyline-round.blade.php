{{-- A round of Skyline, for the solo game (skyline.js), the phones (live.js) and the big screen (live-host.js): the
     district to guess, its crime, year and state; its state's towers in 3D (skyline-scene.js); with $controls, the slider
     and box to guess with ($id names them), and Confirm; and every district's count as a list, open instead of the
     towers where they can't be drawn. The scripts find its parts by data-sky. --}}
<div class="skyline-round" data-sky-round>
    <div class="guessr-ask skyline-ask">
        <p class="guessr-prompt">{{ __('How tall is') }} <strong data-sky="district"></strong>?</p>
        <span class="guessr-place">
            <span class="material-icon" aria-hidden="true" data-sky="icon">local_police</span><span data-sky="type"></span> · <span data-sky="year"></span>
        </span>
        <span class="guessr-place"><span class="material-icon" aria-hidden="true">location_city</span><span data-sky="state"></span></span>
    </div>

    <div class="skyline-stage" data-sky="stage">
        <div class="skyline-scene" data-sky="scene" role="img"></div>
        <p class="skyline-hint" aria-hidden="true">{{ __('Drag to turn · pinch to zoom · tap a tower for its count') }}</p>
    </div>
    <p class="skyline-flat" data-sky="flat" hidden>{{ __("Your browser can't draw the 3D towers, so here they are as a list.") }}</p>

    @if ($controls)
        <div class="skyline-controls" data-sky="controls">
            <div class="skyline-dial">
                <label for="{{ $id }}-height">{{ __('Your guess') }}</label>
                <output data-sky="value" for="{{ $id }}-height">0</output>
            </div>
            <input type="range" class="skyline-slider" id="{{ $id }}-height" min="0" max="100" step="1" value="0" data-sky="slider" />
            <div class="skyline-fine">
                <button type="button" class="btn skyline-step" data-sky-step="-1" aria-label="{{ __('A little lower') }}">
                    <span class="material-icon" aria-hidden="true">remove</span>
                </button>
                <input type="number" class="skyline-number" min="0" step="1" inputmode="numeric" data-sky="number"
                    aria-label="{{ __('Your guess, as a number') }}" />
                <button type="button" class="btn skyline-step" data-sky-step="1" aria-label="{{ __('A little higher') }}">
                    <span class="material-icon" aria-hidden="true">add</span>
                </button>
                <button type="button" class="btn btn-primary game-play skyline-confirm" data-sky="confirm" disabled>
                    <span class="material-icon" aria-hidden="true">check</span>{{ __('Confirm') }}
                </button>
            </div>
        </div>
    @endif

    <details class="skyline-list" data-sky="details">
        <summary>{{ __("Every district's count") }}</summary>
        <ol data-sky="list"></ol>
    </details>
</div>
