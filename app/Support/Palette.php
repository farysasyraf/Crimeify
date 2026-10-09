<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// The command palette, Ctrl+K (⌘K on a Mac) on every page of the app: a search for a page or a record to go to. The
// app gives its pages; each module adds what it has to find, from its service provider, as the Setup module adds its
// users and the Map module its police districts and stations. Every source gives only what the user can open, by the
// same rules as the pages themselves: a link to a page limited to roles only for a user with one of them.
final class Palette
{
    /**
     * At most this many results from each source.
     */
    public const Limit = 6;

    /**
     * Each source's search by its heading, with its place among the others.
     *
     * @var array<string, array{order: int, search: Closure(string, User): iterable<array{label: string, about: string, url: string, icon: string}>}>
     */
    private array $sources = [];

    /**
     * Add a source of results under a heading. Its search is given what was typed, trimmed, which may be nothing, and
     * the user, and gives each result's label, what it is, its address and a Material icon's name, and optionally
     * "also": other text it's found by, like a user's username and email, after the results whose label matches.
     * Lower orders come first.
     *
     * @param  Closure(string, User): iterable<array{label: string, about: string, url: string, icon: string, also?: string}>  $search
     */
    public function add(string $heading, int $order, Closure $search): void
    {
        $this->sources[$heading] = ['order' => $order, 'search' => $search];
    }

    /**
     * Every source's results for what was typed, under their headings, best match first; none for a source with none.
     * With nothing typed, each source's results as it gives them, all of them, like the pages in the menu's order.
     *
     * @return list<array{heading: string, results: list<array{label: string, about: string, url: string, icon: string}>}>
     */
    public function search(string $term, User $user): array
    {
        $term = trim($term);
        $groups = [];

        foreach (collect($this->sources)->sortBy('order') as $heading => $source) {
            $results = collect(($source['search'])($term, $user))
                ->map(fn (array $result) => [...Arr::except($result, 'also'), 'rank' => self::rankResult($result, $term)])
                ->filter(fn (array $result) => $result['rank'] !== null)
                ->when($term !== '', fn ($results) => $results->sortBy([['rank', 'asc'], ['label', 'asc']])->take(self::Limit))
                ->map(fn (array $result) => Arr::except($result, 'rank'))
                ->values()
                ->all();

            if ($results !== []) {
                $groups[] = ['heading' => $heading, 'results' => $results];
            }
        }

        return $groups;
    }

    /**
     * Whether the user can open the route with this name: it exists, and if it's for administrators or limited to
     * roles on the Routes page, the user has one of them.
     */
    public static function canOpen(User $user, string $name): bool
    {
        $route = Route::getRoutes()->getByName($name);

        return $route !== null && self::allows($user, $route);
    }

    /**
     * Whether the user can open this address: one of the app's pages they can open, or another site's. Not one the
     * app has no page at, nor one that's only for a form to send to.
     */
    public static function canOpenUrl(User $user, string $url): bool
    {
        if (parse_url($url, PHP_URL_HOST) !== parse_url(url('/'), PHP_URL_HOST)) {
            return true;
        }

        try {
            return self::allows($user, Route::getRoutes()->match(Request::create($url)));
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    private static function allows(User $user, RoutingRoute $route): bool
    {
        if (in_array('admin', $route->gatherMiddleware(), true) && ! $user->isAdmin()) {
            return false;
        }

        $roles = $route->getAction(SavedRoutes::ROLES_ACTION);

        return $roles === null || ($roles !== [] && (bool) SavedRoutes::roles()?->userHasAny($user, array_keys($roles)));
    }

    /**
     * How well a label matches what was typed: 0 if it starts with it, 1 if one of its words does, 2 if it's
     * anywhere in it, and null if it isn't, ignoring letter case and accents. With nothing typed, everything matches.
     */
    public static function rank(string $label, string $term): ?int
    {
        if ($term === '') {
            return 0;
        }

        $label = Str::lower(Str::ascii($label));
        $term = Str::lower(Str::ascii($term));

        return match (true) {
            str_starts_with($label, $term) => 0,
            (bool) preg_match('/[^a-z0-9]'.preg_quote($term, '/').'/', $label) => 1,
            str_contains($label, $term) => 2,
            default => null,
        };
    }

    /**
     * How well a result matches: by its label, or after every label match, by the other text it's found by.
     *
     * @param  array{label: string, also?: string}  $result
     */
    private static function rankResult(array $result, string $term): ?int
    {
        $also = isset($result['also']) ? self::rank($result['also'], $term) : null;

        return self::rank($result['label'], $term) ?? ($also === null ? null : $also + 3);
    }

    /**
     * A LIKE pattern for a column containing what was typed, with %, _ and [ as themselves. Use it with
     * ESCAPE '\', as User's search does.
     */
    public static function like(string $term): string
    {
        return '%'.addcslashes($term, '\\%_[').'%';
    }
}
