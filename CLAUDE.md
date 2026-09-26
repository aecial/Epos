# CLAUDE.md — Restaurant POS System

**Stack:** Laravel 12 + Inertia React back office · React Native Expo (planned POS + KDS) · MySQL
**Hardware:** Intel NUC + TP-Link Deco M5 · Goojrpt PT-210 thermal printer (Local Deployment after Development of the overall Application)
**Real-time:** beyondcode/laravel-websockets (planned; nothing installed yet)  
**Auth:** Laravel web/session authentication for the back office; Laravel Sanctum bearer tokens for the POS API (`/api/v1`, implemented)

---

## Repository Structure

```
app/
  Http/
    Controllers/
      *Controller.php   ← Back office controllers (session auth; Inertia pages + JSON)
      Api/V1/           ← POS REST API controllers (Sanctum)
      Api/Concerns/     ← ApiResponses trait (success/error envelope)
    Middleware/
    Requests/           ← Form requests (validation + role authorization)
  Exceptions/           ← Domain exceptions (mapped to 409/403 for /api/*)
  Models/
  Services/             ← Business logic shared by back office and API
resources/
  js/
    pages/              ← Inertia React pages (back office)
    components/         ← Shared React components
routes/
  web.php               ← Inertia routes (back office)
  api.php → api_v1.php  ← POS API, prefix /api/v1
database/
  migrations/
  seeders/
docs/
  ENHANCED_SPEC.md        ← Product spec (implementation status marked)
  UNIFIED_API_ENDPOINTS.md ← The implemented /api/v1 contract
  MERGED_DATABASE_SCHEMA.sql  ← Reference only (drifted); use migrations
```

---

## Key Business Rules

| Rule                   | Detail                                                                                                   |
| ---------------------- | -------------------------------------------------------------------------------------------------------- |
| **KDS order**          | Strictly `created_at` ASC (FIFO across all terminals)                                                    |
| **Terminal isolation** | POS only sees its own open tickets                                                                       |
| **Inventory**          | Direct items use item stock; recipe items use shared ingredient stock; reserve on add, deduct on payment |
| **Shift lock**         | One open shift at a time (DB UNIQUE INDEX)                                                               |
| **Split payment**      | Cash + GCash simultaneously on one ticket; amounts-only (no per-item assignment); charges must equal the recomputed total to the centavo; one receipt per charge with a prorated discount |
| **Notes**              | KDS-only — never printed on customer receipt                                                             |
| **Duplicate names**    | Auto-append among open tickets within a shift, across all terminals: john → john2 → john3                |
| **Passcode**           | 4-digit PIN hashed with bcrypt; required to void item on open ticket or approve/reject refund (approver must be admin/manager) |
| **Shift formula**      | Starting Cash + Cash Sales + Additions − Expenses − Cash Refunds = Expected Cash                          |
| **Payment atomicity**  | Charges, inventory deduction, ticket close and receipt generation succeed or roll back together          |
| **Special items**      | Items in a `special` category (Fee item: cashier types the amount; Custom item: cashier types name + amount). No inventory/cost/recipe/modifiers; discount applies; `ticket_items.line_type` = item/fee/custom (KDS hides `fee`). The word "charge" always means a payment — never use it for these |
| **Ticket merge**       | Open tickets in the same shift fold into a target; sources become `merged` with zeroed money             |

### Inventory Modes

- `direct` — the menu item owns integer `quantity` and `reserved_quantity` stock.
- `recipe` — the menu item consumes ingredient stock through `item_ingredient`.
- `none` — the menu item bypasses inventory accounting.
- Ingredients belong to an `ingredient_group` and use decimal quantities with units `piece`, `kg`, `gram`, `liter`, or `ml`.
- Recipe requirements store `quantity_required` and must use the same unit as the ingredient.
- Recipe availability is the smallest complete-serving count across its ingredients.
- Inventory mutations are transactional and lock item/ingredient rows to prevent competing reservations.

---

## Users & Roles

- Login via `username` (not email)
- `password` — full login credential (bcrypt)
- `passcode` — 4-digit PIN (bcrypt); used for sensitive POS actions:
    - Removing an item from an open ticket → POS prompts passcode → `Hash::check()` → allowed
    - Refund approval → `approved_by` set to the verifying manager/admin user id
- Roles: `admin` · `manager` · `cashier`

---

## Schema Changes from v1.0 → v1.1

| Table        | Change                                                                        |
| ------------ | ----------------------------------------------------------------------------- |
| `users`      | `email` → `username` (unique); `password_hash` → `password`; added `passcode` |
| `categories` | Removed `icon_url`, `display_order`; added `is_visible_to_pos` boolean        |
| `items`      | Status enum changed: `active/inactive` → `available/unavailable/hidden`       |
| `modifiers`  | Removed `price_modifier`, `display_order`                                     |

---

## Item Status Semantics

- `available` — shown and orderable on POS
- `unavailable` — shown on POS but greyed out (cannot be ordered)
- `hidden` — not shown on POS at all; visible in back office only

---

## Development Priority

1. **Back office** (Laravel Inertia React) — done, except item image upload (only an `image_url` string) and the stats/report pages below
    - Auth (login page), users, categories, items, modifier groups/modifiers, ingredient groups/ingredients, recipes
2. **POS API** (`/api/v1`, Sanctum) — done: auth, menu, shifts, shift transactions, tickets (create/add/void/discount/merge/cancel), payments, receipts, refunds
    - Still missing: ticket line quantity edit, a POS categories endpoint, server-enforced terminal isolation, KDS feed
    - Known issues: `GET /items` is registered outside `auth:sanctum`; shift-transaction delete has no role check; public `/register` is still enabled
3. Back-office shift, orders/receipts, refunds and dashboard pages (planned)
4. React Native POS app (planned)
5. KDS app (planned)
6. Real-time (planned WebSockets)

See `docs/UNIFIED_API_ENDPOINTS.md` §13 and `docs/ENHANCED_SPEC.md` §11 for the detailed status.

---

## Locked Decisions (v1)

- No offline queue — real-time only
- No complex tax logic
- KDS completion is UI-only (no DB timestamp)
- No per-modifier cost tracking
- Margin calc on frontend: `(base_price - cost_price) / base_price * 100`
- WebSockets via Laravel (not Node/Socket.io)
- Remote access via Tailscale
- No offline queue; current inventory behavior assumes real-time access

---

## References

- `docs/ENHANCED_SPEC.md` — Full feature specification
- `docs/UNIFIED_API_ENDPOINTS.md` — Planned REST API contract
- `docs/MERGED_DATABASE_SCHEMA.sql` — Reference SQL only; migrations are authoritative
