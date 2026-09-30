<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

// Mirrors dbo.Users in MyAppDB. CreatedAt is filled in by the column's database default.
#[Table(name: 'Users', key: 'Id', timestamps: false)]
#[Fillable(['Name', 'Username', 'Email', 'Phone', 'Password'])]
#[Hidden(['Password'])]
class User extends Authenticatable
{
    use Notifiable;

    /**
     * How recently a user must have opened a page to count as online in the users log.
     */
    public const OnlineMinutes = 5;

    /**
     * Emails go to the user's address, with their name.
     *
     * @return array<string, string>
     */
    public function routeNotificationForMail(): array
    {
        return [$this->Email => $this->Name];
    }

    /**
     * The address "Forgot password?" sends a link to, and the account the link is for: the user's email.
     */
    public function getEmailForPasswordReset(): string
    {
        return $this->Email;
    }

    /**
     * The email with the link to choose a new password, in the app's words.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    /**
     * What a username can be, once in lowercase: 3 to 30 letters, numbers, dots, dashes and underscores, starting
     * with a letter or number. Never an @, so the login form can tell a username from an email.
     */
    public const UsernamePattern = '/^[a-z0-9][a-z0-9._-]{2,29}$/';

    /**
     * A user added without a username, like by LoginUserSeeder, gets one from their email.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (blank($user->Username)) {
                $user->Username = static::freeUsername((string) $user->Email);
            }
        });
    }

    /**
     * A username no one has yet, from an email: the part before the @, as far as a username allows, with a number
     * after it if it's taken (ada@example.com → ada, then ada2). As the migration adding usernames made them.
     */
    public static function freeUsername(string $email): string
    {
        $base = Str::of(Str::before($email, '@'))->ascii()->lower()->replaceMatches('/[^a-z0-9._-]/', '')->ltrim('._-')->substr(0, 30)->value();
        $base = strlen($base) >= 3 ? $base : 'user'.$base;
        $username = $base;

        for ($n = 2; static::query()->where('Username', $username)->exists(); $n++) {
            $username = substr($base, 0, 30 - strlen((string) $n)).$n;
        }

        return $username;
    }

    /**
     * Usernames are kept in lowercase, so Ada and ada are the same username, to log in with and to be taken.
     *
     * @return Attribute<string, string>
     */
    protected function username(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : Str::lower(trim($value)));
    }

    /**
     * The column holding the hashed password used to log in.
     *
     * @var string
     */
    protected $authPasswordName = 'Password';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Password' => 'hashed',
            'CreatedAt' => 'datetime',
            'LastSeenAt' => 'datetime',
            'LoggedOutAt' => 'datetime',
        ];
    }

    /**
     * Online: opened a page in the last few minutes (LastSeenAt, from RecordLastSeen) and hasn't logged out since.
     */
    public function isOnline(): bool
    {
        return $this->LastSeenAt !== null
            && $this->LastSeenAt->gt(now()->subMinutes(self::OnlineMinutes))
            && ($this->LoggedOutAt === null || $this->LoggedOutAt->lt($this->LastSeenAt));
    }

    /**
     * The roles given to this user, linked through dbo.UserRoles.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'UserRoles', 'UserId', 'RoleId');
    }

    /**
     * The user's profile photo, in dbo.UserPhotos.
     *
     * @return HasOne<UserPhoto, $this>
     */
    public function photo(): HasOne
    {
        return $this->hasOne(UserPhoto::class, 'UserId', 'Id');
    }

    /**
     * When the photo was last changed, as a number for its address (…?v=), so browsers keep a photo until it's
     * replaced. Null without a photo. Reads only the date, not the photo.
     */
    public function photoVersion(): ?string
    {
        return UserPhoto::query()->whereKey($this->Id)->first(['UserId', 'UpdatedAt'])?->UpdatedAt->format('Uv');
    }

    /**
     * Up to two letters for the user's avatar without a photo, like SA for Siti Aminah.
     */
    public function initials(): string
    {
        return mb_substr(Str::initials($this->Name, capitalize: true), 0, 2);
    }

    /**
     * Whether the user has the role with this name, like ADMIN.
     */
    public function hasRole(string $name): bool
    {
        return $this->roles()->where('Name', $name)->exists();
    }

    /**
     * Whether the user has the ADMIN role, which opens the Routes page.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    /**
     * Whether the user is the only one with the ADMIN role, who must keep it so someone can open the Routes page.
     */
    public function isLastAdmin(): bool
    {
        return $this->isAdmin() && Role::adminCount() === 1;
    }

    /**
     * Users without a password can't log in.
     */
    public function canLogIn(): bool
    {
        return filled($this->Password);
    }

    /**
     * Match users whose name, username or email contains the term.
     * %, _ and [ typed by the user are treated as literal characters in LIKE.
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $pattern = '%'.addcslashes(trim($term), '\\%_[').'%';

        $query->where(fn (Builder $query) => $query
            ->whereRaw("Name LIKE ? ESCAPE '\\'", [$pattern])
            ->orWhereRaw("Username LIKE ? ESCAPE '\\'", [Str::lower($pattern)])
            ->orWhereRaw("Email LIKE ? ESCAPE '\\'", [$pattern]));
    }
}
