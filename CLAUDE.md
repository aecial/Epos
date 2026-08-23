# CLAUDE.md — Restaurant POS System

**Stack:** Laravel 12 (API + Inertia React back office) · React Native Expo (POS + KDS) · MySQL  
**Hardware:** Intel NUC + TP-Link Deco M5 · Goojrpt PT-210 thermal printer  
**Real-time:** beyondcode/laravel-websockets  
**Auth:** Laravel Sanctum

---

## Repository Structure

```
app/
  Http/
    Controllers/
      Api/          ← REST API controllers (POS + KDS)
      BackOffice/   ← Inertia controllers (back office pages)
    Middleware/
    Requests/
  Models/
  Services/
  Events/
resources/
  js/
    Pages/          ← Inertia React pages (back office)
    Components/     ← Shared React components
routes/
  api.php           ← REST endpoints (POS / KDS)
  web.php           ← Inertia routes (back office)
database/
  migrations/
  seeders/
  schema/
    initial_schema.sql   ← Reference only; use migrations
docs/
  ENHANCED_SPEC.md
  UNIFIED_API_ENDPOINTS.md
```

---

## Key Business Rules

| Rule                   | Detail                                                                                 |
| ---------------------- | -------------------------------------------------------------------------------------- |
| **KDS order**          | Strictly `created_at` ASC (FIFO across all terminals)                                  |
| **Terminal isolation** | POS only sees its own open tickets                                                     |
| **Inventory**          | `quantity` + `reserved_quantity`; reserve on add, deduct on payment                    |
| **Shift lock**         | One open shift at a time (DB UNIQUE INDEX)                                             |
| **Split payment**      | Cash + GCash simultaneously on one ticket; one receipt per payment method              |
| **Notes**              | KDS-only — never printed on customer receipt                                           |
| **Duplicate names**    | Auto-append within shift: john → john2 → john3                                         |
| **Passcode**           | 4-digit PIN hashed with bcrypt; required to void item on open ticket or approve refund |
| **Shift formula**      | Starting Cash + Cash Sales + Additions − Expenses = Expected Cash                      |

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

1. **Back office first** (Laravel Inertia React)
    - Auth (login page, role guard)
    - Users CRUD
    - Categories CRUD (with `is_visible_to_pos` toggle)
    - Items CRUD (image upload, status toggle)
    - Modifiers CRUD
2. Shifts management (back office view + open/close)
3. API layer (POS endpoints)
4. React Native POS app
5. KDS app
6. Real-time (WebSockets)

---

## Locked Decisions (v1)

- No offline queue — real-time only
- No complex tax logic
- KDS completion is UI-only (no DB timestamp)
- No per-modifier cost tracking
- Margin calc on frontend: `(base_price - cost_price) / base_price * 100`
- WebSockets via Laravel (not Node/Socket.io)
- Remote access via Tailscale

---

## References

- `docs/ENHANCED_SPEC.md` — Full feature specification
- `docs/UNIFIED_API_ENDPOINTS.md` — All REST API routes
- `database/schema/initial_schema.sql` — DB reference (use migrations, not this file directly)
