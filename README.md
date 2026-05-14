# Pick & Pack – POC

A lightweight pick & pack app that gives users an overview of orders and order details in one place. It combines three data sources:

- Shopify – order and line data (unarchived orders)
- Webshipper – shipping and return labels
- Business Central – source of truth for fulfillment (e.g. GIA, ship dates)

Users see a single view of orders and can switch between two views: pick & pack and shipping.

## Tech Stack

- Laravel 12 (PHP 8.4+)
- Laravel Breeze (auth scaffolding)
- Livewire + Volt
- Tailwind CSS
- Vite
- Shopify API PHP library
- Shopify CLI
- Redis (queues; required for Horizon)
- Laravel Horizon (queue dashboard)
- Laravel Telescope (debugging / monitoring / dashboard)
- Spatie Laravel Activity Log (activity logging)
- Mailgun (transactional email)
- Typescript (minimal use frontend under web/resources/js)

## What this starter includes

- Shopify embedded app OAuth flow (`/api/auth`, `/api/auth/callback`)
- Laravel email/password login
- Dashboard page behind Laravel auth
- Logout flow back to login
- SQLite local database setup (easy to swap for MySQL/Postgres later)

## Getting started

### Requirements

1. You must [create a Shopify partner account](https://partners.shopify.com/signup) if you don’t have one.
1. You must create a store for testing if you don't have one, either a [development store](https://help.shopify.com/en/partners/dashboard/development-stores#create-a-development-store) or a [Shopify Plus sandbox store](https://help.shopify.com/en/partners/dashboard/managing-stores/plus-sandbox-store).
1. You must have [PHP](https://www.php.net/) 8.4 or higher installed.
1. You must have [Composer](https://getcomposer.org/) installed.
1. You must have [Node.js](https://nodejs.org/) installed.
1. Redis must be installed and running (required for queues and Horizon).

### Git clone

Clone the project.

NOTE: Make sure you remove the existing .git and connect it to your own repo.

### Setting up

These are the typical steps needed to set up a Laravel app once it's cloned:

1.  From the repo root run:

    ```shell
    npm install
    ```

2.  Now switch to the `web` folder:

    ```shell
    cd web
    ```

3.  Install your composer dependencies:

    ```shell
    composer install
    ```

4.  Install laravel dependencies:

    ```shell
    npm install
    ```

5.  Create the `.env` file (still inside web/):

    ```shell
    cp .env.example .env
    ```

6.  Bootstrap the default [SQLite](https://www.sqlite.org/index.html) database and add it to your `.env` file:

    ```shell
    touch database/database.sqlite
    ```

    **NOTE**: The app uses Laravel’s default path, so you don’t need to set DB_DATABASE in .env unless you use a different path.

    Use the following descriptions to fill in the rest of your .env; some values must be provided by whoever has admin access to the relevant services:
    - APP_KEY: see the section below

    - HOST: https://your-ngrok-url (without port)

    - APP_URL: https://your-ngrok-url (without port)

    - SHOPIFY_STORE_DOMAIN: Store hostname (e.g. `mystore.myshopify.com`). From the admin URL or Partners when opening the store.

    - SHOPIFY_ACCESS_TOKEN: Admin API token.

    - SHOPIFY_API_VERSION: API version, e.g. `2025-01`. Omit to use the app default.

    - BC_TENANT_ID: Azure AD tenant ID from Azure portal.

    - BC_CLIENT_ID: same as above just for client --> see Application (client) ID.

    - BC_CLIENT_SECRET: Secret for that app also from admin access.

    - BC_ENVIRONMENT: Business Central company name or display name. Used to select which BC company to use.

    - BC_COMPANY_ID: Business Central company ID (GUID). If set, overrides company selection by name. Found in BC.

    - WEBSHIPPER_ACCOUNT_NAME: The Webshipper account subdomain/slug (used in the API URL). Found in the Webshipper dashboard or in the URL when logged in.

    - WEBSHIPPER_ACCESS_TOKEN: Webshipper API token for authentication. Created or copied from the Webshipper dashboard.

    - MAIL_USERNAME: SMTP login from Mailgun. Mailgun dashboard.

    - MAIL_PASSWORD: SMTP password from the same Mailgun SMTP credentials section.

    - MAIL_HOST: SMTP server. For Mailgun default is `smtp.mailgun.org`.

    - MAIL_PORT: Use `587` for TLS.

    - MAIL_ENCRYPTION: Use `tls` for port 587.

    - MAIL_FROM_ADDRESS: Sender email (e.g. `noreply@yourdomain.com`). Should be from a domain you’ve verified in Mailgun.

    - MAIL_FROM_NAME: Sender display name.

7.  Generate an `APP_KEY` for your app:

    ```shell
    php artisan key:generate
    ```

8.  Create the necessary Shopify tables in your database:

        ```shell
        php artisan migrate
        ```

    and:

        ```shell
        npm run build
        ```

And your Laravel app is ready to run! You can now switch back to your app's root folder to continue:

```shell
cd ..
```

### Configure the env file in web/.env if not already set:

- Set the absolute path for the database, e.g. `DB_DATABASE=database/database.sqlite`. Or remove the line to make laravel choose the default path.

9. Configure embedded session/cookies (still in web/.env):

    SESSION_DRIVER=file
    SESSION_SECURE_COOKIE=true
    SESSION_SAME_SITE=none

### Local Development

[The Shopify CLI](https://shopify.dev/docs/apps/tools/cli) connects to an app in your Partners dashboard.
It provides environment variables, runs commands in parallel, and updates application URLs for easier development.

You can develop locally using your preferred Node.js package manager.
Run one of the following commands from the root of your app:

Option 1: Use npm

```shell
npm run dev
```

Option 2: Use your own tunnel (ngrok)

1. Start the tunnel in your normal terminal

```shell
ngrok http 3000
```

2. Copy the HTTPS URL from ngrok

3. In web/ .env, set:
   HOST=https://your-subdomain.ngrok-free.app

4. From the app root, run:

```shell
   shopify app dev --tunnel-url https://your-subdomain.ngrok-free.app:3000
```

or

```shell
npm run dev -- --tunnel-url https://your-subdomain.ngrok-free.app:3000
```

Open the Preview URL from the terminal (from the terminal press p), then you can start development.

To use the login setup create a quick test user:

```shell
cd web
```

```shell
php artisan tinker
```

Then add this:

```shell
\App\Models\User::updateOrCreate(
['email' => 'example@email.com'],
['name' => 'Admin', 'password' => 'Some password']
);
```

Then exit.

## Link This Repo to Your Shopify App (if not linked)

If Shopify CLI shows an error like “no app is linked”, “config not linked”, or opens the wrong app, re-link this project to your app config.

From the project root:

```shell
shopify app config link
```

# Application Features

## Shopify Embedded App Behavior

### Authentication Flow

1. The app is launched inside Shopify Admin (embedded context).
2. Shopify app authentication is initiated through `/api/auth` and completed via `/api/auth/callback`.
3. After Shopify authentication, users authenticate in the app with Laravel auth (email/password).
4. The app preserves Shopify context parameters (`shop`, `host`, `embedded`) across redirects (login/logout), so users return to the embedded app correctly.

### Authorization Model

- **Application-level roles** are stored on `users.role`:
    - `super_admin`
    - `coworker`
- `super_admin` users can access coworker management flows (invite/list/restore).
- Route access is protected by Laravel `auth` middleware.
- Horizon and Telescope dashboard access is controlled by the `viewHorizon` gate (`app/Providers/HorizonServiceProvider.php`) and the `viewTelescope` gate (`app/Providers/TelescopeServiceProvider.php`) respectively.

### Embedded App Notes

- The app is designed to run embedded in Shopify Admin with App Bridge-compatible behavior.
- Session/cookie settings must support embedded usage (secure cookie + same-site requirements).
- For local tunnel development (ngrok/Shopify CLI), use publicly reachable URLs for user-facing links (for example, password reset links in emails).

## Order data and data sources

The main dashboard shows orders in a single view. Data is combined from three sources:

- **Shopify** – Order and line item data (unarchived orders) via the Storefront/Admin API. Requires `SHOPIFY_STORE_DOMAIN` and `SHOPIFY_ACCESS_TOKEN` (or OAuth session) in `.env`.
- **Webshipper** – Shipping and return label information. Used to create and display labels. Requires `WEBSHIPPER_ACCOUNT_NAME` and `WEBSHIPPER_ACCESS_TOKEN`. When not configured or in test mode, label creation is disabled.
- **Business Central** – Fulfillment data (e.g. GIA, ship dates) as source of truth. Used to read and write order lines. Requires `BC_TENANT_ID`, `BC_CLIENT_ID`, `BC_CLIENT_SECRET` (and optionally `BC_ENVIRONMENT`, `BC_COMPANY_ID`, `BC_COMPANY_NAME`). When not configured or in test mode, writing to BC is disabled.

Users can switch between **pick & pack** and **shipping** views. If a source is not configured in `.env`, that data is omitted or disabled in the UI.

### Demo data and placeholder integration values

For the bachelor/demo setup, Shopify orders are loaded from the connected Shopify development store. This means orders shown in the UI are Shopify development-store records, even when they were created by the seed scripts.

Business Central and Webshipper data may be missing in the demo setup. When no live external record is linked, the UI shows short placeholder references such as:

- `BC: number from BC (placeholder)`
- `WS: number from Webshipper (placeholder)`

These placeholder labels are only UI references. They do not mean that live Business Central or Webshipper data was loaded.

External order enrichment is disabled by default to keep the demo stable and avoid slow loading when BC/Webshipper data is unavailable:

```env
SHOPIFY_ORDERS_LOAD_EXTERNAL_DATA=false
```

Set it to `true` only when real Business Central/Webshipper credentials are configured and live integration data should be loaded. Write/create behavior is described separately in [Test mode](#test-mode).

### User-facing error messages

The order UI should not show raw technical errors such as GraphQL messages, API response bodies, or permission stack details. API endpoints map these failures to short user-facing messages, for example missing Shopify permissions or data that could not be loaded.

The original technical exception is still kept for debugging through Laravel logs, Telescope, and activity logs where relevant.

### Test mode

The app runs in **test** or **production** mode based on `VITE_APP_STATUS` in `web/.env`:

- **Test (default)** – If `VITE_APP_STATUS` is unset or not exactly `Production` (case-insensitive), the app is in test mode. In test mode:
    - **Webshipper:** Label and return-label creation are disabled (no real API calls that create labels for now).
    - **Business Central:** Creating or updating order lines is disabled (no writes to BC).
    - Orders and read-only data from Shopify/Webshipper/BC can still be shown; only write/create actions are blocked.
- **Production** – Set `VITE_APP_STATUS=Production` to allow label creation and Business Central writes. Use this only when you intend to create real labels and update BC.
  The current mode is shown in the UI (in the navigation). Logic lives in `App\Services\AppStatus` (backend) and `resources/js/lib/app-status.ts` (frontend).

### On hold (Shopify tag)

**What it does**

- Lets you mark an order as **on hold** from the **Ready to pack**, **Ready for pickup**, and **Upcoming** tabs.
- Shows those orders on the **On hold** tab and add the tag to the order in Shopify.
- Lets you **remove** the on-hold tag from the **On hold** tab.

**How it works**

- The app uses Shopify’s order tag **`On hold`**. Adding or removing on hold updates that tag in Shopify.
- The **On hold** tab lists orders that have this tag, so the view matches Shopify and stays correct after a refresh.

### Ready to pack loading flow

- On page open, the app calls `GET /api/shopify/orders?view=ready-to-pack`.
- The API fetches open Shopify orders from the last 2 months, then filters to paid orders (`fetchOrdersReadyToPack`).
- The result is enriched with Business Central + Webshipper data before it is returned to the UI.
- Performance updates:
    - Short-lived cache is used for expensive enrichment calls (BC sales orders, expected receipt map, BC shipment dates, Webshipper orders).
    - Business Central purchase-order line fetches run in parallel chunks instead of one-by-one.
    - Webshipper orders load page 1 first, then remaining pages in parallel when needed.

## Laravel Horizon

USE THIS TO START THE HORIZON SERVER:
php artisan queue:work redis --queue=default --tries=3 --timeout=120

- **What it is:** Queue dashboard for Redis; shows pending, completed, and failed jobs.
- **URL:** `/horizon`. You must be logged in.
- **Access:** Controlled by the `viewHorizon` gate in `app/Providers/HorizonServiceProvider.php` (allowlisted users in non-local environments).
- **Retention:** See [Logs, retention & scheduled tasks](#logs-retention--scheduled-tasks).
- **Config:** `web/config/horizon.php`.

### Running the queue

Redis must be running. To process queued jobs (including email), run from `web/`:

```shell
php artisan horizon
```

### Email queue support

- The app sends two transactional emails (password reset, coworker invite); they are queued with Redis and processed by Horizon (`ShouldQueue` notifications).
- **Local tunnel note:** Reset links must use a publicly reachable URL. If a link fails, verify the generated email link host.

### Email flows

- **Coworker access:** A coworker can use the app only after a `super_admin` invites them. The super_admin sends an invite email; the coworker sets up their account (and password) via the link in that email.
- **Password reset:** An active user can request a password reset; the reset link is sent by email (queued and processed by Horizon).

## Laravel Telescope

- **What it is:** Debugging and monitoring dashboard (requests, exceptions, logs, queries, queued jobs).
- **URL:** `/telescope`. You must be logged in.
- **Access:** Controlled by the `viewTelescope` gate in `app/Providers/TelescopeServiceProvider.php`.
- **Enable/disable:** `TELESCOPE_ENABLED=true` or `false` in `web/.env`. When `false`, no data is recorded.
- **What’s recorded:** In `local` and `development`, all entries are stored. In production, the same applies; sensitive data (cookies, CSRF token) is hidden outside local/development.
- **Retention:** Entries are pruned daily by the scheduler. See [Logs, retention & scheduled tasks](#logs-retention--scheduled-tasks) for how long we keep data.
- **Config:** `web/config/telescope.php`.

## Spatie Activity Log

- **What it is:** Logs user and model activity (e.g. who did what, when) to the database.
- **Where it’s stored:** Database table `activity_log`.
- **Access:** No dashboard; data is queried via the `Activity` model or your own views/reports.
- **Enable/disable:** `ACTIVITY_LOGGER_ENABLED=true` or `false` in `web/.env` (see `web/config/activitylog.php`).
- **Retention:** Old records are removed daily by the scheduler. See [Logs, retention & scheduled tasks](#logs-retention--scheduled-tasks) for how long we keep data.
- **Config:** `web/config/activitylog.php`.

## Logs, retention & scheduled tasks

We limit how long we keep logs and other stored data so the app doesn’t run out of disk or database space. Below is what we keep, for how long, and where it’s configured and may be changed.

### What we keep and for how long

| Data                                                                | Kept for                                                                  | Where to change it                                                                                                |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| **Laravel log files** (`storage/logs/`)                             | 30 days                                                                   | `web/config/logging.php` → `daily` channel, `days`                                                                |
| **Spatie activity log** (DB table `activity_log`)                   | 2 years                                                                   | `web/config/activitylog.php` → `delete_records_older_than_days`                                                   |
| **Telescope entries** (DB)                                          | 30 days                                                                   | `web/routes/console.php` → `telescope:prune` (hours), or `web/config/telescope.php` if `prune_hours` is set there |
| **Horizon job lists** (Redis; recent/completed/failed in dashboard) | 30 days                                                                   | `web/config/horizon.php` → `trim` (values are in minutes)                                                         |
| **Shopify sessions** (DB table `sessions`)                          | Expired sessions are removed; sessions not updated in 30 days are removed | `web/routes/console.php` → “Shopify sessions cleanup” closure                                                     |
| **Failed jobs** (DB table `failed_jobs`)                            | 30 days                                                                   | `web/routes/console.php` → “Failed jobs cleanup” closure                                                          |

### How cleanup runs

Cleanup is done by the **Laravel scheduler** (defined in `web/routes/console.php`). The scheduler runs:

- **Telescope prune** – daily
- **Activity log clean** – daily
- **Shopify sessions cleanup** – daily
- **Failed jobs cleanup** – daily

Laravel’s **log files** are rotated automatically when the app writes logs (one file per day); no scheduled command is used for that.

### Checking what’s scheduled

To see which tasks are scheduled and when they run:

```shell
cd web
php artisan schedule:list
```

## Known constraints and limitations

- **Single store:** The app is currently built for one Shopify store and can be upscaled later for multi-store if needed.

## Linting

The project uses PHP_CodeSniffer (PSR-12). Before pushing, run from `web/`:

```shell
cd web
composer lint
```

Fix any reported issues so CI passes. Some violations can be auto-fixed with:

```shell
./vendor/bin/phpcbf --standard=PSR12 app routes
```
