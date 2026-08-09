# Payment gateway setup

The wallet now sends Kenyan M-Pesa top-ups through CourtneyTech and continues to use Paystack for card top-ups. The application reads all credentials from environment variables; no live key belongs in source control.

Set the following variables in the deployment environment:

```dotenv
PAYSTACK_PUBLIC_KEY=your_paystack_public_key
PAYSTACK_SECRET_KEY=your_paystack_secret_key
COURTNEY_BASE_URL=https://courtneytech.xyz/api
COURTNEY_API_KEY=your_courtney_api_key
COURTNEY_API_SECRET=your_courtney_api_secret
COURTNEY_ACCOUNT_ID=9
```

`COURTNEY_ACCOUNT_ID=9` is the numeric `payment_account_id` supplied for this deployment. It is not the account UUID; verify it remains active with CourtneyTech’s `GET /v2/accounts` endpoint. CourtneyTech requests use the `X-API-Key` and `X-API-Secret` headers and the `/v2/stkpush` and `/v2/status` endpoints, as documented at https://courtneytech.xyz/documentation.

## Paystack webhook

The repository now exposes the signed Paystack webhook at:

```text
https://YOUR_PANEL_DOMAIN/webhooks/paystack
```

Add that exact HTTPS URL in the Paystack Dashboard developer settings. The endpoint validates the `X-Paystack-Signature` HMAC before verifying the transaction server-side, and duplicate wallet crediting is prevented by a database row lock.

Paystack card payments can be made by customers outside the merchant’s country only when international card payments are enabled for the Paystack business account and the selected currency/payment method is supported for that account. The frontend currently initializes the card popup in KES; confirm in the Paystack Dashboard that KES international card acceptance and settlement are enabled before advertising it to foreign customers.

After changing environment variables, clear the Laravel configuration cache and run the new database migration:

```bash
php artisan config:clear
php artisan migrate --force
```

The supplied Paystack secret should be placed in `PAYSTACK_SECRET_KEY` only in the deployment secret store or local `.env` file. It was intentionally not written to this repository or the delivered archive.
