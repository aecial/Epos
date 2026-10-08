# Restaurant POS API — Endpoints (v1)

> **Status:** Implemented. This document describes the REST API in `routes/api_v1.php`, which is what the React Native POS talks to. Routes, controllers, requests and services are authoritative if this document differs. Things in the original plan that do **not** exist yet are listed in [§13 Not implemented](#13-not-implemented-yet).
>
> The back office is **not** served by this API. It uses session-authenticated Laravel web routes and Inertia pages (`routes/web.php`). See `ENHANCED_SPEC.md` §7.

**Base URL:** `http://nuc-ip:8000/api/v1`
**Authentication:** Bearer token via Laravel Sanctum (everything except `POST /auth/login`)
**Content type:** `Accept: application/json` on every request

---

## 0. CONVENTIONS

### Response envelope

Success:

```json
{ "success": true, "data": { "...": "..." }, "meta": { "...": "..." } }
```

`meta` is present only when there is something to put in it (pagination, `ticket_item_id`, `approver`).

Error:

```json
{
    "success": false,
    "message": "User-friendly error message",
    "errors": { "field_name": ["Validation error message"] }
}
```

`errors` is present only on `422`.

### Status codes

| Code | Meaning                                                                                                      |
| ---- | ------------------------------------------------------------------------------------------------------------ |
| 200  | OK                                                                                                           |
| 201  | Created (login, open shift, create ticket/transaction/refund, add item)                                      |
| 401  | Missing/invalid token — `Unauthenticated.`                                                                   |
| 403  | Role not allowed, a passcode that matches no active manager/admin (`Passcode is invalid or the approver lacks permission.`), a passcode shared by more than one of them, or a token's ability doesn't cover the route (e.g. a `kds`-scoped token calling anything outside §8.5) |
| 404  | Unknown id, another cashier's ticket (§5), or no active shift where one is required                          |
| 409  | Business-rule conflict: no active shift, shift already open, open tickets block close, charge total mismatch, insufficient stock, ticket not open, refund already decided, a refund that doesn't stay within what was purchased/paid, etc. |
| 422  | Validation failed                                                                                            |
| 429  | Login throttled (6 attempts per minute), or 5 failed passcode attempts in a minute (sends `Retry-After`)      |

Any domain rule a service rejects with `InvalidArgumentException` (for example "Ticket is not open.") is returned as **409** with that message.

### Money and dates

- Money columns are Laravel `decimal` columns with no cast, so **model-backed responses (tickets, charges, refunds, shifts, transactions, items) serialize money as decimal strings** on MySQL, e.g. `"175.00"`. Parse before doing math.
- Computed values (live shift totals, receipt `payload`) are JSON numbers.
- Send amounts as numbers. Charges and refunds compare in whole centavos.
- Timestamps are ISO-8601 UTC.

### Roles

`admin`, `manager`, `cashier`. A token belongs to one user. Role checks are noted per endpoint; "any staff" means any authenticated user.

A token also carries Sanctum abilities, independent of the user's role: every route in §2–§8 (everything except §8.5 KDS) requires the `full-access` ability, which a normal login (`scope` omitted) always has. A `scope: "kds"` login instead gets only `kds:read`/`kds:complete` (§1, §8.5) and is forbidden (`403`) from everything else, regardless of the underlying user's role.

---

## 1. AUTHENTICATION

### POST `/auth/login`

Log in with `username` (not email) and password. Throttled: 6 requests per minute.

**Body**

```json
{ "username": "dangbi", "password": "pass1234", "device_name": "POS-01", "scope": "kds" }
```

`device_name` is optional (default `pos`); it labels the token so a manager can tell tablets apart on the back office's Employee Management → Devices page (below).

`scope` is optional. Omitted, the token gets Sanctum's default `['*']` abilities — full access to every `/api/v1` route, same as today. `"kds"` instead issues a token restricted to the `kds:read`/`kds:complete` abilities only (see §8.5) — use this for a kitchen display tablet so a lost/stolen device can't touch payments, tickets or refunds. Any staff account can mint a kds-scoped token with their own credentials, same as any POS terminal login.

Tokens never expire on their own (`config/sanctum.php` `expiration` is `null`) — a terminal logs in once and stays signed in until a manager/admin revokes it. Pass a stable, meaningful `device_name` (e.g. the `terminal_id`, not the default `pos`) so that revoke list is actually useful.

### Revoking a device's login (back office only)

Not part of `/api/v1` — this lives in the session-authenticated back office (`routes/web.php`), gated the same as the rest of Employee Management (admin/manager only):

- `GET /users/{user}/sessions` — list that user's active tokens (`device_name`, signed-in time, last-used time)
- `DELETE /users/{user}/sessions/{token}` — revoke one device; its token stops authenticating immediately
- `DELETE /users/{user}/sessions` — revoke every device at once (lost tablet, employee left, etc.)

An admin account's sessions can't be viewed or revoked through this UI, same protection as editing/deleting an admin.

**Response `201`**

```json
{
    "success": true,
    "data": {
        "token": "1|abcdef...",
        "user": { "id": 2, "name": "Dangbi", "username": "dangbi", "role": "cashier", "status": "active" }
    }
}
```

`password` and `passcode` are never serialized.

**Errors:** `422` `username: These credentials do not match our records.` for bad credentials, or `username: This account is inactive.` for an inactive user.

### GET `/auth/me`

Returns the current user (same shape as `data.user` above).

### POST `/auth/logout`

Revokes the current token only. Returns `{ "success": true, "data": { "message": "Logged out." } }`.

There is no `/auth/register`. Users are created in the back office (§ENHANCED_SPEC 7).

---

## 2. MENU (POS)

Feeds the MenuScreen. Replaces the old `/menu` endpoint.

> **Auth:** requires a token, like every other `/api/v1` route.

### GET `/items`

Optional query: `category_id` (must exist).

Returns every item the POS is allowed to show, **sorted by name**:

- `hidden` items are never returned.
- Items in a category with `is_visible_to_pos = false` are never returned.
- `available` items are orderable; `unavailable` items are returned so the POS can grey them out.

```json
{
    "success": true,
    "data": [
        {
            "id": 12,
            "category_id": 3,
            "category": { "id": 3, "name": "Mains", "type": "menu" },
            "name": "Fried Itik (Large)",
            "base_price": "150.00",
            "status": "available",
            "inventory_type": "recipe",
            "entry_mode": "fixed",
            "image_url": null,
            "available_stock": 7,
            "modifiers": [
                {
                    "id": 5,
                    "name": "Extra crispy",
                    "price_modifier": "10.00",
                    "group": { "id": 1, "name": "Cooking", "is_required": false }
                }
            ]
        }
    ]
}
```

Notes:

- `cost_price` and the raw `quantity` / `reserved_quantity` are deliberately **not** exposed.
- `available_stock` is what can be sold right now: `quantity − reserved_quantity` for `direct`; the smallest complete-serving count across ingredients for `recipe`; `null` for `none` (untracked/unlimited) and for a recipe item with no ingredients configured yet.
- `modifiers` lists only the item's **active** modifiers, in the configured display order. `group` is `null` for an ungrouped modifier. `price_modifier` is the per-item price from the `item_modifier` pivot.
- A category filter with no matching items returns `[]`.
- `category.type` is `menu` or `special`. A **special** category holds **Special items** (fees and custom items, see below); show it as its own "Specials" section. Special items have `inventory_type: "none"`, so `available_stock` is `null`.
- `entry_mode` tells the app what to collect before adding the item to a ticket:

| `entry_mode` | Kind | The cashier types | What to send to `POST /tickets/{id}/items` |
| ------------ | ---- | ----------------- | ------------------------------------------ |
| `fixed` | Any normal item, or a fixed-price fee (e.g. Packaging ₱10) | nothing | just `item_id`, `quantity` |
| `price` | **Fee item** (e.g. Delivery fee) | the amount (`base_price` is only a suggested default to pre-fill) | plus `unit_price` |
| `name_price` | **Custom item** (e.g. an off-menu dish) | the name and the amount | plus `unit_price` and `custom_name` |

### GET `/items/{id}`

One item, same shape. A hidden item or one in a POS-hidden category is a clean `404`.

### GET `/categories`

Categories for the MenuScreen's pills, **sorted by name**. Only `active` categories with `is_visible_to_pos = true` are returned — the same visibility rule `/items` applies.

```json
{
    "success": true,
    "data": [
        { "id": 3, "name": "Mains", "type": "menu" },
        { "id": 7, "name": "Specials", "type": "special" }
    ]
}
```

### GET `/categories/{id}`

One category, same shape. An inactive category or one with `is_visible_to_pos = false` is a clean `404`.

---

## 3. SHIFTS

Only one shift can be open at a time (DB unique index on a generated `is_open` column, plus an application check).

### POST `/shifts`

Open a shift. **Any staff** may open (the code allows all authenticated users).

**Body:** `{ "starting_cash": 5000 }` (`numeric`, `>= 0`)

**Response `201`:** the shift as created — only the fields set at creation (re-fetch with `GET /shifts/active` for the full row and live totals).

```json
{
    "success": true,
    "data": {
        "id": 1, "opened_by": 2, "status": "open", "starting_cash": 5000,
        "opened_at": "2026-09-25T06:00:00.000000Z",
        "created_at": "2026-09-25T06:00:00.000000Z", "updated_at": "2026-09-25T06:00:00.000000Z"
    }
}
```

**Errors:** `409` if a shift is already open.

### GET `/shifts/active`

The open shift, **with live totals merged in**. `404` (`No active shift is open.`) if none.

Live totals (numbers) overwrite the snapshot columns:

```json
{
    "id": 1, "status": "open", "starting_cash": "5000.00",
    "total_revenue": 1250.0, "total_cash": 750.0, "total_gcash": 500.0,
    "total_additions": 500.0, "total_expenses": 200.0,
    "total_refunds": 0.0, "total_cash_refunds": 0.0,
    "expected_cash": 6050.0
}
```

### GET `/shifts/{shift}`

Same as above for an open shift (live totals). For a closed shift, returns the stored row with the snapshot written at close.

### PUT `/shifts/{shift}/close`

Close a shift. **Any staff** may close (deliberate: cashiers included).

**Body:** `{ "closing_cash": 8200 }` — the cash counted in the drawer.

**Rules**

- `409` `Shift is already closed.` if not open.
- `409` if any ticket in the shift is still `open` (paid, merged and cancelled tickets do not block).
- Runs in a transaction with the shift row locked.

**Response:** the closed shift with the totals snapshotted. Values written by the close are JSON numbers in this response; re-fetching later via `GET /shifts/{shift}` returns them as decimal strings.

```json
{
    "id": 1, "status": "closed", "closed_by": 2, "closed_at": "2026-09-25T14:00:00.000000Z",
    "starting_cash": "5000.00", "closing_cash": 6000,
    "total_revenue": 1250, "total_cash": 750, "total_gcash": 500,
    "total_additions": 500, "total_expenses": 200, "total_refunds": 0,
    "expected_cash": 6050, "discrepancy": -50
}
```

### How totals are computed

```
total_revenue       = SUM(tickets.total)        WHERE status = 'paid'
total_cash          = SUM(charges.amount)       WHERE method = cash  AND status = paid
total_gcash         = SUM(charges.amount)       WHERE method = gcash AND status = paid
total_additions     = SUM(shift_transactions)   WHERE type = addition AND not deleted
total_expenses      = SUM(shift_transactions)   WHERE type = expense  AND not deleted
total_refunds       = SUM(refunds.amount)       WHERE status = approved
total_cash_refunds  = SUM(refunds.amount)       WHERE status = approved AND charge is cash

expected_cash = starting_cash + total_cash + total_additions − total_expenses − total_cash_refunds
discrepancy   = closing_cash − expected_cash
```

Only **cash** refunds reduce expected cash; a GCash refund never touched the drawer. `total_cash_refunds` is returned only by the live view; the closed row stores `total_refunds` (all methods).

---

## 4. SHIFT TRANSACTIONS (Expenses & Cash Additions)

**Manager/admin only** for create and update. Only on an **open** shift.

### GET `/shifts/{shift}/transactions`

All non-deleted transactions for the shift, newest first. Any staff. (Soft-deleted rows are excluded.)

### POST `/shifts/{shift}/transactions`

```json
{ "type": "expense", "amount": 200, "reason": "Supply purchase" }
```

- `type`: `expense` | `addition`
- `amount`: numeric `> 0`
- `reason`: required string, max 255

`201` with the transaction (records `created_by`). `409` if the shift is closed. `403` for a cashier.

### PUT `/shifts/{shift}/transactions/{transaction}`

Body: `amount`, `reason` (type cannot change). Records `updated_by`. `404` if the transaction belongs to a different shift; `409` if the shift is closed. `403` for a cashier.

### DELETE `/shifts/{shift}/transactions/{transaction}`

Soft delete (`deleted_at`, `deleted_by`); excluded from totals afterwards. `409` if the shift is closed. **Note:** unlike create/update, this action has no role check in code — any authenticated staff member can call it (see §13).

---

## 5. TICKETS

A ticket is one customer's open order. Statuses: `open` → `paid` | `cancelled` | `merged`.

### Who can see and act on a ticket

Ticket access is **bound to the logged-in account**:

- A **cashier** sees and acts only on tickets **they opened** (`created_by`).
- A **manager or admin** sees and acts on **every** ticket, e.g. to settle a tab when its cashier is away.
- Another cashier's ticket is `404` (`Resource not found.`), identical to an unknown id, on every per-ticket route: show, add/change/void an item, discount, merge, cancel and `POST /tickets/{ticket}/charges` (§6).
- KDS (§8.5), receipts (§7) and refunds (§8) are **not** scoped: they see every ticket.
- `terminal_id` is only a label the client sends on creation; it never decides access.

### GET `/tickets`

A cashier's list holds only their own tickets; a manager's/admin's holds everyone's. Query (all optional):

| Param         | Notes                                                                                                  |
| ------------- | ------------------------------------------------------------------------------------------------------ |
| `shift_id`    | Defaults to the active shift. `404` if none is open.                                                   |
| `terminal_id` | Optional label filter (e.g. a manager narrowing to one counter). It does not widen what a cashier sees. |
| `status`      | `open` (default), `paid`, `merged`, `cancelled`                                                        |

Returns tickets ordered by `created_at` ascending, each with `items_count` (non-voided lines only).

### POST `/tickets`

Create a ticket on the active shift. Any staff.

```json
{ "terminal_id": "POS-01", "customer_name": "john", "order_type": "dine_in" }
```

- `terminal_id`: required string, max 50. A label (e.g. the tablet's name); not an access rule.
- `customer_name`: required string, max 255
- `order_type`: `dine_in` | `takeout`

**Behavior**

- `order_number` is `#001`, `#002`, … and **resets each shift**.
- Duplicate names among **open** tickets in the shift (across all terminals) are auto-suffixed: `john` → `john2` → `john3`. A name can be reused once the earlier ticket is no longer open. Enforced by a DB unique index on `(shift_id, open_name)` as well.
- `409` if no shift is open.

**Response `201`:** the ticket as created (re-fetch with `GET /tickets/{ticket}` for every column).

```json
{
    "success": true,
    "data": {
        "id": 10, "shift_id": 1, "created_by": 2, "terminal_id": "POS-01",
        "customer_name": "john2", "order_number": "#002", "order_type": "dine_in",
        "status": "open", "subtotal": 0, "total": 0,
        "created_at": "2026-09-25T06:10:00.000000Z", "updated_at": "2026-09-25T06:10:00.000000Z"
    }
}
```

### GET `/tickets/{ticket}`

The ticket with `items` (each with `modifiers`, including voided lines — check `voided_at`), `charges` (each with its `receipt`), and `merged_tickets`.

### POST `/tickets/{ticket}/items`

Add a line and **reserve stock**. Any staff. Ticket must be `open`.

```json
{ "item_id": 12, "quantity": 2, "modifier_ids": [5], "notes": "Extra crispy" }
```

- `quantity`: integer `>= 1`
- `modifier_ids`: optional; ids must exist and be attached to the item (others are ignored)
- `notes`: optional, max 500. **KDS/back-office only — never printed on a receipt.**
- `unit_price`: numeric, `> 0`, at most 2 decimals, max 999999.99 — **required** for `entry_mode` `price` and `name_price`, **forbidden** for every other item (a terminal can never override a menu price)
- `custom_name`: 1–100 characters, single line — **required** for `name_price`, **forbidden** otherwise

Line price = `(unit_price or base_price + Σ modifier price_modifier) × quantity`. The item name (the typed `custom_name` for a Custom item), cost price, unit price, `line_type` and modifier names/prices are **snapshotted** onto the line so later menu edits don't change history.

**Special items** (items in a `special` category) never touch inventory, so adding, voiding, paying and refunding them never moves stock. The ticket discount applies to them like any other line. `line_type` on the ticket line is `item` (regular), `fee` (Fee item or any fixed fee in a special category) or `custom` (Custom item). The future KDS feed shows `item` and `custom` lines and hides `fee` lines.

**Response `201`:** the refreshed ticket with `items.modifiers`; `meta.ticket_item_id` is the new line's id.

**Errors:** `409` `Insufficient stock for item …` (nothing is reserved), `409` if the ticket is not open, `422` if `unit_price` / `custom_name` is missing or not allowed for the item's `entry_mode`.

### DELETE `/tickets/{ticket}/items/{ticketItem}`

Void a line and **release its reservation**. Requires a manager/admin **passcode** — the manager types it on the POS and the passcode alone identifies them (see §10 Passcodes).

```json
{ "passcode": "0428" }
```

- `passcode`: required, exactly 4 digits.
- The signed-in user (usually a cashier) is recorded as `voided_requested_by`; the manager/admin the passcode belongs to as `voided_by`.
- `403` `Passcode is invalid or the approver lacks permission.` if it matches no active manager/admin.
- `403` `This passcode is shared by more than one manager. It must be changed in the back office before it can be used.` if it matches more than one.
- `429` after 5 failed passcode attempts in a minute by the signed-in user.
- The line is kept (with `voided_at`); it is excluded from totals and receipts.
- `404` if the line belongs to another ticket; `409` if already voided or the ticket isn't open.

Returns the refreshed ticket; `meta.approver` names who approved:

```json
{ "success": true, "data": { "id": 10, "...": "..." }, "meta": { "approver": { "id": 2, "name": "Kring" } } }
```

### PATCH `/tickets/{ticket}/items/{ticketItem}`

Change a line's quantity **up or down**. Any staff — **no passcode**: this is not a removal. Ticket must be `open`, the line must not be voided.

```json
{ "quantity": 1 }
```

- `quantity`: integer, `>= 1`. There is no way to reach `0` here — dropping a line to zero is a removal, which stays passcode-gated on `DELETE /tickets/{ticket}/items/{ticketItem}` above.
- Reserves the difference if the quantity goes up, releases it if it goes down — same stock rules as adding an item (`409` `Insufficient stock for item …` if raising it exceeds what's available; nothing changes on failure).
- `line_total` is recomputed from the line's own `unit_price` and its modifiers' prices (unaffected by this call) at the new quantity; the ticket's `subtotal`/`total` are recomputed too.

**Response `200`:** the refreshed ticket with `items.modifiers`.

**Errors:** `422` if `quantity` is missing or `< 1`; `409` if the line is voided or the ticket isn't open, or on insufficient stock; `404` if the line belongs to another ticket.

### PATCH `/tickets/{ticket}/discount`

Any staff. Ticket must be `open`.

```json
{ "discount_amount": 25 }
```
or
```json
{ "discount_percent": 10 }
```

- `discount_amount`: numeric `>= 0`; `discount_percent`: numeric `0–100`
- If `discount_percent > 0` it **takes precedence** over `discount_amount`.
- `total = max(0, subtotal − discount)`.
- Sending both fields replaces both; a field you omit resets to `0`. To clear the discount, send `{ "discount_amount": 0 }`.

Returns the updated ticket.

### POST `/tickets/{ticket}/merge`

Merge other open tickets **into** the ticket in the URL (the target).

```json
{ "merge_from_ticket_ids": [11, 12] }
```

**Rules** (checked under row locks; a rejected merge changes nothing):

- All tickets must be `open` and in the **same shift**; the target can't be in its own list → `409`.
- A **cashier** may only merge tickets they opened: a source id they don't own fails validation (`422` on `merge_from_ticket_ids.N`, without confirming it exists) and the service refuses it with `409` (`You can only merge tickets you opened.`). A manager/admin may merge any tickets in the same shift.
- Source lines are physically moved onto the target and remember their origin in `merged_from_ticket_id` (an earlier origin is never overwritten on chained merges).
- Sources become `merged` (`merged_into_ticket_id`, `merged_by`, `merged_at`) with all money zeroed, so the amount lives only on the target.
- Discounts from every ticket combine into **one fixed `discount_amount`** on the target; `discount_percent` becomes `0`.
- Notes are concatenated, prefixed by source order number (`#002: no onions`).
- Chained merges are flattened so the target's `merged_tickets` is the complete list.
- Inventory is untouched (reservations belong to items, not tickets).
- Merged sources don't block closing the shift.

Returns the target with `items.modifiers` and `merged_tickets`.

### POST `/tickets/{ticket}/cancel`

Cancel an open ticket: **releases every reservation**, sets `status = cancelled`, `cancelled_by`, `cancelled_at`. `409` if not open. No passcode required.

---

## 6. PAYMENT

### POST `/tickets/{ticket}/charges`

Pay the ticket. **One call, one transaction:** creates the charges, deducts inventory, marks the ticket `paid`, and issues one receipt per charge. If anything fails (including receipt generation) the whole payment rolls back.

```json
{
    "charges": [
        { "payment_method": "cash", "amount": 100, "tendered_amount": 200 },
        { "payment_method": "gcash", "amount": 75, "payment_reference": "GC-123456" }
    ]
}
```

| Field                      | Rules                                                     |
| -------------------------- | --------------------------------------------------------- |
| `charges`                  | required array, at least 1                                |
| `charges.*.payment_method` | `cash` \| `gcash`                                         |
| `charges.*.amount`         | numeric `>= 0` (`0` only for a ticket discounted to a `0` total — see Comps below) |
| `charges.*.tendered_amount`| optional, `>= amount`, **cash only**; `change_due` is computed from it. `422` if sent for `gcash` — a digital payment has no "tendered"/change |
| `charges.*.payment_reference` | **required for `gcash`**, optional otherwise            |

**Charges are amounts-only.** There is no per-item assignment: each charge covers a portion of the ticket total, and every receipt lists **all** ticket items with the ticket's discount **prorated** to that charge.

**Rules**

- The server **recomputes the total** from live, non-voided lines and the ticket discount — a client-supplied total is never trusted (another terminal may have voided a line).
- The charges must sum to the total **exactly, to the centavo** → otherwise `409 ChargeAmountMismatch`.
- `409` if the ticket isn't `open` (so a paid ticket can't be charged twice and stock is never deducted twice) or has no items.
- Inventory is deducted (`direct` decrements `quantity`; `recipe` decrements ingredients) and reservations cleared.

**Comps (a ticket discounted to `0`):** a 100%-discount or a fixed discount larger than the subtotal both floor the total at `0`. That ticket still has to be closed — the food was made and handed over, so stock still has to come off the shelf even though nothing was collected. Close it the same way, with a **single** charge of `amount: 0`:

```json
{ "charges": [{ "payment_method": "cash", "amount": 0 }] }
```

- Exactly one charge, amount `0` — `409` `A complimentary ticket (total ₱0) must be closed with a single zero-amount charge.` if more than one charge is sent (e.g. two `0` charges, or splitting the `0` across cash and gcash). A single zero-amount charge is required because the receipt's discount proration divides by the ticket total, which would be a division by zero for any charge but the last if there were more than one.
- A `0` charge against a ticket whose real total is **not** `0` still fails the normal sum-mismatch check above — this only ever applies when the recomputed total is genuinely `0`.
- The receipt is still issued and still shows the full `subtotal`/`discount`, just with `total: 0` — it's a record that the order was comped, not that nothing was ordered.
- The shift's `total_revenue`/`total_cash` are unaffected — a comp adds `0` to both.

**Response `200`:** the paid ticket with `charges.receipt`. Each receipt already carries the full printable `payload`, so the POS can print immediately without a second request:

```json
{
    "success": true,
    "data": {
        "id": 10, "status": "paid", "subtotal": "200.00", "total": "175.00", "closed_at": "...",
        "charges": [
            {
                "id": 21, "payment_method": "cash", "amount": "100.00",
                "tendered_amount": "200.00", "change_due": "100.00", "status": "paid",
                "payment_reference": null,
                "receipt": { "id": 31, "receipt_number": "REC-2026-09-25-001", "payload": { "...": "see §7" } }
            },
            { "id": 22, "payment_method": "gcash", "amount": "75.00", "payment_reference": "GC-123456", "receipt": { "...": "..." } }
        ]
    }
}
```

There is no separate "close ticket" call — paying closes it.

---

## 7. RECEIPTS

A receipt is an **immutable snapshot** written when payment commits (one per charge). Reprinting a receipt next month gives identical content.

- Number format: `REC-YYYY-MM-DD-NNN` — a per-day running sequence, unique.
- Split payment ⇒ two receipts, same order number and items, each with its own prorated slice. Discount shares are prorated in whole centavos; the last charge takes the remainder, so shares always add up to the ticket discount exactly.
- Merged tickets print **one** receipt listing every order number in `order.merged_from`.
- `notes` are **never** included.

### Receipt `payload`

```json
{
    "receipt_number": "REC-2026-09-25-001",
    "issued_at": "2026-09-25T06:35:00+00:00",
    "order": {
        "order_number": "#001",
        "customer_name": "john",
        "order_type": "dine_in",
        "terminal_id": "POS-01",
        "merged_from": [{ "order_number": "#002", "customer_name": "john2" }]
    },
    "cashier": "Dangbi",
    "items": [
        {
            "name": "Fried Itik (Large)", "line_type": "item", "quantity": 1, "unit_price": 150.0,
            "modifiers": [{ "name": "Extra crispy", "price": 10.0 }],
            "line_total": 160.0
        }
    ],
    "subtotal": 200.0,
    "discount": 25.0,
    "total": 175.0,
    "payment": {
        "method": "cash", "amount": 175.0,
        "tendered_amount": 200.0, "change_due": 25.0, "reference": null
    }
}
```

`line_type` is `item`, `fee` or `custom`, so the POS can print fees under their own heading; receipts issued before Special items existed have no `line_type` (treat as `item`). For a Custom item `name` is the name the cashier typed.

Here `subtotal − discount = total` describes **this charge's slice** of the bill. The restaurant name/address header and footer text are not in the payload; the POS app supplies them.

### GET `/receipts`

History for **every terminal** (no restriction). Paginated, newest first. Lightweight rows — no payload.

Query (all optional): `shift_id`, `ticket_id`, `terminal_id`, `payment_method` (`cash|gcash`), `date_from`, `date_to` (`>= date_from`), `search` (matches order number, customer name or receipt number), `page`, `per_page` (1–100, default 20).

```json
{
    "success": true,
    "data": [
        {
            "id": 31, "receipt_number": "REC-2026-09-25-001", "order_number": "#001",
            "customer_name": "john", "payment_method": "cash", "amount": "175.00",
            "terminal_id": "POS-01", "shift_id": 1, "ticket_id": 10,
            "issued_at": "...", "reprint_count": 0
        }
    ],
    "meta": { "total": 1, "per_page": 20, "current_page": 1, "last_page": 1 }
}
```

### GET `/receipts/{receipt}`

The full receipt including `payload`, `reprint_count`, and the `prints` log (each with `is_reprint`, `printed_at`, `printed_by {id, name}`).

### POST `/receipts/{receipt}/reprint`

Logs a duplicate print (`is_reprint = true`) and returns the receipt with two extra fields:

```json
{ "is_reprint": true, "watermark": "DUPLICATE RECEIPT" }
```

The stored receipt is never altered — the POS prints the payload and adds the watermark itself. Any staff.

---

## 8. REFUNDS

Item-level, targeting **one charge** so cash-vs-GCash is known for drawer reconciliation. Only `paid` tickets can be refunded.

### POST `/refunds`

Request a refund. **Any role.**

```json
{
    "ticket_id": 10,
    "charge_id": 21,
    "reason": "Wrong order",
    "items": [{ "ticket_item_id": 55, "quantity": 1, "amount": 150 }]
}
```

- `items`: at least one; `ticket_item_id` distinct; `quantity` integer `>= 1`; `amount` `> 0`
- The refund `amount` is the sum of item amounts. Status starts `pending`.
- `409` if the charge doesn't belong to the ticket or the ticket isn't `paid`.
- `409` if a `ticket_item_id` doesn't belong to the ticket being refunded, if the requested `quantity` exceeds what's left to refund on that line (purchased minus whatever's `pending`/`approved` on prior refunds), if the requested `amount` exceeds that line's actual per-unit price × quantity, or if the refund's total would exceed what the targeted charge actually collected. These bounds are re-derived from the database inside a locked transaction on every request — nothing here is trusted from the client. A `rejected` refund's quantity/amount becomes refundable again.

`201` with the refund and its `items`.

### GET `/refunds`

Optional `status` (`pending|approved|rejected`) and `shift_id`. Newest first, with `items`.

### GET `/refunds/{refund}`

One refund with `items`.

### PUT `/refunds/{refund}/approve` · PUT `/refunds/{refund}/reject`

Requires a manager/admin passcode; the passcode alone identifies who decides:

```json
{ "passcode": "0428" }
```

- `passcode`: required, exactly 4 digits.
- `403` `Passcode is invalid or the approver lacks permission.` if it matches no active manager/admin; `403` with the "shared by more than one manager" message if it matches several.
- `429` after 5 failed passcode attempts in a minute by the signed-in user.
- `409` `Refund has already been decided.` if not `pending` (checked under lock).
- **Approve** restores inventory (`direct` → `quantity += qty`; `recipe` → ingredients restored; `none` → nothing), sets `status = approved`, `approved_by`, `approved_at`. A **cash** refund reduces the shift's expected cash (§3).
- **Reject** sets `status = rejected` with the same audit fields and touches nothing else.

Returns the refund; `approved_by` holds the deciding user's id and `meta.approver` is `{ "id", "name" }` for display. The ticket stays `paid`.

---

## 8.5. KDS (Kitchen Display System)

The kitchen-facing read feed and completion toggle. Unlike every other `/api/v1` route, these two can be reached by a token scoped to `kds:read`/`kds:complete` only (§1) in addition to a full-access one.

### GET `/kds/orders`

**Requires the `kds:read` ability** (or full access). Every open ticket, strictly `created_at` ASC across all terminals, each with its kitchen-relevant lines in the order they were added.

Excludes: paid/cancelled/merged tickets, voided lines, `fee` line_type lines (fee lines never reach the kitchen), and **completed lines** - a bumped item drops off the feed entirely rather than appearing checked off. A ticket with no pending lines left disappears from this response until something new is added to it, and that add-on then appears alone, not mixed back in with the already-served lines. Never includes prices or `terminal_id` - the KDS is a kitchen-only, no-money, no-terminal view.

```json
{
    "success": true,
    "data": [
        {
            "ticket_id": 10,
            "order_number": "#001",
            "customer_name": "john",
            "order_type": "dine_in",
            "created_at": "2026-10-01T12:00:00.000000Z",
            "items": [
                {
                    "ticket_item_id": 55,
                    "item_name": "Fried Itik",
                    "quantity": 2,
                    "notes": "Extra crispy",
                    "modifiers": [{ "name": "Large" }]
                }
            ]
        }
    ]
}
```

### PATCH `/kds/orders/items/{ticketItem}/complete`

**Requires the `kds:complete` ability** (or full access). Toggles `ticket_items.completed_at`; no passcode, since this isn't a removal.

**Body**

```json
{ "completed": true }
```

- `409` if the item is voided, or its ticket is no longer `open`.

`200` with `{ "ticket_item_id": 55, "completed": true }`.

Completion is persisted (not UI-only) so it survives a tablet reload and stays in sync across multiple KDS screens - but it's operational kitchen-workflow state, not audit history: nothing in receipts, payments or refunds reads it, and a nightly scheduled command (`kds:clear-completed`, `dailyAt('03:00')`) sweeps every `completed_at` back to `null` regardless of ticket status.

### PATCH `/kds/orders/{ticket}/complete`

**Requires the `kds:complete` ability** (or full access). Bumps every pending (non-voided, kitchen-visible) line on the ticket in one action - the "tap the customer name" gesture, instead of toggling each item individually. No body.

- `409` if the ticket is no longer `open`.
- Voided lines and lines already completed are left untouched.

`200` with `{ "ticket_id": 10, "completed": true }`. Broadcasts `ticket.updated`; since every line is now completed, the resulting card has nothing pending left and drops off the feed until something new is added to the ticket.

### Realtime: the `kds.orders` channel

A private channel broadcasting the events below, via Laravel Reverb. Any authenticated token (full-access or kds-scoped) may subscribe.

Because the client here is a bearer-token tablet, not a browser session, the broadcasting auth endpoint is `POST /api/v1/broadcasting/auth` (Sanctum-guarded) rather than Laravel's default session-guarded `/broadcasting/auth`.

| Event | Fired on | Payload |
| --- | --- | --- |
| `ticket.created` | A ticket is created | Full card, shaped like a `GET /kds/orders` row |
| `ticket.updated` | Items added/voided, quantity changed, or a merge target's lines changed | Full card (pending lines only - a card can arrive with an empty `items` array, meaning the kitchen should drop it) |
| `ticket.paid` | The ticket left the feed (paid) | `{ ticket_id, order_number }` |
| `ticket.cancelled` | The ticket left the feed (cancelled) | `{ ticket_id, order_number }` |
| `ticket.merged` | Source ticket(s) left the feed (merged into a target) | `{ ticket_id, order_number, removed_ticket_ids, removed_order_numbers }` |
| `item.completed` | `PATCH .../complete` with `completed: true` | `{ ticket_id, ticket_item_id }` |
| `item.uncompleted` | `PATCH .../complete` with `completed: false` | `{ ticket_id, ticket_item_id }` |

Every broadcast fires after its underlying `DB::transaction()` already committed, so a rollback never produces a false push, and every dispatch is wrapped in a try/catch - a Reverb outage degrades to "the tablet falls back to polling `GET /kds/orders`," never a failed POS request. Changing a ticket's discount broadcasts nothing (the KDS card carries no prices, so a discount change is invisible to it).

---

## 9. AUDIT TRAIL SUMMARY

| Action              | Recorded                                                         |
| ------------------- | ---------------------------------------------------------------- |
| Ticket created      | `created_by`, `terminal_id`                                      |
| Line voided         | `voided_by` (approver), `voided_requested_by` (cashier), `voided_at` |
| Ticket cancelled    | `cancelled_by`, `cancelled_at`                                   |
| Ticket merged       | `merged_by`, `merged_at`, `merged_into_ticket_id`; line `merged_from_ticket_id` |
| Charge              | `created_by`, `paid_at`                                          |
| Receipt / reprint   | `issued_by`; every print in `receipt_prints` (`printed_by`, `is_reprint`) |
| Shift transaction   | `created_by`, `updated_by`, `deleted_by`, `deleted_at`           |
| Refund              | `requested_by`, `approved_by` (the passcode holder), `requested_at`, `approved_at` |
| Shift               | `opened_by`, `closed_by`                                         |

---

## 10. KEY IMPLEMENTATION NOTES

### Inventory flow

```
Add line     →  reserve   (direct: reserved_quantity += qty | recipe: ingredient reserved += needed)
Void / cancel →  release   (reverse of reserve)
Pay          →  deduct    (quantity -= qty AND reserved -= qty)
Approve refund → restore   (quantity += qty)
```

- `none` items skip all inventory accounting.
- Reserving fails with `409` if `quantity − reserved_quantity` (or any recipe ingredient) is short; nothing is reserved on failure.
- Every mutation runs in a transaction that locks the item/ingredient rows, so competing terminals can't oversell.
- Merging tickets never touches inventory.

### Transactions and locking

Creating a ticket locks the shift row to serialize order-number and name assignment across terminals. Payment locks the ticket, then the shift (receipt numbering). Merges lock every involved ticket in ascending id order to avoid deadlocks. Refund decisions lock the refund row. The KDS completion toggle locks the ticket item, then its ticket, same pattern as voiding or changing quantity.

### Passcodes

A passcode is a 4-digit PIN stored hashed (bcrypt) on the user. The POS never knows approver ids: it sends only `passcode`, and the server `Hash::check`s it against **every** active `admin`/`manager` with a passcode (always all of them, for consistent timing and to spot duplicates):

- no match → `403`; exactly one → that user is the approver; more than one → `403` asking for the passcode to be changed in the back office (the server never guesses).
- Cashiers and inactive users never match, even if they hold a passcode.
- Failed attempts are counted per signed-in user: 5 in a minute → `429` with `Retry-After` until the minute is up. A successful passcode resets the count.
- Because hashes are salted, uniqueness can't be a DB index. The back office Employees form instead rejects a manager passcode that already belongs to another active manager/admin (`This passcode is already in use. Choose a different one.`).

### Error catalog

| HTTP | Typical `message`                                                                                          |
| ---- | ---------------------------------------------------------------------------------------------------------- |
| 401  | `Unauthenticated.`                                                                                         |
| 403  | `Passcode is invalid or the approver lacks permission.` · `This passcode is shared by more than one manager. It must be changed in the back office before it can be used.` · role-forbidden · token ability doesn't cover the route |
| 404  | `Resource not found.` (also another cashier's ticket) / `No active shift is open.`                         |
| 409  | `Insufficient stock for item X.` · `Ticket is not open.` · `Tickets must belong to the same shift to be merged.` · `You can only merge tickets you opened.` · `Refund has already been decided.` · `Ticket item {id} does not belong to this ticket.` · `Refund quantity for ticket item {id} exceeds the remaining refundable quantity ({n}).` · `Refund amount for ticket item {id} exceeds its purchased value.` · `Refund amount exceeds what this charge collected.` · `Cannot change the completion state of a voided item.` · `Cannot change item completion state on a ticket that is not open.` · charge amount mismatch · shift already open · open tickets exist |
| 422  | `The given data was invalid.` + `errors`                                                                   |
| 429  | `Too many incorrect passcode attempts. Please try again in N seconds.` · login throttled                   |

---

## 11. TYPICAL POS FLOW

```
POST /auth/login                       → token
GET  /shifts/active                    → 404? → POST /shifts { starting_cash }
GET  /items                            → menu
POST /tickets                          → ticket (#001, "john")
POST /tickets/{id}/items               → reserve stock, repeat per item
PATCH /tickets/{id}/items/{itemId}     → optional, adjust a line's quantity (no passcode)
PATCH /tickets/{id}/discount           → optional
POST /tickets/{id}/charges             → pay (split ok) → receipts in the response → print
GET  /receipts                         → history / reprint
PUT  /shifts/{id}/close { closing_cash } → reconcile
```

---

## 12. TESTED BEHAVIOR

Covered by Pest tests (`tests/Feature`, `tests/Unit`): ordering reserves in both `direct` and `recipe` modes without deducting; paying deducts and clears reservations; a paid ticket can't be charged twice; a short recipe ingredient blocks the order and reserves nothing; cash+GCash split totals; receipt numbering, per-charge slices and centavo-exact discount proration; notes never on receipts; receipt immutability and payment rollback on receipt failure; receipt history filters/pagination/search; reprint logging; ticket merge rules (same shift, open only, chained merges, one receipt with all order numbers); menu visibility, filters, `available_stock`, and modifier output.

Special items (fee and custom lines, entry-mode validation, no stock movement through pay/void/refund, discount, merge, receipt `line_type`) are covered by `SpecialItemTest`.

Passcode approval (void and refund approve/reject identify the manager/admin from the passcode alone; wrong, cashier, inactive and shared passcodes are refused; 5 failures → `429`, success resets; back office passcode uniqueness) is covered by `PasscodeApprovalTest`.

Refund validation (cross-ticket `ticket_item_id` rejected, quantity/amount bounded by what was actually purchased and by what the targeted charge collected, the same units can't be refunded twice across separate requests whether pending or approved, a rejected refund frees its quantity/amount back up) is covered by `RefundValidationTest`.

Back-office deletes that would otherwise violate a `restrictOnDelete()` foreign key (category/item/modifier/modifier group/ingredient/ingredient group referenced by sales history or a recipe) flash a readable error instead of crashing — covered by `DeleteInUseTest`.

KDS (feed ordering/filtering/field omissions, completion toggle persistence and its voided/not-open guards, the nightly `kds:clear-completed` sweep, and a kds-scoped Sanctum token being forbidden from every other route) is covered by `tests/Feature/Kds/*`. Realtime broadcasting (every mutation dispatches the right event with the right payload, a rolled-back mutation dispatches nothing, `SetDiscount` dispatches nothing) is covered by `KdsBroadcastTest`.

Ticket access (another cashier gets `404` on every per-ticket route and the ticket is untouched, a manager/admin can view/add to/pay any ticket, a cashier lists only their own tickets in any status while a manager/admin lists all, merges can't pull in another cashier's ticket in the request or the service, a manager/admin can merge across cashiers, KDS and receipts still see everything) is covered by `TicketOwnershipTest`.

**Not yet covered by dedicated tests:** API login, shifts open/close and totals, shift transactions, refund listing, and ticket create/discount/cancel.

---

## 13. NOT IMPLEMENTED YET

| Item | Status |
| ---- | ------ |
| **Real-time / WebSockets for general POS sync** | The KDS channel (`kds.orders`, §8.5) is implemented via Laravel Reverb. `shift.{shift_id}`/`terminal.{terminal_id}` and the `inventory.updated`/`refund.*` events in `ENHANCED_SPEC.md` §10 are not - POS terminals still use the manual sync button or polling. |
| **KDS tablet client (the actual display app)** | `GET /kds/orders`, the completion toggle and the realtime channel it will use are implemented (§8.5). The React Native display itself is not built. |
| **Employee / category / item admin over the API** | These live in the session-authenticated back office only, not in `/api/v1`. |
| **Per-item charge assignment (`charge_items`)** | Dropped by design; charges are amounts-only with prorated receipts. |
| **Back-office pages for refund history, dashboard stats, reports** | Data and API exist; no Inertia pages yet. (The Shifts and Tickets pages exist — `ENHANCED_SPEC.md` §7. Refund approval stays on the POS.) |
