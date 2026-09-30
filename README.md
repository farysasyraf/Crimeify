# Crimeify

A Laravel + Blade app for managing `dbo.Users` in the `MyAppDB` SQL Server database: list, search, add, edit and delete users. The menu on the left comes from `dbo.MenuItems`, and you can edit it under **Manage menu**. Links nest up to three levels: a level 2 link sits under a level 1 link (`ParentId`), and a level 3 link under a level 2 link. A link's address is picked from a list: the app's own pages and the routes saved on **Manage routes** that open without a parameter. Choose **Other address…** to type anything else, like an outside site. A link set to **None** is a heading that only groups the links under it. Deleting a link also deletes the links under it. Each link is shown either to everyone who is logged in or only to users with chosen roles (`dbo.MenuItemRoles`). A link limited to roles whose roles have all been deleted is hidden from everyone until you pick new ones. Hiding a link doesn't block its page: to stop others opening the address, limit its route to the same roles on **Manage routes**.

The sidebar is laid out like AMV's: a floating dark panel with the logo, then the signed-in user, which opens to **My profile** and **Log out**, then the **Modules** from `dbo.MenuItems`.
- **Icons:** every link shows a Material icon, set on the link's Manage menu form; links under a group get a slightly smaller one. A link without an icon gets `content_paste`.
- **Groups:** a group opens and closes with the arrow next to it, or by clicking a heading. As in AMV, each page opens only the group holding it.
- **Highlighting:** the current page is a bright blue pill, and the group holding it gets a faint bar and a blue icon.
- **Narrow menu:** on wide screens, the ≡ button in the top bar narrows the menu to its icons. Pointing at the narrow menu opens it over the page, and each browser remembers the choice.
- **Smaller screens:** below 1200px wide, the menu slides in from the **Menu** button instead.

The icons come from the Material Icons Round font in `public/fonts`, so they work without an internet connection. Pages link their CSS, JavaScript and images with `versioned_asset('js/sidebar.js')`, which adds the file's last-changed time (`?v=…`). A changed file therefore gets a new address, and browsers load it straight away instead of keeping an old copy for hours. Use it for any file you add to `public/`. The logo at the top of the sidebar, on the login page and in the browser tab is `public/images/logo.png`; replace that file with your own square logo to change it. The app's name comes from `APP_NAME` in `.env`. If you cache the config (`php artisan config:cache` or `php artisan optimize`), run it again after changing the name.

The whole app has one dark look, whatever the computer's light or dark setting, in the style of the sidebar and the Map page's crime popups: charcoal panels with rounded corners on a near-black page, off-white text, soft grey and soft blue for what's secondary, and bright blue for what can be clicked. Main buttons are bright blue and delete buttons coral, both with dark text. The colours are toned down from full saturation so they don't glow, every text colour keeps at least 4.5:1 contrast with its background, and form fields have clearly visible edges. The colours are CSS variables at the top of `public/css/site.css` (`--bg`, `--surface`, `--primary` and so on), so the look can be adjusted there in one place.

Under the users list, the **Users log** shows who is online and when each user was last online. Only the ADMIN role sees it, set in the code, whoever the Routes page lets open the list.
- **Online** means having opened a page in the last 5 minutes (`User::OnlineMinutes`) and not logged out since. Someone who leaves the app open without using it shows offline after 5 minutes.
- **How it's kept:** `App\Http\Middleware\RecordLastSeen` writes when a logged-in user opens a page to `dbo.Users.LastSeenAt`, at most once a minute, and logging out writes `LoggedOutAt`, so they show offline straight away. Users show **Never** until they next open a page.
- **Times:** "Last online" says how long ago, then the date and time. Every time in the app is Malaysia time (`config('app.timezone')`, `Asia/Kuala_Lumpur`, or set with `APP_TIMEZONE`), as MyAppDB's server keeps it: the times the database fills in itself, like a user's CreatedAt, and the ones the app writes, like when a user was last online or a crime figure was edited, are stored and shown the same way. The app wrote its times in UTC before; the migration `2026_09_30_000005_move_app_times_to_malaysia_time` moved those 8 hours on.

Roles are managed on the **Roles** page (`dbo.Roles`). You give roles to users with the checkboxes on the Add user form or in the Roles box on Edit user; each user can have any number of roles, and `dbo.UserRoles` records which user has which role.

**My profile** (`/profile`) and **Edit user** (`/users/edit/5`) show a user the same way: their round photo with **Upload new photo**, then a box each for **Personal info** (full name, username, email, phone), **Password** and **Roles**, each with an **Edit** button that opens that box as a form and saves only what's in it.
- **Who can change what:** everyone changes their own photo, details and password on My profile. Roles can only be changed on Edit user, which starts ADMIN-only on the Routes page; My profile shows your roles but ignores any sent to it.
- **Photos** are stored as bytes (`varbinary(max)`) in `dbo.UserPhotos`, one per user, deleted with the user. A table of their own keeps `dbo.Users`, read on every page, small. The upload must be a JPG or PNG under 10 MB. It's stored as its middle square, at most 800×800 px, turned the way up a phone took it. It's drawn again with PHP's GD, which drops what else the file carried, like where it was taken. Without a photo, the user's initials show instead, as in the menu.
- **Choosing a photo uploads it,** after "Please confirm" shows it round, as it will look. Without JavaScript, an Upload button does.
- **Addresses:** your own photo is `/profile/photo` (in the code). Another user's is `users/photo/{user}`, uploaded with `users/store-photo/{user}` and removed with `users/destroy-photo/{user}`, all routes on the Routes page with the same roles as `users/edit`.

Notifications show as toasts at the top right, like AMV's `toast(status, message)`: SweetAlert2's toast, with the status's icon and a coloured edge, gone after a few seconds (a bar counts down), kept while the pointer is on it, with × to close it.
- **Success** (green) says what a save did, like "Added Test to the menu."; **info** (blue) says nothing was done, like "Nothing to save" or "Cancelled. Nothing was changed." after answering No; **warning** (amber); and **error** (red) shows the first problem with a form, while each problem also shows by its field.
- **From a controller:** `->with('message', 'Added …')` is a success toast. Add `'status' => 'info'`, `'warning'` or `'error'` for another kind. A form's validation errors make an error toast by themselves.
- **From a script:** `toast('success', 'Saved.')`, from `public/js/toast.js`, which every logged-in page and the login page load.
- **Without JavaScript,** the same notifications show as boxes at the top of the page (`resources/views/layouts/_flash.blade.php`).

Every form that adds, updates or deletes something asks "Please confirm" first, like AMV's "Kepastian!" box (`Swal.fire`):
- The icon shows what's about to happen: a question mark to save, a warning to update, and a red cross to delete.
- The box has a red **No** and a green **Yes**, in the app's toned-down coral and mint.

A form opts in with `data-confirm="the question"` and `data-confirm-kind="save"`, `"update"` or `"delete"`; `public/js/confirm.js` does the rest. The box is SweetAlert2 (MIT licence), served from `public/js/vendor`, shown as one of the app's charcoal panels. Without JavaScript, forms save straight away.

Every list (`<select>`) is a [Select2](https://select2.org) box with a search, as in AMV. It's the same version, 4.1.0-rc.0, with jQuery 3.7.1, both MIT licence and served from `public/vendor`.
- **One script for every page:** `public/js/select2-init.js` turns every list into a Select2 box, so a new page's lists get it too. Opening a list puts the cursor in its search box, as AMV does. Clicking a list's label opens it, and screen readers hear the label with the choice.
- **Placeholders:** give a list `data-placeholder="…"`, like AMV's "Sila Pilih", and its empty first option is shown in grey while nothing is chosen. A list that isn't `required` also gets × to clear it. Leave the attribute off when the empty option is a real choice, like **None** in Manage menu's Link list.
- **Leaving one alone:** a list with `data-native` stays as the browser draws it.
- **Tables that refresh in place:** on the Crime data page (Figures, and Update Logs by User) and the Police stations page, choosing in a filter list, ticking a box, searching, changing Rows per page, or clicking a page number, Previous, Next or Clear refreshes only that table, as DataTables does, not the whole page. `public/js/live-table.js` fetches the page for the new choices and swaps in the parts marked `data-live-region`, each with the address parameters it shows in `data-live-params`; only the parts whose parameters changed are swapped, so paging the update log leaves the figures, and any counts being typed, as they are. The address changes as it would, so Back, Forward, reloading and sharing it work as before. Before swapping figures with unsaved counts, the page asks, as leaving it does. Lists in the new part become Select2 boxes again (`window.enhanceSelects`). If the fetch fails, or without JavaScript, the page loads as usual.
- **Page scripts:** Select2 reports a choice only through jQuery, so the script also sends the browser's usual `change` event. A page's own script can listen with `addEventListener('change', …)` as usual.
- **Look and fallback:** the boxes and their lists are styled in `site.css` to match the app. Without JavaScript, the lists work as ordinary lists.

The app's pages are managed on the **Manage routes** page (`/routes`, `dbo.AppRoutes`). It is ported from the AMV route page (`Modules/Setup` `RouteController` and `routeTreeview`), with the form on the left and the menu tree with each link's routes on the right. Each route has:
- a menu: any link with nothing under it. Links at the top of the menu, like Dashboard, come first; then, as in AMV, one group per level 1 link with its level 2 and level 3 links
- a route name, like `reports/monthly`, which is both its address and its Laravel name, so code can link to it with `route('reports/monthly')`
- one method: GET opens a page, POST adds from a form, PUT saves changes to a record, DELETE deletes one, and ANY answers every method
- the public function on a controller in `app/Http/Controllers` or a module's `Http/Controllers` that handles it
- optional parameter names, like `id/kw`, which are added to the end of the address as `{id}/{kw}`
- who can open it: **Everyone who is logged in**, or **Only users with these roles** (`dbo.AppRouteRoles`). The server checks this, so anyone else who types the address gets a "Not allowed" page. A route limited to roles that have all been deleted opens for no one, and the list marks each limited route with its roles.

The app's own pages are routes here too, so they're managed like any other: the users list (`users`), **Add user** (`users/create`), **Roles** (`roles`, `roles/create`), **Manage menu** (`menu-items`, `menu-items/create`), the dashboard (`dashboard`), **Crime data** (`crime-data`, `crime-data/download`), and **Police stations** (`police-stations`, `police-stations/create`). Migrations moved them here from the code and put each under its menu link.
- **Every action is a route too,** so who can add, edit or delete is set here, one route at a time. As in AMV, the address is the action, then the record:

  | Route | Method | Does |
  |---|---|---|
  | `users/store` | POST | adds a user from the Add user form |
  | `users/edit/{user}` | GET | opens the Edit user page, like `/users/edit/5` |
  | `users/update/{user}` | PUT | saves the Edit user form |
  | `users/delete/{user}` | GET | opens the "Delete user?" page used without JavaScript |
  | `users/destroy/{user}` | DELETE | deletes the user |

  Roles (`roles/…/{role}`) and Manage menu (`menu-items/…/{menu_item}`) have the same five. Crime data has `crime-data/update` (PUT, saves the counts), `crime-data/store` (POST, adds a figure), `crime-data/delete/{crimeStat}` and `crime-data/destroy/{crimeStat}`, and for Excel `crime-data/upload` (POST), `crime-data/apply/{upload}` (POST) and `crime-data/cancel/{upload}` (DELETE). Each goes under its page's menu link.
- **Adding, editing and deleting start ADMIN-only:** so do the Add pages and all of Crime data; the lists and the dashboard start open to everyone who is logged in. Each route is separate: opening **Users** to everyone doesn't let anyone edit users, and letting a role edit users means opening both `users/edit` and `users/update` to it. Anyone else gets "Not allowed", even for a record that doesn't exist, so the address doesn't reveal which records do.
- **My profile** (`/profile`, in the code) is where everyone who is logged in changes their own photo, name, username, email, phone and password, whatever the Users routes allow. No one can change their roles there, so no one can give themselves a role.
- **Only ADMIN can use this page,** set in the code (the `admin` middleware in `Modules/Setup/Routes/web.php`), not on the page itself: it decides who can open every other page, so anyone let in here could let themselves into everything. Anyone else gets "Not allowed", even typing the address. So there's always someone who can open it: the ADMIN role can't be deleted or renamed, and the last user with it can't lose it or be deleted until someone else has it. If the dashboard and users list are both gone, logging in leads ADMIN here and everyone else to My profile.
- **What stays in the code** is what this page can't hold, or shouldn't: login and logout, `/`, the public pages, My profile, and this page itself with its own add, edit and delete, so it can't be deleted or broken from itself. Those are the **Pages in this app** in Manage menu's Link list.
- **Links in the code** to these pages use `page_url('users/edit', ['user' => $user])` rather than `route(…)`. If a route is renamed or deleted here, the link gives its plain address, which shows "Not found", instead of an error. If the dashboard is gone, logging in leads to the users list, and failing both, as above.

Saving opens the route for editing, with **Delete** and **Update** buttons. Save, Update and Delete each ask "Are you sure…?" first; without JavaScript, Delete opens a confirmation page instead. The search box matches menu labels as well as routes, and marks what it matched.

`routes/web.php` adds the saved routes on every request, after the built-in pages and behind the same login. A parameter named like the function's argument, such as `user` for `User $user`, loads that record, or shows Not found. The page refuses an address and method that something else already answers, and a route name a built-in page already has. A saved route never replaces a built-in page or takes its name, even one written straight into the table. When two routes share a route name (a GET and a POST, say), the first one saved gets the name. Deleting a menu link keeps its routes; they show under **No menu** until you pick a new one. A route whose controller or function has since been removed from the code is marked **Code not found**. If you cache routes (`php artisan route:cache` or `php artisan optimize`), changes on this page only take effect after `php artisan route:clear`.

## Requirements

- PHP 8.3 or newer with the `pdo_sqlsrv` extension enabled. In Laragon: **Menu → PHP → Extensions → pdo_sqlsrv**.
- Composer
- Microsoft ODBC Driver 17 or 18 for SQL Server

## Run it

```sh
composer install              # only on a fresh copy without vendor/
cp .env.example .env          # then set DB_USERNAME and DB_PASSWORD
php artisan key:generate      # only when .env is new
php artisan migrate           # creates any tables that are missing
php artisan serve
```

Open http://localhost:8000.

`php artisan migrate` creates `dbo.MenuItems` with the default links, and it creates `dbo.Users` only if that table doesn't exist yet. Laravel records which migrations have run in `dbo.migrations`. Rolling back never drops `dbo.Users`.

## Logging in

Every page requires login. Passwords are stored hashed in the `Password` column of `dbo.Users`. Users without a password can't log in.

- **Email or username:** the login page has one box for either. With an `@` in it, it's an email; a username never has one.
- **Usernames** are in the `Username` column of `dbo.Users`: 3 to 30 letters, numbers, dots, dashes and underscores, starting with a letter or number, and no two the same. They're saved in lowercase, so `Ada` and `ada` are the same username, to log in with and to be taken.
  - When the column was added, each user got a username from their email: the part before the `@`, as far as a username allows, with a number after it if it was taken (`ada@example.com` → `ada`, then `ada2`), and `user` in front if it was under 3 characters. A user added without one, like by the seeder, gets one the same way.
  - Each user can change theirs on **My profile**, and whoever can edit users on **Edit user**. The Add user form asks for one.
  - As a username is typed there, the field says under it whether it's free or taken, or what's wrong with it. `public/js/username-check.js` asks `POST /username-check` (`UserController@checkUsername`, for anyone logged in, 60 checks a minute) a moment after typing stops. Saving checks again, so without JavaScript saving still says if it's taken.
- To create the starter accounts `admin@myapp.local` and `demo@myapp.local`, run `php artisan db:seed`. Each gets a random 16-character password of its own, shown once when the command runs, and the usernames `admin` and `demo`. Write the passwords down, or change them on My profile after logging in. Accounts that already exist are left unchanged. (Accounts seeded before this change got the password `secret`: change it.)
- **Before anyone else can reach the app,** set `APP_DEBUG=false` in `.env`, so an error shows the app's own "Something went wrong" page rather than details of the code and settings. Turn it back on only while fixing something on your own computer.
- To let any other user log in, set a password in the Password box on their **Edit user** page.
- After 5 wrong passwords for the same email or username, login is locked for a minute.
- **Forgot password?** under the login's password: type the account's email, and a link comes by email to a page to type a new password twice. The link works once, for 60 minutes (`config('auth.passwords.users')`), and is kept hashed in `dbo.PasswordResetTokens`.
  - **What it won't tell:** the page says a link is on its way "if an account has that email", whether or not one does, and sends the email after replying, so neither the words nor the time taken show who has an account. Only accounts that can log in get a link; one without a password stays as an administrator left it. Links are limited to 3 per email and 20 per IP address every 15 minutes.
  - **The new password** has the same rules as elsewhere (at least 8 characters). The password the account has now is refused with a warning, "That's the password your account has now. Choose a different one.", and the link still works to choose another. The page with the link's token tells the browser not to pass its address on to other sites.
  - **After:** the link stops working, everywhere else the account was logged in is logged out (Laravel's `AuthenticateSession`, which ends any login made with an older password), and an email says the password was changed, so the owner would notice if it wasn't them.
  - **Code:** `app/Http/Controllers/PasswordResetController.php`, the emails in `app/Notifications/`, the pages in `resources/views/auth/`.
- **Sending email with Gmail:** `.env` has Gmail's server (`MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`). To send, a Gmail account needs 2-Step Verification and an **App Password** (Google Account → Security → App passwords), a 16-letter password for this app only. Then in `.env` set `MAIL_MAILER=smtp`, `MAIL_USERNAME` and `MAIL_FROM_ADDRESS` to the Gmail address, and `MAIL_PASSWORD` to the App Password without spaces. Check it with `php artisan mail:test you@gmail.com`, which sends a test email or says what went wrong. With `MAIL_MAILER=log` (the default), emails are written to `storage/logs/laravel.log` instead of sent. Gmail sends up to about 500 emails a day.
- **The login page's backdrop** is a looping, silent video of a night police chase (`public/videos/login-backdrop.mp4`, 1280×720, 10 seconds, 7 MB), darkened so the form stays readable, with a still frame of it (`public/images/login-backdrop.jpg`) while it loads. `public/js/login-backdrop.js` starts it, except for devices set to reduce motion or save data, which keep the still frame, as do browsers without JavaScript. The round button in the bottom right corner pauses it, and a pause is remembered for the next visit. To use another video, replace those two files, keeping their names.
- **After logging in:** you go to the dashboard. If you had opened a particular page before logging in, like `/menu-items`, you go back to it; opening the site's address counts as asking for no particular page. Opening the login page or the site's address (`/`) while logged in, or clicking the menu's logo, also leads to the dashboard. If its route has been deleted on Manage routes, the users list takes its place.

## Public pages

Anyone can see the dashboard and the map without logging in, at **`/public/dashboard`** and **`/public/map`** (`/public` leads to the dashboard). The login page links to both.
- **The site's address:** someone not logged in who opens `/` goes to the public dashboard, and can log in from there. Every other page of the app still sends them to the login page. Logged in, `/` is the users list as before. The rule is `redirectGuestsTo` in `bootstrap/app.php`.
- **Same figures:** they show the same figures and charts as the pages in the app, from the same code: `DashboardController@publicIndex` and `MapController@publicIndex`. The public map gets its figures from `/public/map/crime` (`MapController@crime`).
- **Nothing else of the app:** they use their own layout, `resources/views/layouts/public.blade.php`, with the logo, **Dashboard** and **Map** along the top and **Log in** on the right. The app's menu, user details and pages don't appear. Someone already logged in gets **Open Crimeify** instead of **Log in**. Instructions meant for administrators, like how to load the figures, aren't shown either.
- **Always public:** the routes are in the Dashboard and Map modules' `Routes/web.php`, not on the Routes page, which adds its routes behind the login. So the Routes page can't make them private or remove them, and the app's own pages still need a login.
- **Rate limit:** each visitor can load the public pages 120 times a minute, far more than looking around needs, so no one can flood the database with requests.

**Bahasa Melayu or English:** the public pages have a **BM | EN** switch at the top, with the Malaysian flag beside BM and the UK's beside EN.
- **Choosing:** each side is a link to the same page with `?lang=ms` or `?lang=en`, so it works without JavaScript and a link can be shared in either language. A cookie (`locale`) keeps the choice for a year, so the dashboard, the map and the map's figures stay in it. Without a choice they're in English. The page's `lang` changes too, for screen readers. `app/Http/Middleware/SetPublicLocale.php` (`public-locale`) does this, on the public routes only: the app itself is in English, whatever the public pages were left in.
- **The Malay text** is in `lang/ms.json`, English to Bahasa Melayu, using the police's own terms like *jenayah kekerasan* and *jenayah harta benda*. The views use `__('…')`; the pages' scripts use `t('…')` and `tn(count, 'one', 'many')`, from `resources/views/layouts/_translations.blade.php`, with the same file. Select2's own words, like "No results found", come from it too. To change a word, edit it there; a test checks that every text the public pages translate has Bahasa Melayu, with the same `:placeholders`.
- **Not translated:** names from the data, like police stations and districts, and credits' proper names, like OpenStreetMap's.

## Modules

The code is split into modules, as in AMV (amv4local), with [nwidart/laravel-modules](https://laravelmodules.com). The package is configured in `config/modules.php` to lay modules out in AMV's folders, and `modules_statuses.json` switches each module on or off.

The **Setup** module holds users, roles, the navigation menu and the routes page:

```
Modules/Setup/
├── Http/Controllers/     UserController, RoleController, MenuItemController, AppRouteController
├── Resources/views/      users/, roles/, menu-items/, app-routes/   (used as setup::users.index)
├── Routes/web.php        its pages, behind the login
├── Providers/            SetupServiceProvider (views, config) and RouteServiceProvider (loads Routes/)
├── Tests/Feature/        its tests
├── Config/, Database/, Entities/, Resources/lang/
└── module.json, composer.json
```

The **Map** module (`Modules/Map`) is set up the AMV way: its `Routes/web.php` registers nothing, and its pages come from rows on the Routes page. It has two, both grouped under the **Map Module › Map** menu link:
- `GET /map` calls `Map\MapController@index` and shows the view `map::index`. Its name is `route('map')`.
- `GET /map/crime` calls `Map\MapController@crime` and gives the map its crime figures as JSON. Its name is `route('map/crime')`.

To add a page, write a public function in `MapController` and its view, then add a route for it on the Routes page. Every page is behind the login.

The Map page shows Malaysia's 13 states and 3 federal territories on an interactive [Leaflet](https://leafletjs.com) 1.9.4 map:
- **Using it:** pointing at a region shows its name. Clicking it, or its button in the list beside the map (states, then federal territories), selects it and shows its details in the panel above the map, beside the year. On a narrow screen, like a phone, the list goes under the map, above **Find a police station**. The list also lets you choose a region from the keyboard. The button under the zoom buttons enlarges the map to fill the window over the dimmed page; that button, Escape or a click on the dimmed page makes it small again.
- **Region codes:** each region is known by its ISO 3166-2 code, `MY-01` Johor to `MY-16` Putrajaya. The official names are in `Modules/Map/Config/config.php` (`config('map.states')`), so data can be linked to a region by its code.
- **Files:**
  - Leaflet is in `public/vendor/leaflet`, under the BSD-2-Clause licence.
  - The page's own script and styles are `public/modules/map/map.js` and `map.css`.
  - The boundaries are `public/modules/map/malaysia-states.geojson`, from geoBoundaries and made from OpenStreetMap data under the ODbL. The map shows the "© OpenStreetMap contributors" credit it requires; see `malaysia-states-LICENSE.txt`.
- **Offline:** the regions are drawn from this app's files, so they show without the internet. The street map underneath comes from OpenStreetMap's tile server and needs the internet. That server is meant for light use; for heavy use, switch the tile address in `map.js` to a paid provider or your own tile server.

The map also has a pin for each of Malaysia's 155 police districts, with its crime figures:
- **Using it:** choose a year in the panel. Clicking a pin opens a popup with the district's violent and property crime for that year, each compared with the year before, and the number of each crime type. With no region chosen, the panel shows the whole country's totals; with one chosen, that region's. Pins close together are grouped into numbered circles until you zoom in (the [Leaflet.markercluster](https://github.com/Leaflet/Leaflet.markercluster) 1.5.3 plugin, MIT licence, in `public/vendor/leaflet-markercluster`).
- **The data:** data.gov.my's [crime by district](https://data.gov.my/data-catalogue/crime_district) dataset, from the Royal Malaysia Police (PDRM) and the Department of Statistics Malaysia (DOSM), under CC BY 4.0. The map credits it. It gives each police district's yearly count of each crime type, 2016 to 2023 so far, not single crimes or where they happened, so a pin stands for a whole district.
- **Loading it:** `php artisan map:import-crime` downloads the latest file and replaces what was loaded before, so run it again when data.gov.my adds a year. To load a file you've already downloaded, give its path: `php artisan map:import-crime crime_district.csv`. The figures go in `dbo.CrimeStats` and the pins in `dbo.PoliceDistricts`. Until the command has run, the page says to run it.
- **Where the pins go:** `Modules/Map/Database/data/police-districts.csv` gives each district's position: its district police headquarters where OpenStreetMap has it, otherwise a police station or the town centre. To move a pin, edit its row and run the import again. See `police-districts-LICENSE.txt` for the sources (OpenStreetMap, ODbL).
- **Names:** four districts were renamed during those years; the import merges each under its current name so it keeps one pin and an unbroken record. The renames are listed in `config('map.crime.renamed')`. The data files Labuan under Sabah and Putrajaya under Kuala Lumpur, but their pins and totals count under their own federal territories.
- **Checks:** the import says if the file has a district with no pin, or a crime type the popups have no label for. The labels are in `config('map.crime.types')`.

**2024, by state:** data.gov.my has police district figures up to 2023. For 2024, the map and dashboard use the Royal Malaysia Police's crime index tables A to D ("Crime Index.xlsx"): each state's violent and property crime by type, but not by police district.
- **The data:** `Modules/Map/Database/data/crime-by-state.csv`, made from the tables' 2022 to 2024 rows, and `crime-by-state-LICENSE.txt` on where it's from and how it was checked. It's loaded into `dbo.CrimeStats` as data.gov.my's state and Malaysia totals are (police district "All"), with Malaysia's added up from the states', only for years with no police district figures. A year with them, from data.gov.my or the Crime data page, always keeps them. `Modules/Map/Support/StateCrime.php` does the loading.
- **Loading it:** `php artisan map:import-state-crime`, safe to run again. `php artisan map:import-crime` runs it too, after replacing data.gov.my's figures, so 2024 stays. Once data.gov.my publishes 2024 by police district, the next import loads those and 2024 is by district like the other years. For a later year by state, add its rows to the CSV and run the command.
- **As the tables have them:** Sabah includes Labuan and W.P. Kuala Lumpur includes Putrajaya; robbery is one type (`config('map.by_state.types')`), where data.gov.my has four kinds. The tables' 2022 and 2023 figures are the same as data.gov.my's for every state and type. In table D's 2024 rows, the three vehicle theft columns are in the order motorcar, motorcycle, lorry, not as headed; the CSV has them under their types (see the LICENSE file). The tables' 2024 total cells are broken formulas, so the totals were added up instead.
- **On the map:** 2024 has no police district pins. The panel shows each state's totals compared with its 2023 state totals, Sabah "with Labuan" and Kuala Lumpur "with Putrajaya", and says there are no pins for 2024. Choosing Labuan or Putrajaya says their figures are in Sabah's or Kuala Lumpur's. The map's credit changes to the police's crime index for 2024.
- **On the dashboard:** everything shows for 2024. Crime by state has Sabah and Kuala Lumpur with Labuan and Putrajaya in them, and says so; crime by type has robbery as one; the credit names the crime index for 2024.
- **On the Crime data page:** 2024 isn't listed, as it has no police district figures to edit, and adding or uploading a police district figure for 2024 is refused: the states' 2024 totals would otherwise be added up again from those few figures.

The **Crime data** page (`/crime-data`, under **Dev Module › Crime data** in the menu) is where administrators edit those figures.
- **Who can open it:** only users with the ADMIN role, as its routes (`crime-data`, `crime-data/download`, and one for each of saving, adding, deleting and uploading) are set on Manage routes. The server checks this, so typing the address doesn't get anyone else in: they get a "Not allowed" page. Each can be opened to other roles there on its own; opening the page alone lets people look but not change anything.
- **The list** shows 10 figures a page, or 50, 100 or all of them, chosen in **Rows per page**, as DataTables' `pageLength` and `lengthMenu` (`[[10, 50, 100, -1], [10, 50, 100, "All"]]`), which are `PageLength` and `LengthMenu` in `CrimeDataController`. The pages are numbered, as in DataTables. The list is paged on the server rather than with DataTables, so only a page's figures are sent and every count on it can be edited. All 14,880 figures at once is a page of about 21 MB, so choose a state or year first. **Save changes** sends only the changed counts; if PHP drops some of a long list's values on the way (its `max_input_vars`, 1,000 here), nothing is saved and the page says so.
- **Editing on the page:** choose figures by state, district, year and crime type, change counts in the list and click **Save changes**, delete a figure, or add one with **Add a figure**. The page marks changed counts until they're saved, and asks before you leave with unsaved changes. If someone else changed a count while you were editing it, nothing is saved and the page says so.
- **Editing in Excel:** **Download Excel** gives every figure as an .xlsx file, with a Read me sheet listing the crime types. Change it in Excel and upload it with **Upload an Excel file**. The next page lists every figure the file would change, add or delete, and nothing changes until you click **Apply the changes**. A figure missing from the file is deleted, so upload the whole file. The file must keep the headings `state, district, category, type, year, crimes`; a problem in a row is reported with its row number. The files are read and written with [OpenSpout](https://github.com/openspout/openspout) (MIT licence).
- **Totals:** the page edits only each police district's count of a crime type. Every total is added up from those: a district's violent or property crime, a state's figures (district "All") and Malaysia's. So an edit updates the totals above it, and the map and dashboard show it straight away.
- **History and re-importing:** every edit is recorded in `dbo.CrimeDataEdits` with who made it and when, and the page lists them under **Update Logs by User**, newest first, paged like the figures: 10 a page, or 50, 100 or all, with numbered pages. The log pages on its own (`?edits_length=` and `?edits_page=`), so paging it keeps the figures where they were, and the other way round. While there are edits, `php artisan map:import-crime` stops rather than replace them, and says so. `php artisan map:import-crime --force` replaces them with data.gov.my's figures and clears the history.
- **Code:** `Modules/Map/Http/Controllers/CrimeDataController.php`, with the editing, totals and Excel work in `Modules/Map/Support/CrimeData.php`, and the views in `Modules/Map/Resources/views/crime-data/`.

Under the map, **Find a police station** lists a state's police stations, on the app's map and the public one:
- **Using it:** choose a state, then one of its stations in the second list, which waits for a state. The station's police district, address and phone number appear under the lists; the phone number is a link phones can call. An address or number not known yet shows as "Not added yet". Only states with stations are offered.
- **Directions:** the address is a link to the station on Google Maps, which opens in a new tab on a computer. On a phone or tablet (Android, iPhone, iPad), tapping it asks **Google Maps** or **Waze**, and each opens its app if the phone has it, or its website if not. The maps search for the station's name and address, so they land on the station rather than just its street. A station without an address has **Search for it on the map** instead, which searches for its name in its state. The links are `googleMapsUrl()` and `wazeUrl()` in the `PoliceStation` model; the public map loads SweetAlert2 for the question, which the app's layout already has.
- **The data:** `dbo.PoliceStations`, one row per station: the state or federal territory's code (`Region`, like `MY-01`), its police district, name, address and phone number. They come with the page, so choosing one doesn't wait on the server. `public/modules/map/station-finder.js` fills the second list and the details; the styles are in `map.css`.
- **Loading them:** `php artisan map:import-stations` adds a station for each police district that has none yet, from `Modules/Map/Database/data/police-stations.csv`. That's the police station each district's pin is on, from OpenStreetMap (ODbL, see `police-stations-LICENSE.txt`): 129 named stations, and for the other 26 districts, whose pins are on a town centre, the district's HQ by its usual name, "Ibu Pejabat Polis Daerah …". OpenStreetMap had an address for only 22 of them and a phone number for 18, as it wrote them; the rest are left empty rather than guessed. Running the command again never changes a saved station, but a district left with none gets its station back.

The **Police stations** page (`/police-stations`, under **Dev Module › Police stations**) is where administrators keep those stations up to date.
- **Who can open it:** only users with the ADMIN role, as with the Crime data page: the menu link is shown only to ADMIN, and its routes (`police-stations`, `police-stations/create`, and one each for saving, editing and deleting) are set on Manage routes, where each can be opened to other roles.
- **The list** shows the stations by state and name, with their police district, address and phone number, 10 a page, or 50, 100 or all of them, chosen in **Rows per page**, with numbered pages, as on the Crime data page (`PageLength` and `LengthMenu` in `PoliceStationController`). Choose a state, search by name, district or address, or tick **Only those missing details** for the stations still without an address or phone number, which a note above the list counts. A list or the box shows the list straight away (`public/modules/map/police-stations.js`); a search waits for Enter or **Show**. Both pages draw their pages with `resources/views/layouts/_pager.blade.php`.
- **Adding, editing and deleting:** **Add police station**, **Edit** and **Delete** each ask "Are you sure…?" first. A station needs a state or federal territory, a police district (the known ones are suggested) and a name, which no other station in the same state may have. The address and phone number can be left empty.
- **Code:** `Modules/Map/Http/Controllers/PoliceStationController.php`, the model `Modules/Map/Entities/PoliceStation.php`, the views in `Modules/Map/Resources/views/police-stations/`, and the list's styles in `public/modules/map/police-stations.css`.

The **Dashboard** module (`Modules/Dashboard`) is the page to start from after logging in: `GET /dashboard`, named `route('dashboard')`, which calls `DashboardController@index` and shows the view `dashboard::index`. It's a route on Manage routes, under the **Dashboard** link at the top of the menu, which everyone who's logged in can see.

The dashboard shows a year's crime figures, the latest unless you choose another year at the top right (`/dashboard?year=2020`). They come from the Map module's copy of the crime data, so run `php artisan map:import-crime` first; until then, the page says to.
- **Cards:** all crime, violent crime, property crime, murder, break-ins and vehicle theft. Each gives the year's count, every year's count as small bars (the chosen year's brightest), and the change from the year before: ▲ in red for more crime, ▼ in green for less. The cards are set in `Modules/Dashboard/Config/config.php` (`config('dashboard.cards')`): each has a label, a Material icon, a colour and the crime types it adds up.
- **Charts:** crime over the years (violent and property, every year), crime by state for the year, and crime by type for the year (the five largest types, then the rest added up). The state totals are added up from police districts, as on the Map page, so Labuan and Putrajaya count on their own.
- **Files:** the view is `Modules/Dashboard/Resources/views/index.blade.php`, with the charts drawn by `public/modules/dashboard/dashboard.js` and styled by `dashboard.css`. The charts use [Chart.js](https://www.chartjs.org) 4.5.1 (MIT licence), in `public/vendor/chartjs` so it works without the internet.
- **Accessibility:** each chart's figures are also in a table that screen readers read in its place, and the cards' figures are plain text.
- **Look:** the cards are the app's charcoal panels, with the charts in the same toned-down colours.

What every module shares stays outside `Modules/`:
- **Models:** `User`, `Role`, `MenuItem` and `AppRoute` are in `app/Models`, as AMV keeps its menu and role models in `app/Models`.
- **Login:** `LoginController` is in `app/Http/Controllers`, and login and logout are in `routes/web.php`.
- **Page layout:** the layouts and sidebar are in `resources/views/layouts`, and the error pages in `resources/views/errors`.
- **Assets and migrations:** CSS and JavaScript are in `public/`, and migrations are in `database/migrations`.

To add a module, for example `php artisan module:make Bajet`, use the same commands as in AMV. Then add to it with commands such as `php artisan module:make-controller BajetController Bajet`.

A module's pages have the addresses their routes give them, on Manage routes or in the module's `Routes/web.php`. Setup's are `/users/…`, `/roles/…`, `/menu-items/…`, `/profile` and `/routes`; the users list moved from `/` to `/users`, and editing moved from `/users/5/edit` to `/users/edit/5`.

On the Routes page, a controller in a module is saved with the module's name in front, like `Setup\RoleController`. The class name alone is enough when only one module has it. `AppServiceProvider` adds saved routes after every module's routes, so a saved route can never catch a page's address.

## Tests

```sh
php artisan test
```

This runs `tests/` and every module's `Tests/` folder. The tests use an in-memory SQLite database and never touch `MyAppDB`.

## Where things moved from the ASP.NET Core version

| ASP.NET Core                        | Laravel                                                   |
| ----------------------------------- | --------------------------------------------------------- |
| `Program.cs`                        | `bootstrap/app.php`, `routes/web.php`, `Modules/Setup/Routes/web.php` |
| Connection string in `appsettings.json` | `DB_*` settings in `.env`                             |
| `Models/User.cs`                    | `app/Models/User.php` (plus the new `app/Models/MenuItem.php`) |
| `Data/UserRepository.cs`            | Eloquent, via `app/Models/User.php`                       |
| `Pages/**/*.cshtml.cs` page models  | `Modules/Setup/Http/Controllers/UserController.php`       |
| `Pages/**/*.cshtml` views           | `Modules/Setup/Resources/views/**/*.blade.php`            |
| `wwwroot/css/site.css`              | `public/css/site.css`                                     |
