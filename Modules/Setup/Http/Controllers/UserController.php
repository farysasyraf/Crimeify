<?php

namespace Modules\Setup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * The parts of a user's details. My profile and Edit user save one at a time, from its own box, with a hidden
     * section field; Add user sends them all.
     */
    private const Sections = ['info', 'password', 'roles'];

    /**
     * What's wrong with a username, when saving it and as it's typed.
     */
    private const UsernameMessages = [
        'username.regex' => 'Use letters, numbers, dots, dashes and underscores only, starting with a letter or number.',
        'username.unique' => "The username ':input' is already taken.",
    ];

    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->value();
        $users = collect();
        $totalCount = 0;
        $databaseError = null;
        $log = null;

        try {
            $users = User::with(['roles' => fn ($query) => $query->orderBy('Name')])
                ->search($search)
                ->orderByDesc('Id')
                ->get();
            $totalCount = User::count();

            // The users log, for ADMIN only, whoever the Routes page lets see the list: who's online now, then who
            // was online most recently, and those never online last.
            if ($request->user()?->isAdmin()) {
                [$online, $offline] = User::query()
                    ->orderByRaw('CASE WHEN LastSeenAt IS NULL THEN 1 ELSE 0 END')
                    ->orderByDesc('LastSeenAt')
                    ->orderBy('Name')
                    ->get(['Id', 'Name', 'Email', 'LastSeenAt', 'LoggedOutAt'])
                    ->partition(fn (User $user) => $user->isOnline());
                $log = $online->concat($offline);
            }
        } catch (QueryException $e) {
            Log::error('Could not load users from the database', ['exception' => $e]);
            $databaseError = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        }

        return view('setup::users.index', compact('search', 'users', 'totalCount', 'databaseError', 'log'));
    }

    public function create(): View
    {
        return view('setup::users.create', ['user' => new User, 'roles' => Role::orderBy('Name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$input, $roleIds] = $this->validated($request);

        try {
            $user = DB::transaction(function () use ($input, $roleIds) {
                $user = User::create($input);
                $user->roles()->sync($roleIds ?? []);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate($input);
        }

        return redirect(page_url('users'))->with('message', "Added {$user->Name}.");
    }

    /**
     * The user's photo and details, each box with an Edit button (?edit=info, password or roles).
     */
    public function edit(Request $request, User $user): View
    {
        return view('setup::users.edit', [
            'user' => $user,
            'roles' => Role::orderBy('Name')->get(),
            'editing' => $this->editing($request),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        [$input, $roleIds] = $this->validated($request, $user);

        if ($roleIds !== null && $user->isLastAdmin() && ! in_array(Role::firstWhere('Name', Role::Admin)?->Id, $roleIds, true)) {
            throw ValidationException::withMessages(['roles' => $this->lastAdmin($user, 'must keep it')]);
        }

        try {
            DB::transaction(function () use ($user, $input, $roleIds) {
                $user->update($input);

                if ($roleIds !== null) {
                    $user->roles()->sync($roleIds);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate($input, $user);
        }

        return redirect(page_url('users/edit', ['user' => $user]))->with('message', "Saved changes to {$user->Name}.");
    }

    /**
     * My profile: whoever is logged in changes their own photo, name, username, email, phone and password. Not their
     * roles, so no one can give themselves more; who can edit other users is set on the Routes page.
     */
    public function profile(Request $request): View
    {
        return view('setup::users.profile', [
            'user' => $request->user(),
            'editing' => $this->editing($request, withRoles: false),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$input] = $this->validated($request, $user, withRoles: false);

        try {
            $user->update($input);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate($input, $user);
        }

        return redirect()->route('profile')->with('message', 'Saved your changes.');
    }

    /**
     * Whether a username can be used, for the Username field as it's typed (username-check.js): whether it's one a
     * username can be, and whether someone already has it. Saving checks again; this only tells sooner. The field
     * itself knows the username it started with, so it doesn't ask about that.
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $typed = $request->input('username');
        $username = is_string($typed) ? Str::lower(trim($typed)) : '';

        $validator = Validator::make(['username' => $username], ['username' => $this->usernameRules()], self::UsernameMessages);

        return response()->json([
            'username' => $username,
            'available' => $validator->passes(),
            'message' => $validator->errors()->first('username') ?: "{$username} is available.",
        ]);
    }

    public function delete(User $user): View
    {
        return view('setup::users.delete', compact('user'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, "You can't delete the account you're logged in with.");
        abort_if($user->isLastAdmin(), 403, $this->lastAdmin($user, "can't be deleted"));

        $user->delete();

        return redirect(page_url('users'))->with('message', "Deleted {$user->Name}.");
    }

    /**
     * The box being edited, from ?edit=, if it's one the page has.
     */
    private function editing(Request $request, bool $withRoles = true): ?string
    {
        $section = $request->query('edit');
        $sections = $withRoles ? self::Sections : array_diff(self::Sections, ['roles']);

        return is_string($section) && in_array($section, $sections, true) ? $section : null;
    }

    /**
     * Validate the form and map it onto the Users columns, plus the Ids of the ticked roles, or null to leave the
     * roles as they are. Only the section sent is changed; without one, a new user gets everything at once and an
     * existing one their details and password, as their roles only change from the Roles box.
     * A blank password leaves the stored one unchanged (or unset for a new user); the Password box needs one.
     *
     * @return array{0: array{Name?: string, Username?: string, Email?: string, Phone?: ?string, Password?: string}, 1: ?list<int>}
     */
    private function validated(Request $request, ?User $user = null, bool $withRoles = true): array
    {
        $section = $request->input('section');
        $sections = match (true) {
            in_array($section, self::Sections, true) => [$section],
            $user === null => self::Sections,
            default => ['info', 'password'],
        };
        $has = fn (string $part) => in_array($part, $sections, true);

        $rules = [];

        if ($has('info')) {
            // Usernames are saved in lowercase, so they're checked that way.
            if (is_string($request->input('username'))) {
                $request->merge(['username' => Str::lower($request->input('username'))]);
            }

            $rules += [
                'name' => ['required', 'string', 'max:100'],
                'username' => $this->usernameRules($user),
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'Email')->ignore($user)],
                'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+() .-]*[0-9][0-9+() .-]*$/'],
            ];
        }

        if ($has('password')) {
            $rules['password'] = [$section === 'password' ? 'required' : 'nullable', 'string', Password::min(8), 'max:255'];
        }

        if ($has('roles') && $withRoles) {
            $rules += [
                'roles' => ['nullable', 'array'],
                'roles.*' => ['integer', 'distinct', Rule::exists(Role::class, 'Id')],
            ];
        }

        $data = $request->validate($rules, self::UsernameMessages + [
            'email.unique' => "A user with the email ':input' already exists.",
            'phone.regex' => 'Use digits, spaces and + ( ) - . only, like 012-345 6789.',
            'password.required' => 'Type the new password.',
            'roles.*.exists' => 'One of the selected roles no longer exists. Reload the page and try again.',
        ]);

        $input = [];

        if ($has('info')) {
            $input = ['Name' => $data['name'], 'Username' => $data['username'], 'Email' => $data['email'], 'Phone' => $data['phone'] ?? null];
        }

        if (filled($data['password'] ?? null)) {
            $input['Password'] = $data['password'];
        }

        // No ticked boxes means the field isn't sent at all, which clears the user's roles.
        $roleIds = $has('roles') && $withRoles ? array_map('intval', $data['roles'] ?? []) : null;

        return [$input, $roleIds];
    }

    /**
     * Why the only user with the ADMIN role must keep it: someone has to be able to open the Routes page.
     */
    private function lastAdmin(User $user, string $so): string
    {
        return "{$user->Name} is the only user with the ADMIN role, which opens the Routes page, so they {$so}. Give the role to someone else first.";
    }

    /**
     * What a username must be: 3 to 30 letters, numbers, dots, dashes and underscores (User::UsernamePattern), and no
     * one else's. Each rule stops the rest, so only the first problem shows.
     *
     * @return list<mixed>
     */
    private function usernameRules(?User $user = null): array
    {
        return ['bail', 'required', 'string', 'min:3', 'max:30', 'regex:'.User::UsernamePattern, Rule::unique(User::class, 'Username')->ignore($user)];
    }

    // The unique rules catch duplicates up front; this covers two saves racing each other, for the username or the email.
    private function duplicate(array $input, ?User $user = null): RedirectResponse
    {
        $usernameTaken = isset($input['Username']) && User::query()
            ->where('Username', $input['Username'])
            ->when($user, fn ($query) => $query->whereKeyNot($user->Id))
            ->exists();

        return back()->withInput()->withErrors($usernameTaken
            ? ['username' => "The username '{$input['Username']}' is already taken."]
            : ['email' => "A user with the email '".($input['Email'] ?? '')."' already exists."]);
    }
}
