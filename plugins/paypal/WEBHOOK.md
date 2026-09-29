# PayPal fixed webhook (opt-in)

The legacy `pay/webhook/<order>/` handler remains HTTP 503. It is not a fixed webhook address. No core plugin-loader fallback is introduced.

For each distinct merchant/channel/subchannel binding, register the HTTPS URL:

`https://YOUR-HOST/plugins/paypal/webhook.php?channel=CHANNEL_ID&uid=MERCHANT_UID&sub=SUBCHANNEL_ID`

Use `sub=0` for a normal channel. Subscribe to **PAYMENT.CAPTURE.COMPLETED** in the same PayPal application and environment as this binding's effective ClientId/ClientSecret. Set the resulting **Webhook ID** in the new `appsecret` channel input. For per-user/subchannel configurations, use the existing `[field]` substitution mechanism and store the ID with the corresponding credential set. Blank or unresolved IDs fail closed (503). A URL is public, not an authentication secret.

The endpoint validates its numeric binding, loads effective user/subchannel credentials, sends signature verification to the fixed PayPal API, and never downloads the event certificate URL locally. It then requires the event invoice to belong to the exact UID/channel/subchannel, exact amount/currency, COMPLETED capture, stored approval-URL token matching the PayPal order, and authenticated order-detail confirmation. Only persisted payment success is acknowledged. Bad signatures, cross-binding events, missing stored approval data, and mismatch states are not credited.

## Limits and deployment decisions

- This is capture-completion recovery, **not unattended capture**: orders only approved by a buyer still require the existing synchronous capture flow. Adding CHECKOUT.ORDER.APPROVED auto-capture needs a separately designed idempotent capture operation.
- Existing `ext` must contain the serialized PayPal approval URL. Legacy orders without it fail closed and need manual reconciliation.
- Amount and currency use current channel settings. Do not change currency/rate with outstanding orders; immutable order snapshots require separate persistence work.
- Shared PayPal apps receiving events for multiple merchant bindings can cause unrelated events to be delivered to each URL; these return 409 rather than being credited. Decide whether to provision separate apps/webhooks or design an app-level binding registry. PayPal webhook-count limits may make one URL per merchant impractical at large scale. No automatic registration or business configuration was performed.
- Core payment idempotency/atomic ledger behavior is a separate dependency. This endpoint uses the normal processNotify path and checks persisted status/capture ID; deployment must include the payment-core concurrency fixes.
- Real gateway verification and production payment acceptance have not been exercised. Tests use isolated fake PayPal responses, not actual signatures from PayPal.
- Central outbound helpers reject configured proxies and disable environment proxies to preserve DNS pinning. Repair CA/DNS/network configuration rather than disabling TLS or bypassing the guard.
