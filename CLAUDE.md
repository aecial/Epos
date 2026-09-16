# CLAUDE.md — Restaurant POS System

**Stack:** Laravel 12 + Inertia React back office · React Native Expo (planned POS + KDS) · MySQL
**Hardware:** Intel NUC + TP-Link Deco M5 · Goojrpt PT-210 thermal printer (Local Deployment after Development of the overall Application)
**Real-time:** beyondcode/laravel-websockets  
**Auth:** Laravel web/session authentication for the current back office; Sanctum is planned for the POS API

---

## Repository Structure

```
app/
  Http/
    Controllers/
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
  web.php           ← Inertia routes (back office)
database/
  migrations/
  seeders/
docs/
  ENHANCED_SPEC.md
  UNIFIED_API_ENDPOINTS.md
  MERGED_DATABASE_SCHEMA.sql  ← Reference only; use migrations
```

---

## Key Business Rules

| Rule                   | Detail                                                                                                   |
| ---------------------- | -------------------------------------------------------------------------------------------------------- |
| **KDS order**          | Strictly `created_at` ASC (FIFO across all terminals)                                                    |
| **Terminal isolation** | POS only sees its own open tickets                                                                       |
| **Inventory**          | Direct items use item stock; recipe items use shared ingredient stock; reserve on add, deduct on payment |
| **Shift lock**         | One open shift at a time (DB UNIQUE INDEX)                                                               |
| **Split payment**      | Cash + GCash simultaneously on one ticket; one receipt per payment method                                |
| **Notes**              | KDS-only — never printed on customer receipt                                                             |
| **Duplicate names**    | Auto-append within shift: john → john2 → john3                                                           |
| **Passcode**           | 4-digit PIN hashed with bcrypt; required to void item on open ticket or approve refund                   |
| **Shift formula**      | Starting Cash + Cash Sales + Additions − Expenses = Expected Cash                                        |

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

1. **Back office first** (Laravel Inertia React)
    - Auth (login page, role guard)
    - Users CRUD
    - Categories CRUD (with `is_visible_to_pos` toggle)
    - Items CRUD (image upload, status toggle)
    - Modifiers CRUD
    - Ingredient groups CRUD
    - Ingredients CRUD and quantity adjustment
    - Recipe assignment for recipe-based items
2. Shifts management (planned)
3. API layer (planned POS endpoints)
4. React Native POS app (planned)
5. KDS app (planned)
6. Real-time (planned WebSockets)

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
