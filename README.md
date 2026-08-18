# Pterodactyl Panel — Customized Fork

This is a customized fork of [Pterodactyl Panel](https://pterodactyl.io), the open-source game server management panel built with PHP (Laravel), React, and Go. The core panel — Docker-isolated game servers, a modern UI, node/location management, and the full admin toolset — is unchanged from upstream Pterodactyl.

On top of that core, this fork adds a **self-service billing and monetization layer**, letting users fund a wallet and instantly provision their own game servers without any manual admin intervention — turning the panel into a standalone SaaS hosting platform.

## What's different from upstream Pterodactyl

### Billing & Monetization

- **Wallet system** — every user has a wallet balance (`wallet_balance`) that can be topped up and spent on hosting plans.
- **Transactions ledger** — every deposit and charge is recorded (`pending` / `success` / `failed`) with a unique reference, so payment history is fully auditable per user.
- **Payment gateway integration**:
  - **CourtneyTech** M-Pesa STK Push for Kenyan mobile-money top-ups.
  - Paystack card payments, including server-side transaction verification.
  - Paystack webhook endpoint with **HMAC-SHA512 signature verification**.
  - Idempotent verify-and-credit flow wrapped in a database transaction, so a payment can never be credited twice.
- **Multi-currency support** — admins can define currencies with exchange rates relative to a base currency (KES by default), each toggleable active/inactive.
- **Resource pricing** — configurable per-unit pricing for CPU, memory, disk, etc., independent of fixed plans.
- **Hosting plans** — admin-defined packages (name, price, billing period, memory/disk/CPU/database/backup/allocation limits) tied to a specific egg and nest, with featured/active flags and custom sort order.

### Self-Service Server Provisioning

- Users browse active plans from their account dashboard and purchase directly from their wallet balance.
- On purchase, a server is **automatically provisioned** — no admin step required. If provisioning fails for any reason, the wallet is never charged.
- Successful purchases are logged as `charge` transactions tied to the resulting server.

### Account Registration

- Public self-registration is enabled (`/auth/register`), gated behind reCAPTCHA, so new users can create their own accounts rather than requiring an admin to create them.

### Configurable Panel Theme

- Admins can re-theme the entire panel from **Admin → Settings → Theme**, without touching code or rebuilding assets.
- Six built-in presets (Default, Black & White, Green & Black, Crimson Night, Purple Haze, Sunset Orange) or fully custom colors via a color picker with a live preview.
- Under the hood, a single neutral color and a single accent color are expanded into a full 50–900 shade ramp using a fixed lightness curve, then injected as CSS custom properties at runtime — so the change applies instantly across the whole panel (sidebar, buttons, links, badges) for every logged-in user.

### Refreshed Auth Pages

- Login, Register, Forgot Password, Reset Password, and Login Checkpoint all share a redesigned, more compact layout — a small centered logo above the form instead of a large logo dominating half the card, and a sensible max-width instead of stretching across large screens.

## Tech Stack

- **Backend**: PHP 8.2+/8.3, Laravel
- **Frontend**: React, TypeScript, Tailwind CSS
- **Payments**: CourtneyTech (Kenya M-Pesa) and Paystack (card payments)
- **Deployment**: Docker-isolated game servers via Wings, Nginx, Certbot, PM2 (or equivalent process manager) for the queue worker

## Installation

These commands assume Ubuntu 22.04/24.04, PHP 8.2 or 8.3, MySQL or MariaDB, Redis, Nginx, Node.js, and Composer are already installed. For the complete upstream server requirements, see the [official Pterodactyl installation guide](https://pterodactyl.io/panel/1.0/getting_started.html).

Clone the repository and install the backend dependencies:

```bash
git clone https://github.com/mzeeemzimanjejeje/pterodactyl-courtneytech.git
cd pterodactyl-courtneytech
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate --force
```

Configure the database, cache, mail, application URL, and queue settings in `.env`, then initialize the application:

```bash
php artisan migrate --seed --force
php artisan storage:link
php artisan config:cache
php artisan view:cache
```

Install and build the frontend assets:

```bash
corepack enable
pnpm install --frozen-lockfile
pnpm build
```

Set the correct ownership for the application directory and storage paths, then run the queue worker under Supervisor or another process manager:

```bash
sudo chown -R www-data:www-data /var/www/pterodactyl
sudo chmod -R 755 storage bootstrap/cache
php artisan queue:work --queue=high,standard,low --sleep=3 --tries=3
```

### Payment environment configuration

Add these variables to the production `.env` or deployment secret store. **Never commit live credentials to GitHub.**

```dotenv
PAYSTACK_PUBLIC_KEY=your_paystack_public_key
PAYSTACK_SECRET_KEY=your_paystack_secret_key
COURTNEY_BASE_URL=https://courtneytech.xyz/api
COURTNEY_API_KEY=your_courtney_api_key
COURTNEY_API_SECRET=your_courtney_api_secret
COURTNEY_ACCOUNT_ID=9
```

After changing payment variables, refresh Laravel configuration:

```bash
php artisan config:clear
php artisan config:cache
```

The Paystack Dashboard webhook URL is:

```text
https://YOUR_PANEL_DOMAIN/webhooks/paystack
```

Kenyan M-Pesa payments use CourtneyTech’s `/v2/stkpush` and `/v2/status` endpoints. Card payments use Paystack’s hosted inline card flow and server-side verification. International card acceptance depends on Paystack enabling international payments for the merchant account and supported KES settlement; verify that setting in the Paystack Dashboard.

### Updating the application

```bash
cd /var/www/pterodactyl
git pull origin main
composer install --no-dev --optimize-autoloader
pnpm install --frozen-lockfile
pnpm build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
sudo systemctl restart nginx
sudo supervisorctl restart pterodactyl-worker:*
```

## Documentation

Since the core panel is unmodified Pterodactyl, the official documentation still applies for anything not covered above:

- [Panel Documentation](https://pterodactyl.io/panel/1.0/getting_started.html)
- [Wings Documentation](https://pterodactyl.io/wings/1.0/installing.html)
- [Community Guides](https://pterodactyl.io/community/about.html)

## License

This project remains licensed under the [MIT License](./LICENSE.md), consistent with upstream Pterodactyl. Credit to [Dane Everitt](https://github.com/DaneEveritt), [Matthew Penner](https://github.com/matthewpi), and the Pterodactyl contributors for the original panel this fork is built on.
