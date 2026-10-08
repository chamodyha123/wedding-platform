# Wedding Marketplace Backend

Laravel API backend for a wedding-services marketplace. Customers can discover
providers and services, request bookings, pay for accepted bookings, and leave
reviews. Service providers manage their business profiles, services, packages,
availability, and bookings. Administrators manage marketplace content and
provider verification.

## Roles

- **Customer** — manages a customer profile, bookings, payments, and reviews.
- **Service provider** — manages a business profile, services, packages,
  availability, and received bookings. Provider visibility and booking
  management depend on the provider's active and verification status.
- **Administrator** — manages categories, customers, providers, services,
  bookings, reviews, verification, and reports.

## Technology

- PHP `^8.3`
- Laravel Framework `^13.17` (project runtime observed: 13.30.1)
- PostgreSQL for the local environment template
- Laravel Sanctum `^4.0` for API bearer tokens
- Laravel Socialite `^5.31` for Google and Facebook sign-in
- Spatie Laravel Permission `^8.3` for roles and permissions
- PHPUnit `^12.5` for automated tests

Composer is the source of dependency constraints; installed patch versions may
differ between environments. For a PostgreSQL setup, PHP needs PDO with the
`pdo_pgsql` driver. The application also uses BCMath for payment amount
comparison. Check the active PHP installation with `php -m` and
`composer check-platform-reqs`.

## Implemented features

- Customer and service-provider registration, login, logout, and current-user
  lookup using Sanctum tokens.
- Customer profiles and service-provider business profiles, category
  assignments, and administrator-managed verification.
- Public categories, provider and service discovery, filtering, sorting,
  pagination, and public service reviews.
- Provider-owned services, packages, and availability records.
- Customer booking requests, provider acceptance or rejection, customer
  cancellation, and provider completion.
- Reviews and ratings associated with eligible customer bookings.
- Payment records, PayHere checkout and server callback handling, and payment
  reconciliation. Local/testing mock payment confirmation is not a real
  transaction.
- Authenticated notifications.
- Signed email verification, registration email OTP, password-reset OTP,
  authenticated password-change OTP, and Laravel password-reset links.
- Google and Facebook OAuth login and account linking.
- Administrator operations for marketplace entities and reports.

Feature availability and authorization are defined by the runtime routes and
controllers. This README does not imply that the application is production
ready.

## Prerequisites

- PHP 8.3 or newer within the Composer constraint `^8.3`.
- Composer.
- PostgreSQL for the database configuration used by `.env.example`.
- PHP PDO with the `pdo_pgsql` driver for PostgreSQL, plus BCMath for payment
  amount comparison. Composer-managed platform requirements can be checked
  with `composer check-platform-reqs`.

## Local setup (Windows PowerShell)

From the directory where you keep projects:

```powershell
git clone https://github.com/chamodyha123/wedding-platform.git
Set-Location .\wedding-platform
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a PostgreSQL database and database user with PostgreSQL or pgAdmin. Edit
`.env` and set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, and `DB_PASSWORD` for that database. The example file selects
PostgreSQL and port 5432; it intentionally leaves the database name and
credentials for the developer to configure.

Run the schema migrations:

```powershell
php artisan migrate
```

Seeding is optional. `DatabaseSeeder` creates the application roles,
permissions, and service categories. Its admin seeder creates an account only
in `local` or `testing` when both `ADMIN_EMAIL` and `ADMIN_PASSWORD` are set.
Configure a disposable local admin password in your own `.env` before seeding
if you need that account; never commit it.

```powershell
php artisan db:seed
```

Start the local server:

```powershell
php artisan serve
```

The API base URL with the default local server is `http://127.0.0.1:8000`.
For email-dependent flows, configure a development mail transport; the
`.env.example` defaults to the log mailer, which writes messages to application
logs rather than sending them to a real mailbox.

## Environment configuration

Use `.env.example` as the local template and the `config/` files as the
authoritative configuration mapping. Set private credentials only in a local
or deployment environment; do not copy secrets into source code or frontend
bundles.

| Area | Environment variable names | Purpose |
| --- | --- | --- |
| Application | `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Application identity, encryption key, debug behavior, URL, and locales. Use `APP_DEBUG=false` outside local development. |
| PostgreSQL | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection and credentials. The supplied template selects `pgsql`. |
| Admin seeding | `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Optional local/testing admin account used by the database seeder. Do not treat these as production bootstrap credentials. |
| Mail | `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Outbound mail transport and sender identity for verification and OTP messages. |
| Sanctum | `SANCTUM_EXPIRATION` | Personal access-token lifetime in minutes; the config default is 1440. `SANCTUM_STATEFUL_DOMAINS` is also read by Sanctum configuration for stateful domains but is not present in `.env.example`. |
| Login and security throttles | `LOGIN_RATE_LIMIT`, `REGISTER_RATE_LIMIT`, `EMAIL_VERIFICATION_RATE_LIMIT`, `PASSWORD_RESET_RATE_LIMIT`, `PASSWORD_RESET_SUBMISSION_RATE_LIMIT` | Per-minute limits used for their corresponding routes. |
| OAuth throttles and credentials | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GOOGLE_AUTH_RATE_LIMIT`; `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `FACEBOOK_REDIRECT_URI`, `FACEBOOK_AUTH_RATE_LIMIT` | OAuth app configuration, callback URLs, and per-minute authentication limits. |
| PayHere | `PAYHERE_MODE`, `PAYHERE_MERCHANT_ID`, `PAYHERE_MERCHANT_SECRET`, `PAYHERE_RETURN_URL`, `PAYHERE_CANCEL_URL`, `PAYHERE_NOTIFY_URL`, `PAYHERE_CURRENCY` | Selects sandbox/live checkout and configures merchant identity, callback URLs, and currency. Keep credentials server-side. |

The example also contains ordinary Laravel runtime settings such as
`SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`,
`SESSION_DOMAIN`, `QUEUE_CONNECTION`, `CACHE_STORE`, `FILESYSTEM_DISK`,
`LOG_CHANNEL`, and `LOG_LEVEL`. Redis, AWS, and other optional integrations
have configuration entries, but they are not required for the default local
PostgreSQL setup.

OTP policy is currently configured in `config/account_security.php`, not
through environment variables: codes are six digits, expire after 10 minutes,
have a 60-second resend cooldown, and allow up to five verification attempts.
Registration, password-reset, and password-change OTPs have separate purposes.

PayHere checkout uses the configured merchant ID and secret, mode, URLs, and
currency. The example also contains `PAYHERE_APP_ID` and `PAYHERE_APP_SECRET`;
the current checkout/signature implementation does not use those two values.

## API documentation

- [Authoritative OpenAPI 3.1 contract](docs/openapi/openapi.yaml)
- [OpenAPI viewing and maintenance notes](docs/openapi/README.md)
- [Frontend integration guide](docs/frontend-integration.md)

The OpenAPI document is the machine-readable API contract and covers the API
operations under `/api`. It can be opened in an OpenAPI 3.1-compatible viewer
or used by client-generation tooling. Compare it with `php artisan route:list
--json` when API routes change.

## Tests

Run the PHPUnit suite from PowerShell:

```powershell
php .\vendor\bin\phpunit --do-not-cache-result
```

The documented baseline is **210 tests, 1,383 assertions, 0 failures**. Test
counts can change as tests are added or removed.

## Development workflow

Develop on `dev-chamoth` and submit changes for review through a pull request
to `main`:

```text
dev-chamoth -> pull request -> main
```

Do not assume direct pushes to `main` are part of the project workflow.

## Existing Thunder Client collections

Thunder Client collections in [`thunder-tests/`](thunder-tests/) are useful
for manually exercising selected requests. They are not a complete API schema;
the OpenAPI contract remains authoritative. Keep maintained collection
requests aligned with the routes and schemas in OpenAPI.
