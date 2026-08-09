# CourtneyTech API findings

Source: https://courtneytech.xyz/documentation (accessed 2026-08-09)

- Base URL: https://courtneytech.xyz/api/v2
- Every request requires HTTP headers `X-API-Key`, `X-API-Secret`, and `Content-Type: application/json`.
- STK Push: POST `/stkpush`
- Required request fields include `payment_account_id`, `phone`, `amount`, `reference`, `description`, and optional/per-request callback URLs including `callback_url`, `success_callback_url`, and `confirmation_url`.
- Status polling: POST `/status` with `checkout_request_id`.
- Status values include `pending`, `completed`, `cancelled`, and `failed`.
- Official landing page example uses phone format `254712345678` without a plus sign.
- Callback events include `payment.stk_callback`, `payment.success`, `payment.failed`, `payment.cancelled`, and `payment.confirmed`.
- The current repository has a wallet flow in `WalletController` that creates pending transactions, calls Paystack for M-Pesa, polls verification, and handles a Paystack webhook.
- The repository does not currently contain CourtneyTech integration.

Implementation implication: Kenya mobile-money payments need CourtneyTech credentials, a CourtneyTech payment account ID, and callback/status handling. The supplied Paystack secret must remain an environment variable and must not be committed.

## Verification references added 2026-08-09

- Official CourtneyTech docs: https://courtneytech.xyz/documentation
- CourtneyTech documents the STK response field as `checkout_request_id`, not `checkoutRequestId`.
- CourtneyTech recommends polling `/v2/status` at least every 5 seconds, with `status` values `pending`, `completed`, `cancelled`, and `failed`.
- CourtneyTech documents `amount` in the STK request and `payment_account_id` as the routing identifier.
- Official Paystack verification docs: https://paystack.com/docs/payments/verify-payments/
- Official Paystack webhook docs: https://paystack.com/docs/payments/webhooks/
- Paystack verification uses `response.data.status` and the amount is in the smallest currency unit; webhook signatures are HMAC-SHA512 using the secret key.
- The current repository’s Paystack webhook method is not registered in `routes/base.php`.
- The current CourtneyTech implementation reads `checkoutRequestId`, so it does not match the documented `checkout_request_id` response field.
