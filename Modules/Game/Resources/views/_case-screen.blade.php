{{-- Case File's game screen, for the solo game (case-file.js), the phones (live.js) and the big screen (live-host.js),
     as $mode says ('solo', 'phone' or 'host'): one pixel screen that the whole game is played in, as an adventure game is.
     Its views, one at a time: the office (case-room.png), its clues laid over it where they sit, each a button, and the
     corkboard, to the suspects; a clue's close-up; the suspects, index cards on the corkboard, to pick and accuse; and
     for the solo game, the case file's cover to start on, the countdown, and the closing report. Over them: the strip
     along the top with the case, the time, what it's worth and the score, and the text box along the foot.
     case-screen.js runs it, finding its parts by data-cs; the clues' places, in the room's pixels, follow
     Modules/Game/Resources/art/make_case_art.py's CLUES, ROOM_TOP lower. --}}
@php
    $props = [
        // The clue, the object it's on, where it sits in the room (x, y, width, height), and its row in case-clues.png.
        'rap' => ['folder', 90, 128, 42, 20, 0],
        'trend' => ['clipboard', 138, 122, 26, 22, 20],
        'rank' => ['ledger', 170, 132, 30, 16, 42],
        'tip' => ['note', 254, 133, 16, 13, 58],
        'photo' => ['photo', 264, 49, 30, 34, 71],
    ];
    $clueNames = ['rap' => __('Rap sheet'), 'trend' => __('Trend report'), 'rank' => __('Ranking'), 'photo' => __('Photo'), 'tip' => __('Tip-off')];
@endphp
<div class="case-screen is-{{ $mode }}" data-case-screen data-mode="{{ $mode }}" data-case-costs="{{ json_encode(config('game.case_file.clues')) }}"
    data-mugshot="{{ versioned_asset('modules/game/case-mugshot.png') }}" data-phone="{{ versioned_asset('modules/game/case-phone.png') }}"
    style="--wood: url('{{ versioned_asset('modules/game/case-wood.png') }}'); --cork: url('{{ versioned_asset('modules/game/case-cork.png') }}')">
    {{-- Along the top: the case, the time left, what it's worth, the score, and the way to the suspects or back. --}}
    <div class="case-hud" data-cs="hud" hidden>
        <span class="case-hud-item case-hud-label" data-cs="label"></span>
        <span class="case-hud-item" data-cs="time-box"><span class="material-icon" aria-hidden="true">schedule</span><span data-cs="time"></span></span>
        <span class="case-hud-item" data-cs="worth-box">{{ __('Worth') }} <strong data-cs="worth"></strong></span>
        <span class="case-hud-item" data-cs="score-box" hidden>{{ __('Score') }} <strong data-cs="score"></strong></span>
        <span class="case-hud-item" data-cs="extra" hidden></span>
        @unless ($mode === 'host')
            <button type="button" class="case-hud-nav" data-cs="nav" hidden>
                <span class="material-icon" aria-hidden="true" data-cs="nav-icon">groups</span><span data-cs="nav-label"></span>
            </button>
        @endunless
    </div>

    {{-- The office. --}}
    <section class="case-view case-view-room" data-cs-view="room" hidden aria-label="{{ __('The office') }}">
        <div class="case-scene" data-cs="scene">
            <img class="case-room-art" src="{{ versioned_asset('modules/game/case-room.png') }}" alt="" width="320" height="240" />
            <button type="button" class="case-spot" data-cs="to-board" style="left: 212px; top: 42px; width: 96px; height: 74px"
                aria-label="{{ __('The corkboard: the suspects') }}" @disabled($mode === 'host')></button>
            @foreach ($props as $clue => [$object, $x, $y, $w, $h, $row])
                <button type="button" class="case-prop case-prop-{{ $object }}" data-case-clue="{{ $clue }}"
                    style="left: {{ $x }}px; top: {{ $y }}px; width: {{ $w }}px; height: {{ $h }}px; --row: -{{ $row }}px; --open: -{{ $w }}px"
                    aria-label="{{ $clueNames[$clue] }}" @disabled($mode === 'host')></button>
            @endforeach
            {{-- The clock's hand, counting down; and the photo's picture, once it's turned over. --}}
            <canvas class="case-clock" data-cs="clock" width="17" height="17" aria-hidden="true"></canvas>
            <canvas class="case-photo" data-cs="photo" width="24" height="22" hidden aria-hidden="true"></canvas>
        </div>
        <p class="case-countdown" data-cs="countdown" hidden aria-hidden="true"></p>
    </section>

    {{-- A clue close up. --}}
    <section class="case-view case-view-clue" data-cs-view="clue" hidden>
        <div class="case-close" data-cs="close"></div>
    </section>

    {{-- The suspects, on the corkboard. --}}
    <section class="case-view case-view-board" data-cs-view="board" hidden aria-labelledby="{{ $id }}-suspects">
        <div class="case-board">
            <h3 class="case-board-title" id="{{ $id }}-suspects">{{ __('Suspects') }}</h3>
            <ol class="case-cards" data-cs="suspects"></ol>
            @unless ($mode === 'host')
                <button type="button" class="case-accuse" data-cs="accuse" disabled>
                    <span class="material-icon" aria-hidden="true">gavel</span><span data-cs="accuse-label">{{ __('Pick a suspect') }}</span>
                </button>
            @endunless
        </div>
    </section>

    @if ($mode === 'solo')
        {{-- Before a game: the case file's cover, how to play, and the name to play as. --}}
        <section class="case-view case-view-cover" data-cs-view="cover" aria-labelledby="case-rules-heading">
            <div class="case-folder">
                <span class="case-folder-tab">{{ __('Confidential') }}</span>
                {{-- The title on a label stuck to the folder. --}}
                <span class="case-folder-titlebox">
                    <img class="case-folder-title" src="{{ versioned_asset('modules/game/title-case-file.svg') }}" alt="" width="5755" height="1610" />
                </span>
                <h2 class="case-folder-heading" id="case-rules-heading">{{ __('How to play') }}</h2>
                <ol class="case-folder-rules">
                    <li>{{ __('Each case is a mystery police district. Its rap sheet is open on the desk: its crimes and the one there was most of.') }}</li>
                    <li>{{ __('Tap the evidence on the desk for more clues. Each one you open takes some off the case: from :points down to :least.', ['points' => number_format($points), 'least' => number_format($least)]) }}</li>
                    <li>{{ __('Tap the corkboard to see the six suspects, pick one and accuse them. :seconds seconds a case, :cases cases.', ['seconds' => $seconds, 'cases' => $cases]) }}</li>
                </ol>
                <form class="case-folder-form" data-case-start novalidate>
                    @csrf
                    <label class="case-folder-label" for="case-name">{{ __('Detective') }}</label>
                    <input type="text" id="case-name" name="name" class="case-folder-name" maxlength="{{ $nameLength }}" autocomplete="name"
                        required aria-describedby="case-name-note" placeholder="{{ __('Your name') }}" />
                    <p class="case-folder-error" role="alert" data-case-error hidden></p>
                    <button type="submit" class="case-stamp-button">{{ __('Open the file') }}</button>
                    <p class="case-folder-note" id="case-name-note">{{ __("Your name and score go to :app's administrators, who choose the winner. Only they see the scores.", ['app' => config('app.name')]) }}</p>
                </form>
            </div>
        </section>

        {{-- After a game: the report of the cases, and the score. --}}
        <section class="case-view case-view-report" data-cs-view="report" hidden aria-labelledby="case-over-heading">
            <div class="case-paper case-report">
                <h2 class="case-paper-title" id="case-over-heading" tabindex="-1">{{ __('Case report') }}</h2>
                <ol class="case-summary" data-case-slot="summary"></ol>
                <p class="case-report-total">{{ __('Your score') }} <strong data-case-slot="final">0</strong> <span data-case-slot="out-of"></span></p>
                <div class="case-report-actions">
                    <button type="button" class="case-stamp-button" data-case-action="again">{{ __('Play again') }}</button>
                    <button type="button" class="case-choice" data-case-action="rename">{{ __('Change name') }}</button>
                </div>
            </div>
        </section>
    @endif

    {{-- Along the foot: what's said, typed out, and the choices. --}}
    <div class="case-dialogue" data-cs="dialogue" hidden>
        <p class="case-dialogue-text" data-cs="say" aria-hidden="true"></p>
        <p class="visually-hidden" data-cs="say-aloud" aria-live="polite"></p>
        <div class="case-dialogue-choices" data-cs="choices"></div>
    </div>
</div>
