<?php

namespace Modules\Game\Support;

use Illuminate\Support\HtmlString;

// The games on the Games page (LibraryController), in the order of its row of tiles, as a games console lists them: the
// first is chosen to start with. Each has a key (its address on the page, like #higher-or-lower), its name, its tile
// (an image, or a Material icon, also shown large beside it unless another is given as big), the look of the page
// behind it (its key as a class in library.css), its title (an image, or its name as a heading), a picture beside it if
// it has one, what it is, its buttons, the first of which its tile opens, and a few cards about it. To add a game, give it its pages and add it
// here.
class Library
{
    /**
     * @return list<array{key: string, name: string, tile: array{image?: string, icon?: string, big?: string},
     *     title: array{image: string, width: int, height: int}|null,
     *     picture?: array{image: string, width: int, height: int, credit?: HtmlString},
     *     about: string, actions: list<array{label: string, url: string, icon: string}>,
     *     cards: list<array{icon: string, title: string, text: string}>}>
     */
    public static function games(): array
    {
        $seconds = config('game.seconds');
        $guessr = config('game.districtguessr');

        return [
            [
                'key' => 'higher-or-lower',
                // The game's name, in English in Bahasa Melayu too, as its title is drawn.
                'name' => 'Higher or Lower',
                'tile' => ['image' => 'modules/game/tile-higher-or-lower.svg'],
                // The drawing and its width and height, which keep its room while it loads.
                'title' => ['image' => 'modules/game/title.svg', 'width' => 4375, 'height' => 2368],
                'about' => __('Which police district had more crime? Two cards of the same crime, one count hidden: guess whether it\'s higher or lower.'),
                'actions' => [
                    ['label' => __('Play'), 'url' => route('public.game.higher-or-lower'), 'icon' => 'play_arrow'],
                    ['label' => __('Join a live game'), 'url' => route('public.game.live'), 'icon' => 'groups'],
                ],
                'cards' => [
                    ['icon' => 'person', 'title' => __('On your own'), 'text' => __('A point for each right guess, :seconds seconds a card. One wrong guess ends the game.', ['seconds' => $seconds])],
                    ['icon' => 'groups', 'title' => __('Live, with a room'), 'text' => __('Everyone plays the same rounds on their phone, and the sooner a right answer, the more points.')],
                    ['icon' => 'local_police', 'title' => __('Real figures'), 'text' => __("Every count is a police district's, from the Royal Malaysia Police's figures.")],
                ],
            ],
            [
                'key' => 'districtguessr',
                // The game's name, the same in Bahasa Melayu.
                'name' => 'DistrictGuessr',
                'tile' => ['image' => 'modules/game/tile-districtguessr.svg'],
                'title' => ['image' => 'modules/game/title-districtguessr.svg', 'width' => 6273, 'height' => 1498],
                // Malaysia's states, from the Map module's boundaries, credited as on the map.
                'picture' => [
                    'image' => 'modules/game/map-districtguessr.svg', 'width' => 900, 'height' => 338,
                    'credit' => new HtmlString(e(__('Boundaries')).': <a href="https://www.geoboundaries.org">geoBoundaries</a> · © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'),
                ],
                'about' => __("Where's the police district? Pin it on a map of Malaysia with only its states' outlines: the closer, the more points."),
                'actions' => [
                    ['label' => __('Play'), 'url' => route('public.game.districtguessr'), 'icon' => 'play_arrow'],
                    ['label' => __('Join a live game'), 'url' => route('public.game.live'), 'icon' => 'groups'],
                ],
                'cards' => [
                    ['icon' => 'location_on', 'title' => __(':rounds districts', ['rounds' => $guessr['rounds']]), 'text' => __('Each with its state as a hint, and :seconds seconds to pin it.', ['seconds' => $guessr['seconds']])],
                    ['icon' => 'straighten', 'title' => __('The closer, the more'), 'text' => __('Up to :points points a district: all of them within :km km.', ['points' => number_format($guessr['points']), 'km' => $guessr['perfect']])],
                    ['icon' => 'map', 'title' => __('No names on the map'), 'text' => __("Only the states' outlines, so it's what you know that counts.")],
                ],
            ],
            [
                'key' => 'live',
                'name' => __('Join a live game'),
                'tile' => ['icon' => 'groups'],
                'title' => null,
                'about' => __('Playing with a room? Type the code on the big screen and your name, and play the same rounds as everyone at once.'),
                'actions' => [
                    ['label' => __('Join with a code'), 'url' => route('public.game.live'), 'icon' => 'login'],
                ],
                'cards' => [
                    ['icon' => 'tv', 'title' => __('1. The big screen'), 'text' => __('The host shows the game on a projector or in a meeting, with its code.')],
                    ['icon' => 'smartphone', 'title' => __('2. Your phone'), 'text' => __('Type the code and your name, then wait for the host to start.')],
                    ['icon' => 'bolt', 'title' => __('3. Be quick'), 'text' => __('A right answer, or a close pin, scores up to :points points: the sooner, the more.', ['points' => number_format(config('game.live.points'))])],
                ],
            ],
            [
                'key' => 'soon',
                'name' => __('More games coming soon'),
                'tile' => ['icon' => 'more_horiz', 'big' => 'hourglass_top'],
                'title' => null,
                'about' => __('More games with Malaysia\'s crime figures are on the way. Check back soon.'),
                'actions' => [],
                'cards' => [],
            ],
        ];
    }
}
