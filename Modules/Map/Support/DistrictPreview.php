<?php

namespace Modules\Map\Support;

use GdImage;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;

// A police district's link preview: what WhatsApp, Telegram, Facebook and the like show for a shared link to it
// (MapController@publicDistrict), from its Open Graph tags. Its title and description, and an image of its chart:
// its violent and property crime each year, stacked, with the latest year's total and how it compares with the year
// before. In the page's language. Drawn with PHP's GD in Inter (Resources/fonts), at the 1200 × 630 pixels these sites
// show large.
final class DistrictPreview
{
    public const Width = 1200;

    public const Height = 630;

    /**
     * Changed whenever the image is drawn differently, so shared links get the new one rather than a copy kept by
     * WhatsApp and the like.
     */
    private const Design = 1;

    /**
     * The app's colours (public/css/site.css): the page, its panels' rules, text, the softer grey, and the violent and
     * property crime colours, the coral and the soft blue, and the green and red of a fall and a rise.
     */
    private const Colours = [
        'page' => '#16171a', 'rule' => '#34373d', 'text' => '#eef2f6', 'muted' => '#b4bec8',
        'assault' => '#ff8f8a', 'property' => '#62a9dc', 'down' => '#7fd8a4', 'up' => '#ff8f8a',
    ];

    /**
     * @param  array<int, array<string, int>>  $years  each year's crimes of each category, oldest first
     */
    private function __construct(public readonly PoliceDistrict $district, public readonly array $years) {}

    /**
     * The district's figures, each category's total in each year as its popup counts them: the "all" row, or its
     * crime types added up.
     */
    public static function of(PoliceDistrict $district): self
    {
        $years = [];
        $rows = CrimeStat::query()->where(['State' => $district->State, 'District' => $district->Name])->get(['Category', 'Type', 'Year', 'Crimes']);

        foreach ($rows->groupBy('Year') as $year => $inYear) {
            foreach (array_keys(config('map.crime.categories')) as $category) {
                $types = $inYear->where('Category', $category)->pluck('Crimes', 'Type');
                $years[(int) $year][$category] = $types['all'] ?? $types->sum();
            }
        }

        ksort($years);

        return new self($district, $years);
    }

    /**
     * Changes with the figures, the language and the image's design, for the image's address.
     */
    public function version(): string
    {
        return substr(md5(json_encode([self::Design, app()->getLocale(), $this->district->Name, $this->district->Region, $this->years])), 0, 12);
    }

    /**
     * The title: the district, its region, and the years.
     */
    public function title(): string
    {
        return __(':name police district, :region: crime :from–:to', [
            'name' => $this->district->Name, 'region' => $this->district->regionName(), 'from' => array_key_first($this->years), 'to' => array_key_last($this->years),
        ]);
    }

    /**
     * The description: the latest year's crimes and how they compare with the year before.
     */
    public function description(): string
    {
        if ($this->years === []) {
            return __('Police district in :region', ['region' => $this->district->regionName()]).'.';
        }

        $change = $this->change();

        return __(':count violent and property crimes in :year', ['count' => number_format($this->total(array_key_last($this->years))), 'year' => array_key_last($this->years)])
            .($change === null ? '' : ', '.$change['text']).'. '
            .__("Each year from :from to :to on Crimeify's map, from the Royal Malaysia Police's figures.", ['from' => array_key_first($this->years), 'to' => array_key_last($this->years)]);
    }

    /**
     * What the image shows, for those who can't see it.
     */
    public function alt(): string
    {
        return __("Bar chart of :name's violent and property crime each year from :from to :to.", [
            'name' => $this->district->Name, 'from' => array_key_first($this->years), 'to' => array_key_last($this->years),
        ]);
    }

    /**
     * The image, as a PNG file's bytes.
     */
    public function png(): string
    {
        $image = imagecreatetruecolor(self::Width, self::Height);
        imagealphablending($image, true);
        $colour = fn (string $name) => imagecolorallocate($image, ...sscanf(self::Colours[$name], '#%02x%02x%02x'));

        imagefilledrectangle($image, 0, 0, self::Width - 1, self::Height - 1, $colour('page'));

        // The logo and name at the top, and where the map is at the right.
        $logo = imagecreatefrompng(public_path('images/logo.png'));
        imagecopyresampled($image, $logo, 64, 48, 0, 0, 44, 44, imagesx($logo), imagesy($logo));
        $this->text($image, config('app.name'), 28, 122, 81, $colour('text'), bold: true);
        $this->text($image, (string) parse_url(config('app.url'), PHP_URL_HOST), 22, self::Width - 64, 81, $colour('muted'), align: 'right');

        // The district, as large as fits, and its region.
        $size = 64;
        while ($size > 36 && $this->width($this->district->Name, $size, bold: true) > self::Width - 128) {
            $size -= 4;
        }
        $this->text($image, $this->district->Name, $size, 64, 188, $colour('text'), bold: true);
        $this->text($image, __('Police district in :region', ['region' => $this->district->regionName()]).' · '.__('Crimes reported each year'), 26, 64, 234, $colour('muted'));

        if ($this->years === []) {
            $this->text($image, __('No crime figures yet.'), 30, 64, 360, $colour('muted'));
        } else {
            $this->latest($image, $colour);
            $this->chart($image, $colour);
        }

        $this->text($image, __('Source: Royal Malaysia Police and DOSM, through data.gov.my (CC BY 4.0)'), 18, 64, 602, $colour('muted'));

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * Down the left: the latest year's crimes, how they compare with the year before, and the colours' key.
     *
     * @param  callable(string): int  $colour
     */
    private function latest(GdImage $image, callable $colour): void
    {
        $year = array_key_last($this->years);
        $this->text($image, number_format($this->total($year)), 76, 64, 356, $colour('text'), bold: true);
        $this->text($image, __('crimes in :year', ['year' => $year]), 24, 64, 394, $colour('muted'));

        $change = $this->change();
        if ($change !== null) {
            $arrow = ['down' => '↓ ', 'up' => '↑ ', 'same' => ''][$change['way']];
            $this->text($image, $arrow.ucfirst($change['text']), 22, 64, 438, $colour($change['way'] === 'same' ? 'muted' : $change['way']), bold: true);
        }

        foreach (array_keys(config('map.crime.categories')) as $at => $category) {
            $y = 500 + $at * 36;
            imagefilledrectangle($image, 64, $y - 16, 82, $y + 2, $colour($category));
            $this->text($image, __(config("map.crime.categories.{$category}")), 20, 94, $y, $colour('muted'));
        }
    }

    /**
     * Down the right: a bar for each year, violent crime stacked on property crime, with its total over it and the
     * year under it.
     *
     * @param  callable(string): int  $colour
     */
    private function chart(GdImage $image, callable $colour): void
    {
        [$left, $right, $top, $base] = [440, self::Width - 64, 284, 526];
        $highest = max(array_map(fn (int $year) => $this->total($year), array_keys($this->years))) ?: 1;
        $slot = ($right - $left) / count($this->years);
        $width = (int) min(56, $slot * 0.62);

        imageline($image, $left, $base, $right, $base, $colour('rule'));

        foreach (array_keys($this->years) as $at => $year) {
            $middle = (int) round($left + $slot * ($at + 0.5));
            $x = $middle - intdiv($width, 2);
            $y = $base;

            foreach (['property', 'assault'] as $category) {
                $height = (int) round(($this->years[$year][$category] ?? 0) / $highest * ($base - $top - 30));
                if ($height > 0) {
                    imagefilledrectangle($image, $x, $y - $height, $x + $width - 1, $y - 1, $colour($category));
                    $y -= $height;
                }
            }

            $latest = $year === array_key_last($this->years);
            $this->text($image, number_format($this->total($year)), 17, $middle, $y - 8, $colour($latest ? 'text' : 'muted'), bold: $latest, align: 'center');
            $this->text($image, (string) $year, 18, $middle, $base + 30, $colour($latest ? 'text' : 'muted'), bold: $latest, align: 'center');
        }
    }

    /**
     * A year's violent and property crime together.
     */
    private function total(int $year): int
    {
        return array_sum($this->years[$year] ?? []);
    }

    /**
     * How the latest year compares with the year before, if there are figures for it: which way, and in words.
     *
     * @return array{way: string, text: string}|null
     */
    private function change(): ?array
    {
        $year = array_key_last($this->years);
        $before = $this->years[$year - 1] ?? null;

        if ($year === null || $before === null) {
            return null;
        }

        [$now, $then] = [$this->total($year), array_sum($before)];

        if ($now === $then || $then === 0) {
            return $now === $then ? ['way' => 'same', 'text' => __('the same as in :year', ['year' => $year - 1])] : null;
        }

        $percent = (int) round(abs($now - $then) / $then * 100);
        $size = $percent === 0 ? __('under 1%') : "{$percent}%";

        return $now < $then
            ? ['way' => 'down', 'text' => __('down :size from :year', ['size' => $size, 'year' => $year - 1])]
            : ['way' => 'up', 'text' => __('up :size from :year', ['size' => $size, 'year' => $year - 1])];
    }

    /**
     * Write text in Inter at a size in pixels, its baseline at y, starting, ending or centred at x.
     */
    private function text(GdImage $image, string $text, int $px, int $x, int $y, int $colour, bool $bold = false, string $align = 'left'): void
    {
        $width = $this->width($text, $px, $bold);
        $x = match ($align) {
            'right' => $x - $width,
            'center' => $x - intdiv($width, 2),
            default => $x,
        };

        imagettftext($image, $px * 0.75, 0, $x, $y, $colour, $this->font($bold), $text);
    }

    private function width(string $text, int $px, bool $bold = false): int
    {
        $box = imagettfbbox($px * 0.75, 0, $this->font($bold), $text);

        return $box[2] - $box[0];
    }

    private function font(bool $bold): string
    {
        return module_path('Map', 'Resources/fonts/Inter-'.($bold ? 'Bold' : 'Regular').'.ttf');
    }
}
