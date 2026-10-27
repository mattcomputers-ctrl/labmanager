# Chroma read-only API

Chroma exposes a small, read-only JSON API so Precision Ink's other systems (Insights, the SDS system, the future ERP) can look up codes and project status without touching the database directly. Nothing can be created or changed through it.

- Base path: `http://<chroma-host>/api/v1`
- Authentication: a bearer token, sent as `Authorization: Bearer chroma_…`. Tokens are created and revoked under **Admin → API tokens**; the plaintext is shown once. The token is read from the header only (never from the query string, so it cannot leak into web-server logs, browser history or Referer headers).
- Every response under `/api/v1` is JSON, whatever `Accept` header you send, including a wrong path or a write method. Lists use the envelope `{"data": [...], "page": 1, "per_page": 50, "total": 123}`; a single object uses `{"data": {...}}`; a failure is `{"error": "plain-language message"}` with status `401` (no or bad token), `404` (not found or no such endpoint), `405` (the API is read-only: only `GET`/`HEAD`), `422` (a bad parameter) or `500`.
- Pagination: `page` (default 1) and `per_page` (default 50, maximum 200).
- Dates: `date_assigned`, `date_submitted`, `date_completed` and `target_date` are plain calendar dates (`YYYY-MM-DD`). `created_at` / `updated_at` are UTC (`YYYY-MM-DD HH:MM:SS`).
- Confidential R&D projects are never returned, and codes never say which confidential project they belong to.
- No rate limit is applied; be reasonable (the server is a small box in the lab).

## Codes

### `GET /api/v1/codes?q=…`

Search sell numbers. `q` accepts anything the app accepts:

- a sell number in any historical spelling: `Y1714UV`, `y-1714uv`, `Y 1714`, `b2978uv` (prefix match: `y17` finds every Y17xx code);
- an E number (`E6374`, `6374`, `E6374-10`): returns every sell number linked to it;
- free text, matched against descriptions: every word must appear (prefix-matched, so `pant 30` finds "Pantone 300"). Text that happens to end in digits, like `pantone 300` or `blue 072`, is free text unless its letters are a real prefix (`y 1714` is the sell number Y1714). `%` and `_` are ordinary characters, not wildcards.

Leave `q` empty to page through every sell number. Add `exclude_voided=1` to leave out voided codes.

```bash
curl -s -H "Authorization: Bearer $CHROMA_TOKEN" \
  "http://chroma.local/api/v1/codes?q=y-1714uv"
```

```json
{
  "data": [
    {
      "sell_number": "Y1714UV", "prefix": "Y", "number": 1714, "suffix": "UV",
      "internal_code": "E9087", "internal_code_voided": false,
      "description": "Blister Pantone 109 Yellow", "customer": "Example Packaging",
      "resale": false, "vendor_name": null, "vendor_code": null,
      "date_assigned": "2026-10-20", "created_at": "2026-10-20 15:02:11", "updated_at": "2026-10-20 15:02:11",
      "voided": false, "project_number": 104
    }
  ],
  "page": 1, "per_page": 50, "total": 1
}
```

### `GET /api/v1/codes/sell/{code}`

One sell number (any spelling) with its notes and every alias (other sell numbers that share its E number).

```bash
curl -s -H "Authorization: Bearer $CHROMA_TOKEN" "http://chroma.local/api/v1/codes/sell/X104043"
```

```json
{ "data": { "sell_number": "X104043", "internal_code": "E9001", "description": "Security red", "aliases": [ { "sell_number": "R2156", "description": "Security red (relabel)", "date_assigned": "2026-03-02", "voided": false, "reason": "relabel" } ], "notes": "Same E#, new formula version", "legacy_text": null, "reason": null, "void_reason": null, "...": "same fields as the search" } }
```

### `GET /api/v1/codes/internal/{code}`

One E number: `E6374-10`, `6374-10` or just `6374`. Without a version, the base formula (no version) is returned if it exists, otherwise the first version on file; `versions` lists every version of that number so the caller can ask for a specific one. `sell_numbers` holds every sell number that points at it.

```bash
curl -s -H "Authorization: Bearer $CHROMA_TOKEN" "http://chroma.local/api/v1/codes/internal/E6374-10"
```

```json
{
  "data": {
    "internal_code": "E6374-10", "number": 6374, "version": "10", "versions": ["E6374-10", "E6374-12"],
    "description": "Process blue", "customer": "Example Labels", "resale": false,
    "vendor_name": null, "vendor_code": null, "legacy_handschy_number": null,
    "date_assigned": "2019-05-14", "voided": false, "void_reason": null, "notes": null, "project_number": null,
    "sell_numbers": [ { "sell_number": "B2201", "description": "Process blue", "date_assigned": "2019-05-14", "voided": false, "reason": null } ]
  }
}
```

### `GET /api/v1/prefixes`

Every prefix with its label, what it is for, the suffixes it allows, whether it is retired, how many codes it holds and the next number it would give out.

```bash
curl -s -H "Authorization: Bearer $CHROMA_TOKEN" "http://chroma.local/api/v1/prefixes"
```

```json
{ "data": [ { "prefix": "Y", "label": "Yellow", "description": "Standard color prefix for yellows.", "min_digits": 4, "retired": false, "tag": null, "allowed_suffixes": ["UV"], "codes_in_use": 715, "highest_number": 1714, "next_number": 1715 } ], "page": 1, "per_page": 33, "total": 33 }
```

## Projects

### `GET /api/v1/projects`

Non-confidential projects, newest change first. Filters (all optional):

| Parameter | Meaning |
|---|---|
| `status` | `open`, `completed` or `cancelled` |
| `customer` | a customer id, or the start of a customer name (`customer=Example`) |
| `type` | project type code: `color_match`, `customer_issue`, `customer_evaluation`, `rnd` |
| `updated_since` | ISO 8601 date or date-time (`2026-10-01` or `2026-10-01T14:30:00Z`); only projects changed since then. Use this to poll for changes. |
| `page`, `per_page` | pagination |

```bash
curl -s -H "Authorization: Bearer $CHROMA_TOKEN" \
  "http://chroma.local/api/v1/projects?status=open&updated_since=2026-10-01T00:00:00Z&per_page=100"
```

```json
{
  "data": [
    {
      "project_number": 104, "type": "color_match", "type_label": "Color match",
      "customer": "Example Packaging", "customer_id": 12, "title": "4-color process set",
      "status": "open", "date_submitted": "2026-10-20", "date_completed": null, "target_date": null,
      "assigned_to": "Matt", "item_count": 4, "items_done": 2,
      "created_at": "2026-10-20 14:55:01", "updated_at": "2026-10-21 09:12:40"
    }
  ],
  "page": 1, "per_page": 100, "total": 1
}
```

### `GET /api/v1/projects/{number}`

One project by its project number, with every item (color, trial, product) and the codes on each. Confidential projects answer `404`.

```bash
curl -s -H "Authorization: Bearer $CHROMA_TOKEN" "http://chroma.local/api/v1/projects/104"
```

```json
{
  "data": {
    "project_number": 104, "type": "color_match", "title": "4-color process set", "status": "open", "stage": null, "description": null,
    "items": [
      {
        "sequence": 1, "kind": "color", "description": "Pantone 663 Purple", "pantone": "663 C", "hex_color": "#6b3fd1",
        "ink_series": "UV Flexo", "substrate": "Blister board", "coating": null,
        "proofs_generated": true, "proof_count": 2, "digital_match": false,
        "status": "matched", "status_label": "Matched", "done": true, "assigned_to": "Matt",
        "date_started": "2026-10-20", "date_completed": "2026-10-21",
        "internal_code": "E9102", "internal_code_voided": false,
        "sell_number": { "sell_number": "P1556", "description": "Pantone 663 Purple", "date_assigned": "2026-10-21", "voided": false, "reason": null }
      }
    ],
    "...": "plus the same project fields as the list"
  }
}
```

## Errors

```bash
curl -s -i "http://chroma.local/api/v1/codes?q=Y1714"
# HTTP/1.1 401 Unauthorized
# {"error": "Send an API token in the Authorization header: \"Authorization: Bearer chroma_…\". Tokens are created under Admin → API tokens."}
```

| Status | When |
|---|---|
| 401 | No token, an unknown token, or a revoked token |
| 404 | No such sell number, E number or project (or the project is confidential); also any path under `/api/v1` that is not an endpoint, such as `/api/v1/projects/abc` |
| 405 | A write method (`POST`, `PUT`, `DELETE`) on an endpoint: the API is read-only; the `Allow` header says `GET, HEAD` |
| 422 | A parameter was not understood (bad `status`, unparsable `updated_since`) |
| 500 | Something broke on the Chroma side; it is logged |

## Direct SQL for reporting tools

For BI tools and the future ERP, two read-only MariaDB views mirror the API (`db/migrations/0002_views.sql`). Give the consumer a MariaDB account with `SELECT` on these views only:

- **`v_chroma_code_lookup`** — one row per sell number: `sell_number`, `sell_number_normalized`, `prefix`, `sell_number_value`, `suffix`, `internal_code` (e.g. `E6374-10`), `internal_number`, `internal_version`, `description`, `customer`, `date_assigned`, `resale`, `vendor_name`, `vendor_code`, `sell_number_voided`, `internal_code_voided`, plus the ids.
- **`v_chroma_project_status`** — one row per non-confidential project: `project_id`, `project_number`, `project_type`, `project_type_label`, `customer`, `title`, `status`, `date_submitted`, `date_completed`, `target_date`, `assigned_to`, `item_count`, `items_done`.

```sql
SELECT sell_number, internal_code, description, customer
FROM v_chroma_code_lookup
WHERE sell_number_normalized = 'Y1714UV';

SELECT project_number, customer, title, items_done, item_count
FROM v_chroma_project_status
WHERE status = 'open'
ORDER BY date_submitted;
```

All Chroma tables carry the `chroma_` prefix, so the views can live in a shared database later without renaming. The views exclude confidential projects exactly as the API does. Never write to Chroma tables from another system: codes must only be created through Chroma so the counters stay correct.
