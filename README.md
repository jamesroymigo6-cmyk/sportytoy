# Sporty Ni Migo v11 — Consolidated & Hardened

Sporty Ni Migo is a sports event operations platform for Tupi, South Cotabato: event planning with venue and equipment reservations, an equipment/merchandise shop with cart and checkout, community wall, announcements with optional urgent SMS, and role-secured administration.

## What changed in v12 — public landing page & Clerk email authentication

### Public landing page (`landing.php`)
- A full marketing front door: sticky glass navbar, animated hero with gradient headline and live trust badges, eight feature cards, a three-step "how it works", the real Tupi venue cards, a FAQ, a closing CTA, and a footer.
- Dark "stadium night" theme (`assets/css/landing.css`) with scroll-reveal animations, floating sports orbs, and full `prefers-reduced-motion` support.
- Signed-in members skip it and land on `index.php`; everyone else gets Sign in / Create account CTAs into the auth pages.

### Clerk email authentication (optional, zero-dependency)
- Sign-in and sign-up run on **Clerk components** (ClerkJS 5 via CDN) with verified email codes — no Composer packages needed.
- Server-side session tokens are **verified against your instance JWKS** (RS256 via OpenSSL, 10-minute cached keys) in `config/clerk.php`; provisioning keeps working through Clerk's REST Backend API via cURL.
- Verified emails auto-provision or auto-link the matching local account, so **every existing role, capability, and feature keeps working unchanged** — the app's session/CSRF/role layer is untouched.
- Classic email+password login remains as an automatic fallback (it takes over when Clerk is unconfigured, the CDN is blocked, or the component fails to mount), so lockouts stay impossible.
- `auth/clerk_webhook.php` (Svix-signed) keeps local accounts in sync with Clerk create/update/delete events.
- New `users.clerk_id` column (added in place by `config/schema.php`; already present in `database/sports_events.sql` for fresh installs).

### Setup
1. Create an application at [dashboard.clerk.com](https://dashboard.clerk.com).
2. In `.env`, set `CLERK_PUBLISHABLE_KEY`, `CLERK_SECRET_KEY`, `CLERK_FAPI_URL` (your Frontend API URL).
3. Add a webhook in Clerk pointing to `https://YOUR-HOST/auth/clerk_webhook.php` and set `CLERK_WEBHOOK_SECRET`.
4. That's it — refresh the app. Leave the keys empty to stay on classic auth.

## What changed in v11

## What changed in v11

### UI/UX fixes
- Administrators can now open **Inventory**, **Participants**, and **Equipment** pages (previously blocked by a wrong role check) and the admin **Data Management** page appears at the bottom of the sidebar.
- Stat cards auto-fit the row width (no stretching when the admin-only card appears).
- Payment method picker, cart rows, contact list, notice cards, and the SMS switch are properly styled, including dark theme.
- Calendar "today" highlight fixed (day 11+ showed day 1 as today).
- Add-to-cart no longer breaks buttons that open the cart panel; dead travel-helper code removed; focus outlines and reduced-motion support added.

### Database: 33 tables → 15
| Consolidated | Replaces |
|---|---|
| `users` (+reset columns) | users, password_reset_tokens |
| `events` (+schedule/venue) | events, venue_bookings |
| `event_registrations` | participants, registrations |
| `items` (kind=supply/product) | inventory, merchandise |
| `orders` (+items_json) | orders, order_items, carts, cart_items, sales |
| `messages` (sender NULL = system; type voice) | messages, notifications, voice_messages |
| `post_interactions` (type=comment/like) | comments, likes |
| `tournaments` (+bracket_json) | tournaments, teams, matches |
| `activity_logs` | activity_logs, sms_logs |
| `roles`, `venues`, `equipment`, `event_equipment_reservations`, `announcements` | unchanged |
| *removed (unused)* | system_settings, weather_alerts, equipment_assignments, inventory_transactions |

The shopping cart now lives in the browser session, so carts can never be orphaned or break a second checkout (a real bug in v10/v10.1).

### Every feature kept
Event planning with conflict-checked venue + equipment reservations, reschedule/cancel with automatic reservation release, registration + approval, shop CRUD, cart + checkout (Cash/GCash/Card — GCash in manual reference-verification mode or fully automated via PayMongo), order status management, GCash payment verification queue for managers, inventory with stock in/out, community posts with photos, likes, comments, announcements with audience targeting and optional SMSGate SMS, direct + voice messaging, notifications, CSV/XLS/Print exports, password reset, brute-force lockout, CSRF protection on every POST, and role-based access for all five roles.

## GCash merchandise payments

Configure in `.env` (see `.env.example`):

- **Manual mode (default)** — set `GCASH_MODE=manual`, `GCASH_NUMBER=09...`, and optionally `GCASH_ACCOUNT_NAME`. At checkout the customer sees the shop's GCash number and exact amount, sends the money in the GCash app, and enters the receipt reference number. The order is stored as **verifying**; managers confirm it under **Shop → GCash payments → Mark paid** (or **Reject** if the money never arrived, which flags the order as payment failed and notifies the customer).
- **Automatic mode** — create a PayMongo account (Philippine GCash aggregator), copy the secret key into `PAYMONGO_SECRET_KEY`, and set `GCASH_MODE=auto`. Checkout creates a PayMongo Checkout Session limited to GCash and redirects the customer; the order confirms itself the moment PayMongo reports the payment as paid (checked on return and whenever the shop page loads). Use `sk_test_...` keys and PayMongo test payments first; live mode requires HTTPS.
- Leave both `GCASH_NUMBER` and `PAYMONGO_SECRET_KEY` empty to hide GCash from checkout entirely.

Database note: `gcash_payouts` and `orders.payment_verified_at` are created automatically by the self-healing schema on first run after deploy.

## Fresh installation

1. Copy the project to your web root (e.g. `C:/xampp/htdocs/sportytoy`).
2. Import **only** `database/sports_events_full.sql` (all tables used by the current build):
   ```
   mysql -u root -p < database/sports_events_full.sql
   ```
3. Copy `.env.example` to `.env` and set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (and `APP_URL` in production).
4. Browse to the app and sign in.

### Demo accounts — password `Sporty Ni Migo2026!` (administrator: `Admin@123`)
| Role | Email |
|---|---|
| Administrator | admin@sports.local |
| Event Organizer | organizer@sports.local |
| Staff/Coordinator | staff@sports.local |
| Participant/Athlete | athlete@sports.local |
| Spectator/Community Member | viewer@sports.local |

## Upgrading from v9/v10/v10.1 (existing database)

Do **not** import anything. Open the app once while using a database account that may `ALTER` and `DROP` tables: `config/schema.php` migrates the old 33-table database **in place**, preserving users, events, registrations, orders (with item snapshots), messages, notifications (as system messages), community content, tournament brackets, and SMS history — then drops the merged legacy tables. If your host forbids `DROP`, back up and import `database/sports_events_full.sql` on a fresh database instead.

`database/legacy_v10_schema.sql` is kept only as a reference for the old layout.

## Troubleshooting

- Errors are logged privately to `storage/logs/app.log`; users see safe messages with a reference code.
- `health.php` shows database connectivity; `production_check.php` (admin only) validates the deployment.
- SMS requires `SMS_PROVIDER=smsgate` plus `SMSGATE_API_URL`, `SMSGATE_USERNAME`, `SMSGATE_PASSWORD`, `SMS_SENDER` in `.env` (https://api.sms-gate.app cloud API; messages are dispatched by an Android device registered to the SMSGate account); hourly reminders run via `cron/send_event_reminders.php` (CLI or `?token=` URL cron with `CRON_TOKEN` set).
- If a write action fails after a database re-import, sign out and sign in again — the session is validated against the live database on every request.

### Locked out, or the password is refused for every value you try

- **Locked out.** Five failed attempts lock an account for 15 minutes. The window is cleared automatically the moment it expires, so you get five fresh attempts rather than being re-locked by a single typo. If you want to unlock immediately:
  ```
  UPDATE users SET failed_login_attempts=0, locked_until=NULL WHERE email='you@example.com';
  ```
- **Every value is refused.** Run this check. A row that prints `NOT A HASH` can never sign in with any password, because `password_verify()` cannot match a value that was not produced by `password_hash()`:
  ```
  SELECT email, LEFT(password_hash,7) FROM users;
  ```
  Any value that does not start with `$2y$`, `$2a$`, `$2b$` or `$argon2` is plain text that was written to the column by mistake (a hand-edited `UPDATE`, or an old import). The application repairs this by itself: on the next page request `config/schema.php` re-hashes the stored value, clears the lockout, and logs what it did to `storage/logs/app.log`. The password then becomes whatever that column literally contained — set it to the documented demo password with `forgot_password.php` if you would rather not know the old one.

### One-file schema
`database/sports_events_full.sql` contains every table the current build uses, including the columns added by `config/schema.php`. Use it for a clean install or to inspect the full current schema in one place. Existing databases are still upgraded in place by `config/schema.php`.
- **Times look wrong.** PHP and the MySQL session both follow `APP_TIMEZONE` (default `Asia/Manila`); `config/db.php` derives the database session offset from it. Set `APP_TIMEZONE` in `.env` if you deploy in another zone, and leave the server's own `php.ini` zone alone.
#   s p o r t y t o y  
 