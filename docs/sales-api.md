# Tarazu sales feed: guide for Nirani's software team

Tarazu takes Nirani's sales straight from your sales system. Every invoice you send updates each mill's sold quantity, average price and balance quota for the month, and the owner's Sales report.

## How to send sales

Send an HTTPS `POST` request to:

```
https://tarazu.live/nirani/api.php?op=sales_push
```

Headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Tarazu-Sales-Key` | the key AgriMandi gives you (keep it secret; it is not a user password) |

Body:

```json
{
  "client": "nirani",
  "sales": [
    {"ref": "INV-2026-1001", "date": "2026-11-14", "mill_code": "67001", "qty": 1500, "rate": 3925, "grade": "M/30", "buyer": "Bengaluru trader"},
    {"ref": "INV-2026-0998", "cancelled": true}
  ]
}
```

| Field | Required | Meaning |
|---|---|---|
| `ref` | Yes | Your invoice or dispatch number. Unique per sale, up to 80 characters. |
| `date` | Yes | Sale date as `YYYY-MM-DD`. |
| `mill_code` | Yes | The mill's code as it appears in Tarazu's mill list (for example `67001`). |
| `qty` | Yes | Quantity in **quintals**. |
| `rate` | Yes | Price in **rupees per quintal**, before GST (between 1,000 and 20,000). |
| `grade` | No | Defaults to `M/30`. |
| `buyer` | No | Shown to the owner in reports. |
| `cancelled` | No | `true` removes a sale you sent earlier (only `ref` is needed). |

## Rules

- **Sending the same `ref` again updates that sale; it is never counted twice.** It is safe to resend a whole day or week.
- At most **1,000 sales per request**. Split larger batches.
- About **240 requests an hour** per server. Every few minutes, or once an hour, is plenty.
- **Bad rows are skipped and explained; good rows in the same request are still saved.**

## Reply

```json
{"ok": true, "received": 2, "saved": 1, "removed": 1, "skipped": []}
```

`skipped` lists any row that was not saved, with its `ref` and the reason, e.g. `unknown mill_code` or `date must be YYYY-MM-DD`.

| Status | Meaning |
|---|---|
| 200 | Processed. Check `skipped`. |
| 400 | The request itself is wrong (unknown client, empty list). |
| 401 | Missing or wrong key. |
| 413 | More than 1,000 sales in one request. |
| 429 | Too many requests. Wait and retry. |
| 503 | The feed is not switched on yet. |

## For AgriMandi: switching it on

1. Generate a long random key, for example `openssl rand -hex 32`.
2. Add it to the GitHub repository secrets as `SALES_KEY`, run **Deploy web**, and give the key to Nirani's team over a private channel.
3. To revoke access, change the secret and deploy again.

Every push is recorded in the activity log (`sales_push`, or `sales_push_denied` for a wrong key).
