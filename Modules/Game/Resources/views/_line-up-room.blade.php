{{-- Line-Up's room (line-up-room.js, line-up.css), for the solo game ('solo'), a live game's phones ('phone') and its
     big screen ('host'): the wall ruled as a height chart under a strip light, the suspects along it, each place a cone
     of light, the state's outline in the corner with a letter where each suspect is, and the floor, numbered. Along its
     foot, but on the big screen, its controls: which way the order goes, with how to move them, or what's said, as how
     the line-up did; Confirm; and in the solo game, the next round. The script puts the suspects in; the page puts in
     their drawings once (_line-up-sprite.blade.php). What moves is said to screen readers. --}}
<div class="lu-room is-{{ $mode }}" data-lu-room data-mode="{{ $mode }}">
    <div class="lu-wall" aria-hidden="true"></div>
    <div class="lu-light" aria-hidden="true"></div>
    <div class="lu-marks" data-lu="marks" aria-hidden="true"></div>
    <figure class="lu-map" data-lu="map" aria-hidden="true" hidden>
        <figcaption>{{ __("Where they're from") }}</figcaption>
        <svg class="lu-map-art" data-lu="map-art" viewBox="0 0 200 160"></svg>
    </figure>
    <ol class="lu-line" data-lu="line" aria-label="{{ __('The line-up, from most crime on the left to least') }}"></ol>
    <div class="lu-floor" data-lu="floor" aria-hidden="true"></div>
    @unless ($mode === 'host')
        <div class="lu-console" data-lu="console">
            <div class="lu-console-words">
                <p class="lu-scale" data-lu="scale" aria-hidden="true"><span>{{ __('Most crime') }}</span><i></i><span>{{ __('Least') }}</span></p>
                <p class="lu-result" role="status" data-lu="result" hidden></p>
                @if ($mode === 'phone')
                    {{-- In a live game, from the answer on, the player's points and place. --}}
                    <p class="lu-standing" data-lu="standing" hidden></p>
                @endif
                <p class="lu-help" data-lu="help">{{ __('Tap one, then another, to swap them, or drag one along the line. Keys work too: Space picks one up, ← and → move it, and Enter confirms.') }}</p>
            </div>
            <button type="button" class="btn btn-primary game-play lu-console-button" data-lu="confirm">
                <span class="material-icon" aria-hidden="true">how_to_reg</span>{{ __('Confirm the line-up') }}
            </button>
            @if ($mode === 'solo')
                <button type="button" class="btn btn-primary game-play lu-console-button" data-lu="next" hidden>
                    <span class="material-icon" aria-hidden="true">skip_next</span><span data-lu="next-label">{{ __('Next round') }}</span>
                </button>
            @endif
        </div>
    @endunless
    <p class="lu-countdown" data-lu="countdown" hidden aria-hidden="true"></p>
</div>
<p class="visually-hidden" aria-live="polite" data-lu="said"></p>
