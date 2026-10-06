# Putting Crimeify on Azure

This puts Crimeify on **Azure App Service** (the app, as the Docker image built from the `Dockerfile`) with **Azure SQL** (the database). The same image also runs on a VPS or your own computer, so nothing here ties you to Azure.

The commands are for **PowerShell** with the [Azure CLI](https://learn.microsoft.com/cli/azure/install-azure-cli) (`az`). You don't need Docker on your computer: Azure builds the image for you. Do **staging first**, a copy that only you use, and open it to the public once the checklist at the end is done.

> These steps were first run on 6 October 2026 with Azure CLI 2.91, for the staging site `crimeify-staging-4200.azurewebsites.net` (resource group `crimeify-rg`, Southeast Asia). If a step fails, **Troubleshooting** at the end covers the likely causes.

## What you're building

```
browser ──https──▶ Azure App Service (the image: Apache + PHP 8.4 + SQL Server driver)
                         │  logins, cache, all data
                         ▼
                   Azure SQL Database  ◀── php artisan commands, from your computer
```

- **App Service** terminates https, gives the site an `*.azurewebsites.net` address, and can add your own domain with a free certificate.
- **Azure SQL** keeps the data and makes automatic backups. Logins and the rate-limit counters live in it too (`dbo.sessions`, `dbo.cache`), because the container's disk is wiped on each deploy.
- Prices change: check the [Azure pricing calculator](https://azure.microsoft.com/pricing/calculator/) for a Basic App Service plan (B1), a Basic or S0 SQL database, and a Basic container registry.

## 1. Names

Pick them once; the rest of the steps use them. Names marked unique must be unique across all of Azure.

```powershell
az login
$rg       = "crimeify-rg"
$region   = "southeastasia"          # or "malaysiawest", if your subscription can use it
$sqlServer = "crimeify-sql-yourname" # unique; lower case letters, numbers, dashes
$sqlAdmin = "crimeifyadmin"
$sqlPass  = '<a long password you make up>'   # single quotes, so a $ in it stays a $
$dbName   = "CrimeifyDB"
$acr      = "crimeifyyourname"       # unique; letters and numbers only
$plan     = "crimeify-plan"
$app      = "crimeify-yourname"      # unique; becomes crimeify-yourname.azurewebsites.net

az group create -n $rg -l $region
```

## 2. The database

```powershell
az sql server create -g $rg -n $sqlServer -l $region -u $sqlAdmin -p $sqlPass
az sql db create -g $rg -s $sqlServer -n $dbName --service-objective Basic
```

If Azure says the region can't make SQL servers for your subscription, try another region (`southeastasia`, `eastasia`) for the server only; the app can stay where it is.

Don't pick a **serverless** database with auto-pause for a public site: the first visitor after a quiet spell waits a long time while it wakes up. A fixed Basic or S0 database is always ready.

Open the firewall, which is closed by default:

```powershell
# Lets Azure services in, which includes the web app. Tighten it later (see the checklist).
az sql server firewall-rule create -g $rg -s $sqlServer -n AllowAzure --start-ip-address 0.0.0.0 --end-ip-address 0.0.0.0

# Lets your computer in, for step 7. Use your own public IP address (search "what is my ip").
az sql server firewall-rule create -g $rg -s $sqlServer -n MyComputer --start-ip-address <your-ip> --end-ip-address <your-ip>
```

Some internet providers switch between several addresses: if step 7 says `Client with IP address '…' is not allowed`, with a different address from the one you gave, widen the rule to that block for the setup (like `203.0.113.0` to `203.0.113.255`) with `az sql server firewall-rule update`, and delete it afterwards.

The server's name for the app is `$sqlServer.database.windows.net`. Using the admin login for the app is fine for staging; for the real site, make a separate database user for the app that can read and write but not drop the database.

## 3. Build the image

```powershell
az acr create -g $rg -n $acr --sku Basic --admin-enabled true
az acr build -r $acr -t crimeify:latest .
```

Run `az acr build` from the project folder. It uploads the code (skipping what `.dockerignore` lists, including `.env`), builds it in Azure and stores the image. The first build takes several minutes.

## 4. The web app

```powershell
az appservice plan create -g $rg -n $plan --is-linux --sku B1

$acrPass = az acr credential show -n $acr --query "passwords[0].value" -o tsv
az webapp create -g $rg -p $plan -n $app --container-image-name "$acr.azurecr.io/crimeify:latest" --container-registry-url "https://$acr.azurecr.io" --container-registry-user $acr --container-registry-password $acrPass --https-only true

# Check the image name: with a registry address, `webapp create` (CLI 2.91) puts the address in front a second time.
az webapp config show -g $rg -n $app --query linuxFxVersion -o tsv
# If it says DOCKER|<acr>.azurecr.io/<acr>.azurecr.io/crimeify:latest, set it again with the full name.
# (`config container set` doesn't add the address, so give it in full.)
az webapp config container set -g $rg -n $app --container-image-name "$acr.azurecr.io/crimeify:latest" --container-registry-url "https://$acr.azurecr.io" --container-registry-user $acr --container-registry-password $acrPass

az webapp config set -g $rg -n $app --always-on true --http20-enabled true --ftps-state Disabled

# Azure's health check, on /up, which answers as soon as the app is running.
'{"healthCheckPath": "/up"}' | Set-Content health.json
az webapp config set -g $rg -n $app --generic-configurations "@health.json"
```

(Older versions of the CLI call these options `--docker-custom-image-name`, `--docker-registry-server-url`, `--docker-registry-server-user` and `--docker-registry-server-password`.) The registry's admin password is the quick way to let the app pull the image; the checklist suggests a managed identity instead. With the image name wrong, the log in step 6 says `ImagePullUnauthorizedFailure` and the site answers 503.

## 5. Settings

Every line of [`.env.production.example`](.env.production.example) becomes an **Application setting**. In the portal: your web app → **Settings → Environment variables**. Fill in `APP_KEY` (make one with `php artisan key:generate --show` on your computer), `APP_URL` (`https://$app.azurewebsites.net` for now), the `DB_*` values from step 2, the mail settings and `MAP_TILE_URL`. Type values without the quotes that the example shows. Also add `WEBSITES_PORT` = `80`.

Or from PowerShell, for example:

```powershell
az webapp config appsettings set -g $rg -n $app --settings WEBSITES_PORT=80 APP_ENV=production APP_DEBUG=false APP_URL="https://$app.azurewebsites.net" TRUSTED_PROXIES="*" LOG_CHANNEL=stderr LOG_LEVEL=warning DB_CONNECTION=sqlsrv DB_HOST="$sqlServer.database.windows.net" DB_PORT=1433 DB_DATABASE=$dbName DB_USERNAME=$sqlAdmin DB_PASSWORD=$sqlPass DB_TRUST_SERVER_CERTIFICATE=false SESSION_DRIVER=database SESSION_ENCRYPT=true SESSION_SECURE_COOKIE=true CACHE_STORE=database APP_KEY="<the key>"
```

(Settings with `<` or `&` in them, like `MAP_TILE_ATTRIBUTION`, are easier to enter in the portal.) Changing a setting restarts the app.

**Email:** a public site shouldn't send from a personal Gmail (about 500 a day, and Google may block it). Use a sending service's SMTP details for `MAIL_*`, then check with `php artisan mail:test` (step 7).

## 6. Watch the logs

```powershell
az webapp log config -g $rg -n $app --docker-container-logging filesystem
az webapp log tail -g $rg -n $app
```

Leave this open in a second window while you do the next step. Apache's access and error logs and Laravel's (`LOG_CHANNEL=stderr`) both show here.

## 7. Create the tables, the first login and the figures

The app can't open a single page until its tables exist (logins are in `dbo.sessions`). Run the setup **from your computer**, pointed at the Azure database: PowerShell's `$env:` settings win over `.env` for that window only, so your local database isn't touched. Close the window afterwards.

```powershell
$env:DB_HOST = "$sqlServer.database.windows.net"
$env:DB_PORT = "1433"
$env:DB_DATABASE = $dbName
$env:DB_USERNAME = $sqlAdmin
$env:DB_PASSWORD = $sqlPass
$env:DB_TRUST_SERVER_CERTIFICATE = "false"

php artisan migrate --force        # all the tables, and the default menu and pages
php artisan db:seed --force        # the admin (with the ADMIN role) and demo logins; passwords shown once: write them down
php artisan map:import-crime       # the crime figures (downloads from data.gov.my) and 2024 by state
php artisan map:import-stations    # a police station for each district

# The menu and pages you made on Manage menu and Manage routes: they're rows in the database, not code, so a new
# database only has the starter links. Export them from your own database (in a window WITHOUT the $env: settings)...
#   php artisan menu:export
# ...then put the file it names into this one (it saves what was there first, and adds the roles it needs):
php artisan menu:import "<the file menu:export saved>"

# The demo login isn't for a public site.
php artisan tinker --execute="App\Models\User::where('Email', 'demo@myapp.local')->first()?->delete();"
```

Then open `https://$app.azurewebsites.net` and log in as `admin` with the password `db:seed` showed. Then, straight away:

1. On **My profile**, change the admin's email to a real one (the `.myapp.local` addresses can't receive a password-reset email) and set a new password.
2. Check the demo user is gone from **Users**.
3. Check the email settings: in the same PowerShell window, set the same `MAIL_*` values as `$env:MAIL_HOST = "..."` and so on, then run `php artisan mail:test you@example.com`. Then try **Forgot password?** on the site itself.

**After a later release that adds a migration:** run `php artisan migrate --force` the same way, before or just after restarting the app on the new image. Or set `RUN_MIGRATIONS` = `true` for one start, then back to `false`. Keep to **one instance** while it's on, so two copies don't migrate at once.

**Moving what's in your local MyAppDB instead of starting fresh:** back it up as a `.bacpac` from SQL Server Management Studio (Tasks → Export Data-tier Application) and import it into Azure SQL (`az sql db import`, or SSMS). Run `migrate --force` afterwards for the tables it doesn't have yet.

## 8. Your own domain (optional)

Add a CNAME for your name pointing to `$app.azurewebsites.net`, and a TXT record `asuid.<name>` with the **Custom Domain Verification ID** from the web app's **Custom domains** page. Then:

```powershell
az webapp config hostname add -g $rg --webapp-name $app --hostname www.example.com
az webapp config ssl create -g $rg -n $app --hostname www.example.com      # a free managed certificate
az webapp config ssl bind -g $rg -n $app --certificate-thumbprint <thumbprint from the line above> --ssl-type SNI
```

Then change the `APP_URL` setting to the new `https://` address.

## Releasing a change

```powershell
az acr build -r $acr -t crimeify:latest .
az webapp restart -g $rg -n $app
```

The app starts on the new image in a minute or two. If it added a migration, see step 7. Pending Excel uploads waiting on a review page are lost by a restart (they're kept in the container's disk): upload the file again.

A release carries the code only. If you've changed the menu or routes on your own computer since, copy them across with `menu:export` and `menu:import` as in step 7, or make the same change on the site's Manage menu and Manage routes pages. `menu:import` replaces the site's whole menu and all its routes with the file's.

## Trying the image on your own computer (needs Docker)

```powershell
docker build -t crimeify .
docker run --rm -p 8080:80 -e APP_KEY="<a key>" -e APP_URL=http://localhost:8080 -e DB_CONNECTION=sqlsrv -e DB_HOST=host.docker.internal -e DB_DATABASE=<a test database> -e DB_USERNAME=<user> -e DB_PASSWORD=<password> -e DB_TRUST_SERVER_CERTIFICATE=true -e SESSION_DRIVER=file -e CACHE_STORE=file crimeify
```

## Before opening it to the public

- [ ] **Tile provider** set (`MAP_TILE_URL`), and its key limited to your site's address in the provider's dashboard.
- [ ] **Real email** sending (`MAIL_*`), tested with `mail:test`; a real address on the admin; demo user deleted.
- [ ] **SQL firewall tightened:** replace the *AllowAzure* rule with the web app's own addresses (`az webapp show -g $rg -n $app --query possibleOutboundIpAddresses`), and delete *MyComputer* when you're done with step 7.
- [ ] **A database user for the app** that can't drop the database, instead of the server admin.
- [ ] **Backups checked:** Azure SQL backs up automatically (point-in-time restore, 7 days by default); check the retention in the portal and try one restore.
- [ ] **Registry access by managed identity** instead of the admin password (`az acr update -n $acr --admin-enabled false` once the web app pulls with its identity).
- [ ] **Alerts:** an email from Azure Monitor when the site returns errors or `/up` stops answering. Add an error tracker (Sentry, Flare) if you want to see the exceptions as they happen.
- [ ] **Cloudflare (or Azure Front Door) in front,** for caching the 7 MB login video and the map files, and to take the load off a flood of requests. The app's own limit is 120 loads a minute per visitor.
- [ ] **HSTS and a Content-Security-Policy,** tried on staging first (`docker/apache.conf` has the place for them). A wrong CSP breaks pages; HSTS can't be taken back quickly.
- [ ] **Privacy notice:** the Users table holds names, emails, phone numbers and photos, so Malaysia's PDPA applies. Say what's kept and who to ask.

## Limits to know about

- **One instance.** Uploads waiting for review (`storage/app/private/*-uploads`) live on the container's disk, so a second instance wouldn't find the first one's. Fine for this app's size; scale up (a bigger plan) rather than out.
- **No route cache.** The Routes page adds routes from the database on every request, so `route:cache` would hide its changes. The entrypoint only caches settings and views.
- **The street map needs the internet** from the visitor's browser (tiles), as before. The regions, pins and figures come from the app.

## Troubleshooting

| What you see | Likely cause |
|---|---|
| The site shows an error page, and the log says `Invalid object name 'sessions'` | Step 7's `migrate` hasn't run against this database. |
| `Login failed for user`, or `Cannot open server ... requested by the login` in the log | The SQL firewall doesn't let the web app (or your computer) in, or `DB_USERNAME`/`DB_PASSWORD` is wrong. |
| The container restarts again and again, and the log says `APP_KEY is not set` | Add the `APP_KEY` setting. |
| The site never comes up, "Application Error" | `WEBSITES_PORT` isn't `80`, or the image failed to pull (check the registry settings in step 4). |
| Logging in sends you back to the login page | `SESSION_SECURE_COOKIE=true` but the page isn't https, or `TRUSTED_PROXIES` is missing so the app thinks the connection is plain http. Use the https address and check both settings. |
| Links and files point at `http://` | `APP_URL` isn't `https://`. |
| The build stops at `pecl install` | The SQL Server driver version isn't on PECL: build with `--build-arg SQLSRV_VERSION=<the newest at pecl.php.net/package/sqlsrv>`. |
| The build stops at `msodbcsql18` | Microsoft's package source changed: check [Microsoft's install page](https://learn.microsoft.com/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server) for Debian 12. |
| The map is grey, with no streets | `MAP_TILE_URL` is wrong, or the provider's key is limited to a different address. |
