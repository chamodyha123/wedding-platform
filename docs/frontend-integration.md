# Frontend Integration Guide

This guide describes the existing Laravel API for a browser or JavaScript
client (React, Vue, or another framework). The OpenAPI 3.1 document at
[`openapi/openapi.yaml`](openapi/openapi.yaml) is the authoritative machine
contract. Use it for the full route inventory, schemas, and operation details.

## Base URL and request conventions

With `php artisan serve`, use:

```text
http://127.0.0.1:8000
```

API paths include the `/api` prefix. Send JSON and request JSON responses:

```http
Accept: application/json
Content-Type: application/json
```

For protected API routes, send the Sanctum personal access token returned by
registration, login, or an OAuth callback:

```http
Authorization: Bearer <access-token>
```

Treat the token as a secret. Do not put it in URLs, logs, analytics events, or
public frontend source. The API uses bearer tokens for ordinary authenticated
operations. Google/Facebook redirect flows are session-backed browser
redirects and are not initiated by attaching a bearer token to the provider
redirect route.

## Access levels

- **Public:** registration/login, password-reset endpoints, signed email
  verification link, OAuth redirect/callback routes, marketplace reads, and
  the PayHere server notification callback.
- **Authenticated:** `/api/auth/*` protected account operations and
  `/api/notifications/*`.
- **Customer:** `/api/customer/*` requires Sanctum authentication and the
  `customer` role. Users can access only their own profile, bookings,
  payments, and reviews.
- **Provider:** `/api/provider/*` requires Sanctum authentication and the
  `service_provider` role. Service, package, and availability operations are
  scoped to the authenticated provider's resources. Booking management also
  requires an active, verified provider.
- **Administrator:** `/api/admin/*` requires authenticated administrator
  permissions. Use the operations and permission requirements described in
  OpenAPI; admin privileges must not be inferred from a client-side role label.

The marketplace only exposes published services from active, verified
providers and active categories.

## Registration and login

Registration accepts customer or service-provider role values. Password
confirmation uses Laravel's `password_confirmation` field.

```http
POST /api/auth/register
Accept: application/json
Content-Type: application/json

{
  "name": "Example Customer",
  "email": "customer@example.test",
  "password": "<choose-a-strong-password>",
  "password_confirmation": "<choose-a-strong-password>",
  "role": "customer"
}
```

The successful response is HTTP 201 and contains `message`, `user`, and
`token`. This abbreviated response omits other serialized user and role fields:

```json
{
  "message": "Registration successful.",
  "user": {
    "id": 123,
    "name": "Example Customer",
    "email": "customer@example.test",
    "roles": [
      {
        "name": "customer"
      }
    ]
  },
  "token": "<token-returned-by-the-api>"
}
```

The numeric ID and token above are placeholders; a real response contains the
created user and assigned role. Store the returned token securely and send it
as a bearer token on protected requests. Login uses `POST /api/auth/login`
with `email` and `password`; it returns the same `message`, `user`, and
`token` response shape. `GET /api/auth/me` returns the authenticated `user`,
and `POST /api/auth/logout` revokes the current access token.

## Provider onboarding and verification

Register with role `service_provider`, authenticate, and create a business
profile with `POST /api/provider/profile`. The request requires `business_name`
and at least one `category_ids` entry; optional fields include description,
phone, WhatsApp, email, website, address, city, district, latitude, and
longitude. Manage provider categories at `/api/provider/categories`.

Provider profile submission is not itself administrator verification. An
administrator manages verification, and marketplace visibility and booking
management are restricted according to the provider's active and verified
state. Providers can use `/api/provider/dashboard` and their own profile
routes to inspect their account.

## Category and service discovery

Public discovery routes do not require a token:

- `GET /api/marketplace/categories`
- `GET /api/marketplace/categories/{slug}`
- `GET /api/marketplace/providers`
- `GET /api/marketplace/providers/{slug}`
- `GET /api/marketplace/services`
- `GET /api/marketplace/services/{slug}`
- `GET /api/marketplace/services/{slug}/reviews`

Example service search:

```http
GET /api/marketplace/services?category=photography&city=Colombo&min_price=10000&max_price=50000&min_rating=4&sort=rating_high&per_page=12&page=1
Accept: application/json
```

Service search supports `category`, `city`, `district`, `provider`, `search`,
`sort`, `featured`, `min_price`, `max_price`, `min_rating`, and `per_page`.
Provider search supports `category`, `city`, `district`, `search`, `sort`, and
`per_page`. Sort values are `newest`, `oldest`, `name_asc`, `name_desc`,
`rating_high`, and `rating_low`. `per_page` must be from 1 through 100; the
Laravel paginator also accepts `page`. For service search, `max_price` cannot
be lower than `min_price`.

Paginated provider and service searches use Laravel's paginator response,
including a `data` array and page/total metadata. Public service reviews return
`service`, `rating_summary`, and a paginated `reviews` object; review listing
accepts `rating` (1-5) and `per_page`.

### Pagination default discrepancy

The current runtime defaults to 12 items for provider and service search and
10 items for public service reviews. The existing OpenAPI file specifies 15
for these defaults. This guide does not alter that contract; clients should
pass an explicit `per_page` until the implementation and contract are
reconciled.

## Provider services, packages, and availability

An authenticated provider manages its own services under
`/api/provider/services`. Service packages are nested under
`/api/provider/services/{serviceId}/packages`; a package requires `name` and
`price`, and may include `description`, `duration_minutes`, and status.
Providers cannot feature their own packages directly.

Availability is managed under
`/api/provider/services/{serviceId}/availability`. A record includes `date`
and `status` (`available`, `unavailable`, `blocked`, or `booked`), with
optional `start_time`, `end_time`, and `notes`. Times use `HH:mm`. Full-day
records omit both times. Duplicate or overlapping records are rejected.

## Booking creation and lifecycle

Only a customer can create a booking. It must refer to a published service,
published package for that service, active verified provider, and an available
time slot that contains the requested interval. The requested event date
cannot be in the past. A provider-level overlapping pending, accepted, or
confirmed booking is rejected with HTTP 409.

```http
POST /api/customer/bookings
Authorization: Bearer <access-token>
Accept: application/json
Content-Type: application/json

{
  "service_id": 42,
  "service_package_id": 17,
  "event_date": "2030-06-15",
  "start_time": "10:00",
  "end_time": "13:00",
  "event_location": "Colombo",
  "customer_notes": "Please contact me before the event."
}
```

The response is HTTP 201 with `message` and `booking`; the new booking starts
with `booking_status: "pending"` and `payment_status: "unpaid"`. IDs and dates
here are examples only; use IDs and availability returned by the API.

Abbreviated response:

```json
{
  "message": "Booking created successfully.",
  "booking": {
    "id": 321,
    "booking_reference": "<booking-reference-returned-by-the-api>",
    "booking_status": "pending",
    "payment_status": "unpaid"
  }
}
```

The provider can accept or reject a pending booking through
`POST /api/provider/bookings/{id}/accept` or `/reject`. A customer can cancel
only a pending or accepted booking, using
`POST /api/customer/bookings/{id}/cancel` with a required
`cancellation_reason`. The provider can complete a confirmed booking on or
after its event date using `POST /api/provider/bookings/{id}/complete`.
Booking reads are scoped to the authenticated customer or provider. Check the
operation responses in OpenAPI for the complete booking representation.

## Payment and PayHere checkout

Only the owning customer may initiate payment, and only for an accepted,
unpaid booking. First request payment initiation:

```http
POST /api/customer/bookings/{bookingId}/payments
Authorization: Bearer <access-token>
Accept: application/json
Content-Type: application/json

{
  "payment_method": "card"
}
```

For `card`, the response includes a payment and PayHere `checkout` information
(`checkout_url` and `payload`) when PayHere is configured. Continue checkout
using the returned gateway URL and payload; do not build a signature or
substitute client-provided payment amounts. The backend creates the amount
from the booking total. Payment status can be retrieved with
`GET /api/customer/payments/{id}`; providers have their own payment read
routes. The API also accepts `payment_method: "bank_transfer"`; that option
creates a payment record without a PayHere checkout payload.

The PayHere notify endpoint, `POST /api/payments/payhere/notify`, is a
server-to-server callback, not a frontend success endpoint. The backend
validates the callback signature and the trusted order, amount, currency, and
transaction information. A confirmed payment transitions the booking to
`confirmed`; duplicate or stale notifications do not repeat payment state
transitions.

Development/testing mock payment success, failure, and cancellation routes
are not real transactions and are only enabled in local/testing environments.
Never use mock success in production. Refunds are not documented as an
implemented capability.

## Reviews

Customers create reviews with `POST /api/customer/bookings/{bookingId}/reviews`
only for their own completed booking, and only one review can be created per
booking. Customer review routes support listing, viewing, updating, and
deleting that customer's reviews. Public readers can fetch service reviews at
`/api/marketplace/services/{slug}/reviews`, optionally filtering by rating.
Providers can read reviews associated with their business.

## Notifications

Authenticated users can list notifications at `GET /api/notifications` and
unread notifications at `GET /api/notifications/unread`. Both accept
`per_page` (1-100; default 15) and return notification data with pagination
metadata. Mark one as read with `POST /api/notifications/{id}/read`, or all
unread notifications with `POST /api/notifications/read-all`. Notification
ownership is enforced by the backend.

## Email verification and OTP flows

Email verification can use the signed link sent by
`POST /api/auth/email/verification-notification`, or the authenticated
registration OTP endpoints:

- `POST /api/auth/email/otp/send`
- `POST /api/auth/email/otp/verify` with a six-digit `code`

The signed link uses `GET /api/auth/verify-email/{user}/{hash}` and must retain
its valid signature; do not construct or alter the URL.

Password reset and password change are distinct:

- Public reset OTP issuance: `POST /api/auth/password/otp/send` with `email`.
- Public reset using OTP: `POST /api/auth/password/otp/reset` with `email`,
  six-digit `code`, `password`, and `password_confirmation`.
- Authenticated change OTP issuance:
  `POST /api/auth/password/change/otp/send` with `current_password`.
- Authenticated password change: `PUT /api/auth/password` with
  `current_password`, six-digit `code`, `password`, and
  `password_confirmation`.

Password reset also supports Laravel's email-link endpoints
`POST /api/auth/forgot-password` and `POST /api/auth/reset-password`.
Registration, reset, and password-change OTPs are isolated by purpose. Each
OTP is six digits, expires after 10 minutes, has a 60-second resend cooldown,
and allows up to five verification attempts. These values are currently
application configuration, not frontend-adjustable settings. Never log or
persist OTP values in the frontend.

## Google and Facebook OAuth

Start OAuth by navigating the browser to
`GET /api/auth/google/redirect` or `/api/auth/facebook/redirect`. The backend
redirects to the configured identity provider and processes the provider
callback at the configured backend callback URL. The callback returns a JSON
authentication response on success; it is not a custom frontend redirect
containing a token. Google and Facebook credentials and callback URLs must be
configured server-side. Authenticated users can link identities through
`/api/auth/google/link` and `/api/auth/facebook/link`.

OAuth requires provider-side app configuration and valid callback URLs.
Facebook email is not treated as verified by this implementation. Handle
callback errors, state expiry, and account-linking conflicts explicitly.

## Validation, authorization, and rate limits

Typical API error responses include:

- **401 Unauthorized** — missing or invalid bearer token on a protected route.
- **403 Forbidden** — authenticated user lacks the required role, permission,
  ownership, or provider state.
- **404 Not Found** — requested resource is missing or outside the caller's
  accessible scope.
- **409 Conflict** — for example, an overlapping booking or active payment
  attempt.
- **422 Unprocessable Content** — validation failure or a disallowed state
  transition. Laravel validation responses include `message` and field-keyed
  `errors`.
- **429 Too Many Requests** — route throttle exceeded. Applicable limits are
  configured per route and, for several auth flows, by the environment
  variables listed in the root README; OTP route throttles also exist.

Validation response shape:

```json
{
  "message": "<validation summary>",
  "errors": {
    "email": [
      "<field validation message>"
    ]
  }
}
```

Always render server-provided field errors and do not treat a client-side role
check as authorization. The backend enforces role, ownership, provider status,
booking state, and validation rules.

## Security notes and limitations

- Keep PayHere merchant credentials, OAuth secrets, and all other credentials
  on the server. Never place secrets in JavaScript bundles or browser storage.
- PayHere callbacks are verified by backend logic; browser redirects alone do
  not prove payment.
- Local/testing mock payments do not move money and are not production payment
  behavior.
- OTP codes are purpose-specific and short-lived. Do not reuse a password
  reset code for registration verification or password change.
- OAuth depends on provider-side configuration and valid callback URLs.
- Use HTTPS and deployment-appropriate secret management outside local
  development. This guide does not assert production readiness.
- Refund operations are not described as implemented.

## Contract maintenance

When API behavior changes, compare this guide and frontend requests with
[`openapi/openapi.yaml`](openapi/openapi.yaml), runtime routes, validation,
controllers, and tests. The OpenAPI contract is authoritative; report any
contract/runtime mismatch and reconcile it deliberately rather than silently
relying on an inaccurate default.
