# Restaurant POS & Back Office System — ENHANCED SPECIFICATION (v1.2)

**Project Name:** Restaurant POS + Back Office Admin Panel + KDS  
**Status:** Back-office menu/inventory/recipe/user management and the POS REST API (shifts, tickets, payments, receipts, refunds) are implemented. The React Native POS, KDS, WebSocket real-time layer and back-office reporting pages are still planned.
**Last Updated:** September 2026

> This document is the product specification. The implemented interfaces are: (1) Laravel 12 + Inertia React session-authenticated back-office pages (`routes/web.php`), and (2) the Sanctum-token REST API for POS clients (`routes/api_v1.php`, documented in `UNIFIED_API_ENDPOINTS.md`). Laravel migrations and application code are authoritative when this document differs from the repository. Sections marked **(planned)** are not built yet.

---

## 1. PROJECT OVERVIEW

A complete point-of-sale (POS), kitchen display system (KDS), and restaurant management system designed to run on a local area network (LAN) via Intel NUC with optional cloud fallback.

### System Components

1. **Laravel web application** — Authenticated back-office pages and the services shared with the API ✅
2. **Laravel REST API** (`/api/v1`, Sanctum) — Backend for POS/KDS clients ✅ (KDS feed and real-time not yet)
3. **Inertia React Back Office** — Admin panel for managing menu, categories, modifiers, employees, ingredients, and recipes ✅
4. **React Native POS** — Tablet-based point-of-sale for taking orders (multiple terminals) _(planned)_
5. **KDS Screen** — Kitchen Display System showing live orders with timers _(planned)_
6. **Receipt Printer** — Thermal printer (Goojrpt PT-210). The API issues printable receipt payloads and a reprint log; the actual printing is done by the POS app _(planned)_

### Core Business Logic

- **Single active shift** at a time (any staff opens it → tickets are created → any staff closes it once no ticket is open)
- **Real-time inventory** tracking with a reserve → deduct → restore system (direct, recipe, or untracked items)
- **Terminal-specific** POS screens (each tablet passes its `terminal_id` and sees only its own open tickets)
- **Unified KDS** (all terminals' orders visible to kitchen) _(planned)_
- **Split charges** (cash + GCash on the same ticket; one receipt per payment method; amounts-only, no per-item assignment)
- **Ticket merging** (fold 2+ open tickets in the same shift into one bill; the receipt lists every order number)
- **Auto-print receipts** after payment completion (API returns the printable payload in the payment response)
- **Receipt history** accessible on POS (no terminal filters) and Back Office (with filters)
- **Passcode-gated actions**: voiding a line and approving/rejecting a refund require a manager/admin 4-digit PIN

---

## 2. DATABASE SCHEMA

### Key Tables & Relationships

```
users (employees; login by username, bcrypt password, optional 4-digit bcrypt passcode)
├─ opens/closes → shifts
├─ creates → tickets
├─ authorizes voids → ticket_items (voided_by) and initiates them (voided_requested_by)
├─ requests → refunds
└─ approves/rejects → refunds

shifts (service sessions)
├─ contains → tickets, refunds, shift_transactions, receipts
└─ totals: computed live while open; snapshotted at close
   (revenue, cash, gcash, additions, expenses, refunds, expected_cash, discrepancy)

shift_transactions (cash additions & expenses, soft-deleted)
└─ belongs to → shift; audit: created_by / updated_by / deleted_by

categories (menu groups; is_visible_to_pos; type = menu | special)
└─ has many → items

items (menu items with direct, recipe, or no inventory)
├─ entry_mode: fixed | price (Fee item) | name_price (Custom item) — non-fixed only in special categories
├─ many-to-many → modifiers (item_modifier: per-item price_modifier, status, display_order)
├─ has many → ticket_items
├─ tracks → quantity/reserved_quantity for direct inventory
├─ uses → ingredient recipe requirements when inventory_type = recipe
└─ tracks → cost_price (for margin calculation)

modifier_groups (reusable groups, e.g. "Size"; is_required)
└─ has many → modifiers (a modifier is reusable across items)

ingredient_groups (ingredient categories)
└─ has many → ingredients

ingredients (shared raw-material stock)
├─ belongs to → ingredient_group
├─ tracks → decimal quantity/reserved_quantity and cost_per_unit
└─ connects to → items through item_ingredient

item_ingredient (recipe pivot)
└─ stores → quantity_required and matching unit per item/ingredient pair

tickets (open / paid / merged / cancelled orders)
├─ contains → ticket_items (line items)
├─ has many → charges (payment records, amounts only)
├─ merged_into_ticket_id / merged_by / merged_at (on merged sources)
├─ cancelled_by / cancelled_at
├─ reserves → inventory (per item, not per ticket)
└─ tracks → created_by, terminal_id, order_number (#001, resets per shift)

ticket_items (line items in ticket)
├─ snapshots → item_name (the typed name for a Custom item), item_cost_price, unit_price, line_type (item | fee | custom) at the time of adding
├─ has many → ticket_item_modifier (snapshotted modifier name + price)
├─ merged_from_ticket_id → the ticket a line originally belonged to
├─ voided_at / voided_by / voided_requested_by
└─ notes → KDS/back-office only, never on receipts

charges (payment records, one or more per ticket)
├─ payment_method: cash | gcash; amount; tendered_amount / change_due; payment_reference (GCash)
└─ has one → receipt

receipts (immutable snapshot per paid charge)
├─ receipt_number REC-YYYY-MM-DD-NNN (per-day sequence), full JSON payload
└─ has many → receipt_prints (append-only print/reprint log)

refunds (refund tracking with approval)
├─ references → shift, ticket, one charge (cash vs GCash known)
├─ has many → refund_items (ticket_item, quantity, amount)
└─ restores → inventory on approval
```

### Core Constraints

- One active shift at a time — DB unique index on a generated `shifts.is_open` column (1 while open, NULL once closed)
- Unique customer name among **open** tickets within a shift, across all terminals — auto-append: john → john2 → john3 (DB unique index on `(shift_id, open_name)`); a name can be reused once its ticket is no longer open
- Order numbers (`#001`, `#002`, …) are unique per shift and reset each shift
- Available qty = `quantity - reserved_quantity`; insufficient stock rejects reservations
- Recipe availability is the minimum complete-serving count across all linked ingredients
- Charges must sum to the recomputed ticket total **exactly, to the centavo** before payment is processed
- Payment is atomic: charges, inventory deduction, ticket close and receipt generation succeed or fail together
- Ticket merge: source lines move onto the target, sources become `merged` with zeroed money, discounts combine into one fixed amount, and receipts list every order number
- Item, modifier and price data are snapshotted onto ticket lines so later menu edits never change history
- **Special items:** an item in a `special` category never tracks inventory, cost, a recipe or modifiers. A Fee item (`entry_mode = price`) takes its amount from the cashier; a Custom item (`name_price`) takes its name and amount. The server rejects a price/name on any other item. A category's type cannot change once it has items. The ticket discount applies to every line, fees included

---

## 3. BUSINESS FLOW DIAGRAMS

### Order Creation Flow (Terminal-Specific)

```
POS Terminal 1                          POS Terminal 2
    ↓                                        ↓
Create Ticket "john"              Create Ticket "john"
(auto-name: john2 if exists)      (separate shift context)
    ↓                                        ↓
Add Items → Reserve Qty           Add Items → Reserve Qty
    ↓                                        ↓
Modify Qty/Discount               Modify Qty/Discount
    ↓                                        ↓
    └─────────────────────────────────────────┘
                    ↓
            Open Orders List
         (Each POS sees own)
            Terminal 1: john, john2
            Terminal 2: john, john3
```

### Payment & Receipt Flow

One API call — `POST /tickets/{id}/charges` — does all of the following in a single transaction:

```
Ticket Ready to Pay
    ↓
Send charges by amount (no per-item assignment):
  Charge 1 (Cash  ₱100, tendered ₱200)
  Charge 2 (GCash ₱75,  reference GC-123456)
    ↓
Server recomputes the total from live, non-voided lines
Validate: Charge Sum = Ticket Total (exact to the centavo)
    ↓
Create Charges (status = 'paid')
    ↓
Deduct Inventory + Clear Reserves
    ↓
Close Ticket (status = 'paid')
    ↓
Issue one Receipt per charge (immutable snapshot, REC-YYYY-MM-DD-NNN)
  — every receipt lists all ticket items; the discount is prorated per charge
  — if receipt generation fails, the whole payment rolls back
    ↓
Response carries each receipt's printable payload
    ↓
POS AUTO-PRINTS Receipt 1 (Cash) and Receipt 2 (GCash) → Goojrpt PT-210
POS shows the receipt on screen (for customer viewing)
    ↓
Receipt History (accessible to all terminals + back office)
```

### Ticket Merge Flow

```
Open Tickets:
  #001 - john (₱350)
  #002 - john2 (₱250)
  #003 - maria (₱400)

Cashier selects: "Merge #002 into #001"  (POST /tickets/{#001}/merge { merge_from_ticket_ids: [#002] })
    ↓
System moves #002's lines onto #001 (each line remembers where it came from)
Discounts from both tickets combine into one fixed amount; notes are kept, labelled by order number
    ↓
Merged Bill (#001 is the target):
  Order Numbers: #001, #002
  Items: john's items + john2's items
  Total: ₱600
    ↓
One Payment Process (cash + gcash split still works)
    ↓
#002 (the source) status → 'merged', money zeroed; #001 is paid
    ↓
Print ONE receipt (per charge) showing both order numbers
```

Only open tickets in the same shift can be merged. Merging never changes inventory. Merged sources do not block closing the shift.

### KDS (Kitchen) Flow

```
All POS Terminals
  ├─ Terminal 1: john (2x Fried Itik Large, 2x Rice)
  ├─ Terminal 2: maria (1x Pork Sinigang, 1x Rice)
  └─ Terminal 1: john2 (3x Lumpia)
         ↓
    KDS Screen (Real-time, All Orders)
    Shows:
      #001 john (PENDING - 2m 15s)
        └─ 2x Fried Itik Large + Special instructions
        └─ 2x Rice
      #002 maria (PENDING - 1m 45s)
        └─ 1x Pork Sinigang
        └─ 1x Rice
      #003 john2 (PENDING - 30s)
        └─ 3x Lumpia
         ↓
    Kitchen marks items done
    (Removes from their screen, doesn't affect payment)
         ↓
    When ALL items done → Order ready for pickup
    When Payment processed → Order moves to history
```

### Inventory Tracking

```
Item: Fried Itik
Initial: quantity=20, reserved=0, available=20

RESERVE (add to ticket):
  quantity=20, reserved=1 → available=19
  Display: "Stock: 20 (1 pending) - Available: 19"

RESERVE MORE (john2 adds):
  quantity=20, reserved=3 → available=17
  Display: "Stock: 20 (3 pending) - Available: 17"

DEDUCT (john's payment processed):
  quantity=19, reserved=2 → available=17
  (1 item removed from actual stock)

REFUND (john's refund approved):
  quantity=20, reserved=1 → available=19
  (1 item restored to inventory)

VOID / CANCEL (line voided or ticket cancelled before payment):
  reserved -= qty  (quantity unchanged)
```

`recipe` items do the same against their ingredients (decimal quantities); `none` items skip inventory entirely.

### Shift Expenses & Cash Additions

During an open shift, managers/admins can add cash additions (external funds) or record expenses from any POS terminal:

**Cash Additions** (e.g., owner deposits cash, cash from delivery):

```
Starting Cash:              ₱5,000
+ Sales (all terminals):    ₱1,250
+ Cash Additions:           ₱  500  ← Owner added ₱500
```

**Expenses** (e.g., supplies, repairs, deliveries):

```
- Expenses:                 -₱  200  ← Bought supplies
```

**Final Shift Summary:**

```
Starting Cash:              ₱5,000
+ Cash Sales:               ₱1,250   (cash charges only — GCash is not in the drawer)
+ Cash Additions:           ₱  500
- Expenses:                 -₱  200
- Approved Cash Refunds:    -₱    0
─────────────────────────────────────
EXPECTED TOTAL CASH:        ₱6,550

Discrepancy = Closing Cash (counted) − Expected Cash
```

Only **cash** refunds reduce expected cash; GCash refunds never touched the drawer. The shift also records total revenue, GCash total, and total refunds (all methods).

**Features:**

- Managers/admins only can add and edit via POS "Shift Settings" (delete has no role check in code yet — see API doc §13)
- Free-text reason (e.g., "Owner deposit", "Supply purchase")
- Track who added it and when (`created_by`, `updated_by`, `deleted_by`)
- Can edit/delete entries mid-shift; not allowed once the shift is closed
- Soft-delete (not shown in totals if deleted)
- Synced across all terminals (no real-time broadcast, but pulled on refresh)
- Visible at shift close for cash reconciliation; totals are computed live while open and snapshotted on close

---

## 4. TECHNOLOGY STACK

| Component                    | Technology                                          | Purpose                            |
| ---------------------------- | --------------------------------------------------- | ---------------------------------- |
| **Backend**                  | Laravel 12                                          | Back office + REST API (`/api/v1`) ✅ |
| **Database**                 | MySQL 8.0+ (SQLite in tests)                        | Persistent data storage            |
| **Authentication**           | Web session (back office) + Laravel Sanctum (POS API) | Username + password login; API issues bearer tokens ✅ |
| **Admin Panel**              | Inertia.js v2 + React + TypeScript                  | Server-driven back office ✅       |
| **Admin Styling**            | TailwindCSS + shadcn-style components               | Professional UI ✅                 |
| **Testing**                  | Pest                                                | Feature + unit tests ✅            |
| **POS App**                  | React Native (Expo)                                 | Tablet-optimized checkout _(planned)_ |
| **KDS Screen**               | React or Expo                                       | Kitchen display (large screen) _(planned)_ |
| **State Management**         | Zustand or Context                                  | Client-side state _(planned)_      |
| **HTTP Client**              | Axios                                               | API requests with interceptors _(planned)_ |
| **Thermal Printer**          | Goojrpt PT-210                                      | Receipt printing via Bluetooth/USB _(planned)_ |
| **Receipt Printing Library** | react-native-thermal-receipt-printer or Goojrpt SDK | Print integration _(planned)_      |
| **Real-time Updates**        | Laravel WebSockets + Pusher JS (Laravel Reverb is a first-party alternative) | Live order broadcast _(planned; nothing installed yet)_ |
| **Deployment**               | Intel NUC (Docker)                                  | Local network hosting (after development) |
| **Network**                  | WiFi Mesh (TP-Link Deco M5)                         | POS tablet connectivity            |

---

## 5. AUTHENTICATION & ROLES

### Role Matrix

This matrix reflects what the code enforces today. "Manage" means the back-office and inventory management routes, which check `admin or manager`.

| Action                                         | Admin | Manager | Cashier |
| ---------------------------------------------- | ----- | ------- | ------- |
| Login (POS API and back office)                | ✅    | ✅      | ✅      |
| Manage Users                                   | ✅    | ✅      | ❌      |
| Manage Menu (Items/Categories/Modifiers)       | ✅    | ✅      | ❌      |
| Manage Ingredients, Ingredient Groups, Recipes | ✅    | ✅      | ❌      |
| Open Shift                                     | ✅    | ✅      | ✅      |
| Close Shift                                    | ✅    | ✅      | ✅      |
| Add/Edit Shift Expenses & Cash Additions       | ✅    | ✅      | ❌      |
| Create Tickets, Add Items, Discount, Merge, Cancel | ✅ | ✅      | ✅      |
| Take Payment (charge a ticket)                 | ✅    | ✅      | ✅      |
| Void an Item on an Open Ticket                 | passcode | passcode | needs a manager/admin passcode |
| Request Refunds                                | ✅    | ✅      | ✅      |
| Approve / Reject Refunds                       | passcode | passcode | ❌ (a cashier can never approve) |
| View Receipts / Reprint                        | ✅    | ✅      | ✅      |
| View Reports (planned)                         | ✅    | ✅      | ❌      |

**Passcodes:** a 4-digit PIN stored hashed on the user. For a void or refund decision the POS sends `approver_id` + `passcode`; the server checks that the approver is an admin/manager and that the PIN matches. The signed-in cashier is recorded as the requester, the approver as the authorizer.

**Terminal isolation:** any authenticated user may create tickets. Isolation between terminals is achieved by each POS sending its `terminal_id` when creating tickets and when listing them; it is not yet enforced from the token.

---

## 6. POS TERMINAL SPECIFICATIONS

### Screen Hierarchy (React Native) — _planned; the API behind each screen is implemented_

Each POS device has a fixed `terminal_id` (e.g. `POS-01`) that it sends when creating and listing tickets.

#### 1. **LoginScreen**

- Username + password (`POST /auth/login`, optionally with `device_name`)
- Token saved to secure storage
- Redirect to `ShiftSetupScreen`

#### 2. **ShiftSetupScreen**

- `GET /shifts/active` — if a shift is open → sync menu → go to MenuScreen
- If none (404) → show "Open New Shift" form
- Input starting cash → `POST /shifts`
- Call `GET /items` to load the menu (there is no `shift_id` parameter)

#### 3. **MenuScreen** (Main Dashboard)

- Horizontal category pills (derived from each item's `category`; there is no POS categories endpoint yet)
- Grid of items (image, name, price); `unavailable` items are shown greyed out and cannot be ordered
- Show `available_stock` (`null` = untracked)
- **Manual sync button** (re-fetch `GET /items`; the only sync mechanism until real-time exists)
- Tap item → choose modifiers (a group with `is_required` must be satisfied) → `POST /tickets/{id}/items`, which reserves stock
- **Specials section:** items whose `category.type = special` are shown as their own section. By `entry_mode`: `fixed` adds immediately; `price` (Fee item) opens an amount keypad pre-filled with `base_price`; `name_price` (Custom item) opens a name + amount form. Send `unit_price` / `custom_name` with the add-item call
- Cart badge (top-right) → go to CartScreen
- **Terminal sees only own open tickets** in sidebar (`GET /tickets?terminal_id=…`)
- Receipt history is **not** terminal-filtered: every terminal sees every receipt (`GET /receipts`)

#### 4. **CartScreen**

- Items with quantities and modifiers
- Edit quantity (+ / -) — _the API has no quantity-edit endpoint yet_ (today: void + re-add)
- Remove item — requires a manager/admin passcode (`DELETE /tickets/{id}/items/{ticketItemId}`)
- Apply discount (₱ or %) — `PATCH /tickets/{id}/discount`; a percent wins over a fixed amount
- Order name (auto-suffixed by the server if a same-named ticket is open: john → john2)
- Order type selector (dine-in / takeout)
- Per-line notes (KDS only — never printed on the receipt)
- Fee lines are shown as fees; Custom lines show the typed name
- Checkout button

#### 5. **CheckoutScreen**

- Ticket summary with items
- Subtotal − discount = total (the server recomputes the total; never trust the client's)
- Payment method buttons:
    - Cash (full) — optional tendered amount, change is computed
    - GCash (full) — payment reference required
    - Split (cash + gcash) — enter an **amount** for each method
- No per-item assignment: every receipt lists all items with a prorated discount
- Validate charge sum = total (exact to the centavo; otherwise the API returns 409)
- `POST /tickets/{id}/charges`; the response contains one receipt payload per charge
- **AUTO-PRINT Receipt** for each charge after confirmation
- **Show Receipt on Screen** (can print again)

#### 6. **OrderHistoryScreen**

- List of open tickets (own terminal only)
- Can merge 2+ tickets (`POST /tickets/{targetId}/merge`)
- Can cancel ticket (before payment; releases the reservation)
- Can view/edit in-progress tickets

#### 7. **ReceiptHistoryScreen**

- View past receipts — all terminals (no terminal filter on POS)
- Filter by date range / payment method; search by order number, customer name or receipt number (all supported by `GET /receipts`)
- Tap to view/reprint (`POST /receipts/{id}/reprint` logs the duplicate; the POS adds the "DUPLICATE RECEIPT" watermark)

#### 8. **Shift/Settings Screen** (Expense/addition controls: Manager/Admin only; closing a shift is open to every role)

- Current shift info
- Close shift button (confirmation modal; enter counted `closing_cash`; blocked while any ticket is still open)
- **Add Expense button** → modal with reason & amount
- **Add Cash Addition button** → modal with reason & amount
- List of today's expenses & additions (can edit/delete)
- Running shift totals (from `GET /shifts/active`):
    - Starting Cash
    - Sales (all terminals; cash and GCash separately)
    - Cash Additions
    - Expenses
    - Approved cash refunds
    - Expected Total Cash

#### 9. **RefundScreen**

- Pick a paid ticket and one of its charges, choose the lines/quantities/amounts to refund, add a reason (`POST /refunds`; any role)
- Pending refunds list (`GET /refunds?status=pending`)
- Approve / reject with a manager/admin approver + passcode

---

## 7. BACK OFFICE (Inertia React) SPECIFICATIONS

The back office is a set of Inertia pages served by session-authenticated Laravel routes (`routes/web.php`, `auth` middleware). It does not use the `/api/v1` token API. Login is by **username** + password. Menu and inventory management is restricted to admin/manager.

### Implemented ✅

| Route | Page | What it does |
| ----- | ---- | ------------ |
| `/login` | Login | Username + password (session) |
| `/dashboard` | Dashboard | Placeholder only — no stats yet |
| `/back-office` | Hub | Links to category, item, modifier and ingredient management |
| `/category-management`, `/create-category`, `/categories/{id}/edit` | Categories | CRUD with `name`, `type` (Menu or Special; locked once the category has items), `status` (active/inactive) and the `is_visible_to_pos` toggle; item counts |
| `/item-management`, `/create-item`, `/items/{id}/edit` | Items | Table of name, price, cost, margin, category, stock, available stock and status. Create/edit/delete with `inventory_type` (`direct` \| `recipe` \| `none`), stock quantities, status (`available` \| `unavailable` \| `hidden`), and attached modifiers with a per-item price and display order. Recipe items get their ingredient requirements (ingredient, quantity, unit). In a **Special category** the form hides inventory, cost, quantity, recipe and modifiers and shows a **Pricing** choice — fixed amount, *Fee item* (cashier enters the amount) or *Custom item* (cashier enters the name and amount); `base_price` becomes the "Default amount" |
| `/modifier-management`, `/create-modifier-group`, `/create-modifier`, `/modifier-groups/{id}/edit`, `/modifiers/{id}/edit` | Modifier groups & modifiers | Reusable groups (with `is_required`) and modifiers; the price is set per item when a modifier is attached |
| `/ingredient-management`, `/create-ingredient-group`, `/create-ingredient`, `/ingredient-groups/{id}/edit`, `/ingredients/{id}/edit` | Ingredient groups & ingredients | CRUD; ingredients carry a unit (`piece`, `kg`, `gram`, `liter`, `ml`), decimal quantity and `cost_per_unit` |
| `/employee-management`, `/users/create`, `/users/{id}/edit` | Employees | CRUD. Creates `manager` and `cashier` accounts (admins are seeded). A 4-digit passcode can only be set on a manager. Status active/inactive |
| `/settings/*` | Profile, password, appearance | Starter-kit account settings |

Notes:

- Margin is calculated on the frontend: `(base_price − cost_price) / base_price × 100`. For recipe items the cost is the sum of `cost_per_unit × quantity_required` across the recipe.
- Item images are stored as an `image_url` string. **File upload is not implemented yet.**
- Restocking a direct item is done by editing its quantity on the item form; ingredient stock is adjusted on the ingredient form. There is no separate bulk-adjust screen.
- The back-office item list has a client-side search (name or category) but no category/status filter; the POS `GET /api/v1/items` supports a `category_id` filter.

### Known gaps (back office)

- **Public registration is still enabled.** `GET/POST /register` (Laravel starter kit, `routes/auth.php`) lets anyone who can reach the server create an account and log in. User creation is meant to be manager/admin-only via `/users/create`; the starter-kit route should be removed. Its tests (`RegistrationTest`, and the email-verification / password-reset tests) are stale and fail.
- **Back-office page routes only require login, not a role.** Create/update form requests are gated by `admin or manager`, but the read-only pages (`/item-management`, `/employee-management`, …) are reachable by any authenticated user, including a cashier.

### Planned (data and API exist; no Inertia pages yet)

#### `/shifts`

- Historical shift list (with totals)
- Click to view details: opening time, closing time, total revenue, cash/gcash breakdown, additions, expenses, refunds, expected vs. counted cash, discrepancy
- Shift close report

#### `/orders` (Sales/Receipts)

- All orders (not terminal-filtered, unlike POS)
- Filter by date, payment method, shift (the receipt-history endpoint already supports these)
- Click order to see receipt details
- Search by order number or customer name

#### `/refunds`

- Pending refunds list (awaiting approval)
- Approve/reject (passcode-gated, as on the POS)
- History of approved/rejected refunds

#### `/dashboard` stats

- Active shift status
- Today's stats: orders count, total revenue, cash/gcash breakdown
- Quick action buttons: Open Shift, Close Shift, View Reports
- Pending refunds widget

#### `/reports` (Optional v1)

- Daily sales
- Item popularity
- Payment method breakdown
- Employee performance (if tracking)

---

## 8. KDS SCREEN (Kitchen Display System) — _planned_

> Not built. The backend already stores what the KDS needs (ticket lines with `notes`, modifiers, `created_at`, `order_type`, voided lines), but there is no KDS read endpoint (`GET /kds/orders`) and no real-time channel yet. Completion state stays UI-only by design (no DB timestamp in v1). The feed must be ordered strictly by `created_at` ASC, exclude paid/cancelled/merged tickets and voided lines, and expose no prices.

### Display

- Full-screen, large fonts, touch-friendly
- Landscape orientation (TV/monitor)
- Real-time order list (all terminals)

### Order Card Layout

```
┌──────────────────────────────────────┐
│ #001 - john          DINE-IN 2m 30s │
├──────────────────────────────────────┤
│ ☐ 2x Fried Itik (Large)              │
│   Special: Extra crispy              │
│ ☐ 2x Rice                            │
│ ✓ 1x Lumpia                          │
└──────────────────────────────────────┘
```

### Interactions

- Tap ☐ to mark item done (✓)
- Tap ✓ to mark incomplete (undo)
- Once all items ✓ → order turns gray/moves to bottom
- Swipe to hide completed orders
- Auto-refresh on new orders (WebSocket)

### Features

- Color-coded by age (green <5m, yellow <10m, red >10m)
- Auto-timer from order creation
- No payment/pricing info visible
- Kitchen-only view (no terminal info)

---

## 9. RECEIPT SPECIFICATIONS

### Auto-Print Flow

1. Payment processed (`POST /tickets/{id}/charges`) → closes ticket
2. **Server** issues one immutable receipt per charge and returns each printable `payload` in the response (implemented ✅)
3. **POS app** connects to Goojrpt PT-210 via Bluetooth/USB _(planned)_
4. **POS app prints each receipt automatically** (no user action needed) _(planned)_
5. Also displays on POS screen for customer verification _(planned)_
6. If the printer is offline the POS falls back to the on-screen digital receipt; the receipt is already stored, so it can be reprinted later

### Receipt Format (Per Charge)

The server supplies the receipt data (see the payload in `UNIFIED_API_ENDPOINTS.md` §7). The restaurant name/address/phone header and the thank-you footer are **not** stored in the payload — the POS app renders them. Notes are never included.

```
═══════════════════════════════════════
        RESTAURANT NAME
        123 Main Street
        +63 912 345 6789
═══════════════════════════════════════
RECEIPT REC-2026-09-25-001 (CASH)
Order #001 - john (Dine-in)
Date: 2026-09-25 14:35:00
Cashier: Maria
═══════════════════════════════════════

1x Fried Itik (Large)        ₱150.00
1x Rice                      ₱ 50.00
                            ─────────
Subtotal                     ₱200.00
Discount (prorated)          -₱25.00
                            ─────────
TOTAL (CASH)                 ₱175.00
Tendered                     ₱200.00
Change                       ₱ 25.00

═══════════════════════════════════════
Thank you! Please come again.
═══════════════════════════════════════
```

For GCash the payment line shows the reference instead of tendered/change.

### For Split Charges

- Each charge prints **separately** (Receipt 1: Cash, Receipt 2: GCash)
- Both show same order number and items
- Each receipt's subtotal − discount = that charge's amount; the ticket discount is prorated by charge amount in whole centavos, with the last charge taking the remainder so the shares add up to the ticket discount exactly

### For Merged Tickets

- One receipt showing both order numbers (#001, #002) via `order.merged_from`
- Combined items and totals
- If split: two receipts with both order numbers

### Receipt History (POS)

- Accessible to all terminals (no filter)
- Shows: date, order number, customer name, total, payment method
- Paginated; filter by shift, terminal, payment method, date range; search by order number, customer name or receipt number
- Click to view full receipt details + reprint option
- Reprint is logged (`receipt_prints`, `is_reprint = true`) and the API returns `watermark: "DUPLICATE RECEIPT"`; the stored receipt is never modified

---

## 10. REAL-TIME UPDATES (WebSockets) — _planned_

> Not built: there is no broadcasting code and no WebSocket package installed. Until then, POS terminals rely on the manual sync button/polling (`GET /items`, `GET /tickets`, `GET /shifts/active`). The events below are the intended design. Note that `beyondcode/laravel-websockets` is effectively unmaintained; Laravel Reverb is the first-party, Pusher-protocol-compatible alternative — decide before implementing (CLAUDE.md lists WebSockets via Laravel as a locked decision).

### Broadcasting Channels

- `shift.{shift_id}` → all users in same shift
- `terminal.{terminal_id}` → specific POS terminal (optional)

### Events Broadcast

1. **ticket.created** — New ticket created

    ```json
    { "event": "ticket.created", "data": { ticket object } }
    ```

2. **ticket.updated** — Items added/removed, discount changed

    ```json
    { "event": "ticket.updated", "data": { ticket object } }
    ```

3. **ticket.paid** — Order completed

    ```json
    { "event": "ticket.paid", "data": { ticket_id, order_number } }
    ```

4. **ticket.merged** — Tickets merged

    ```json
    { "event": "ticket.merged", "data": { new_ticket_id, merged_from: [#001, #002] } }
    ```

5. **inventory.updated** — Item reserved/deducted

    ```json
    { "event": "inventory.updated", "data": { item_id, quantity, reserved_quantity } }
    ```

6. **item.completed** — Kitchen marks item done (KDS)

    ```json
    { "event": "item.completed", "data": { ticket_id, ticket_item_id } }
    ```

7. **refund.requested** — Refund pending approval

    ```json
    { "event": "refund.requested", "data": { refund object } }
    ```

8. **refund.approved** — Refund approved by admin
    ```json
    { "event": "refund.approved", "data": { refund_id, ticket_id } }
    ```

---

## 11. V1 SCOPE (MVP)

Legend: `[x]` implemented and tested · `[~]` implemented on the server/API, client side still to build · `[ ]` not started.

### Must-Have

**Backend + back office**

- [x] User authentication (session for back office, Sanctum tokens for the POS API; username login)
- [x] Role rules + manager/admin passcodes (void an item, approve/reject a refund)
- [x] Menu management (items, categories, modifier groups & modifiers)
- [x] Inventory: direct / recipe / none items, ingredient groups, ingredients, recipes
- [x] Shift open/close with a single-open-shift DB guard
- [x] Shift expenses & cash additions (manager/admin) — API; soft delete
- [x] Shift cash reconciliation with expected totals, cash refunds and discrepancy
- [x] Ticket creation with auto-duplicate names and per-shift order numbers
- [x] Item add/remove from ticket (inventory reserve/release)
- [x] Discount application (fixed amount or percent)
- [x] Split charge payment (cash + gcash), atomic, exact-to-the-centavo
- [x] Inventory decrement on payment
- [x] Ticket merging and ticket cancel
- [x] Receipt issuing (one per charge), immutable snapshot, prorated discount, notes excluded
- [x] Receipt history for all terminals (filter/search/paginate) and reprint log with watermark flag
- [x] Refund request + passcode approval/rejection, inventory restored, cash refunds netted from expected cash
- [x] Special items: Special categories with Fee items (cashier enters the amount) and Custom items (cashier enters the name and amount) — API, receipts and back office

**Still to build**

- [~] Terminal isolation (POS sees own orders only) — `terminal_id` filter works; not enforced by token
- [ ] Ticket line quantity edit (API)
- [ ] Categories endpoint for the POS (API)
- [ ] KDS order feed + item completion UI (UI-only completion)
- [ ] Real-time WebSocket updates
- [ ] React Native POS app, including auto-print to the thermal printer
- [ ] KDS app
- [ ] Back-office pages: shifts, orders/receipts, refunds, dashboard stats

### Nice-to-Have (v1)

- [x] Receipt reprint with watermark (API flag; the POS renders the watermark)
- [x] Receipt history filters (API)
- [x] Cost price → margin calculation (frontend, item management page)
- [ ] Order status color-coding (age-based)
- [ ] Receipt history filters in the back office UI
- [ ] Image uploads for items (only an `image_url` string today)

### Defer to v1.1+

- [ ] Offline order queueing
- [ ] Advanced analytics/reporting
- [ ] Leave/attendance scheduling
- [ ] Barcode/QR code scanning
- [ ] Multi-location support
- [ ] Delivery integration (GrabFood, Foodpanda)
- [ ] Kitchen staff performance metrics
- [ ] Inventory alerts/reordering

---

## 12. ERROR HANDLING

### Standard Response Format (`/api/v1`)

```json
{
    "success": false,
    "message": "User-friendly error message",
    "errors": { "field": ["Validation error 1"] }
}
```

`errors` is only present on validation failures. Successful responses are `{ "success": true, "data": …, "meta": … }`.

### Common Errors

- **401 Unauthorized** — Missing/invalid token
- **403 Forbidden** — Insufficient role, or a bad passcode / approver who is not admin/manager
- **404 Not Found** — Resource doesn't exist, or no active shift where one is required
- **409 Conflict** — Business-rule violation: shift already open, no active shift, open tickets block close, charge total mismatch, insufficient stock, ticket not open, refund already decided, tickets in different shifts
- **422 Unprocessable Entity** — Validation failed
- **429 Too Many Requests** — Login throttled (6 attempts per minute)

Full catalog: `UNIFIED_API_ENDPOINTS.md` §10.

---

## 13. DEPLOYMENT ARCHITECTURE

Local deployment happens after the application is developed. Target layout:

```
WiFi Mesh (TP-Link Deco M5)
│
├─ Intel NUC (LAN)
│  ├─ Laravel app: REST API (/api/v1) + Inertia back office (Port 8000)
│  ├─ MySQL Database
│  └─ WebSocket server (Port 6001) — planned
│
├─ POS Tablet 1 (WiFi)
│  ├─ React Native Expo App
│  └─ Goojrpt PT-210 Printer
│
├─ POS Tablet 2 (WiFi)
│  ├─ React Native Expo App
│  └─ Goojrpt PT-210 Printer
│
├─ KDS Screen (WiFi)
│  └─ React/Expo app — planned
│
└─ Admin Laptop (WiFi)
   └─ Inertia back office (Browser, same Laravel app)
```

### Network Setup

- All devices on same LAN via WiFi mesh
- API accessible at `http://nuc-ip:8000/api/v1`; back office at `http://nuc-ip:8000`
- WebSockets at `ws://nuc-ip:6001` (planned)
- No offline queue: the POS needs a live connection to the NUC (locked decision). Remote access via Tailscale.

---

## 14. NOTES FOR DEVELOPMENT

### Key Implementation Points

1. **Terminal ID Tracking**

    - Each POS tablet sends `terminal_id` on ticket creation
    - Used to isolate orders and receipts per terminal

2. **Inventory Reserve Logic**

    ```
    Add item to ticket: reserved_qty += qty
    Void item / cancel ticket: reserved_qty -= qty
    Process payment: qty -= qty, reserved_qty -= qty
    Approve refund: qty += qty  (reserved is not touched)
    ```

    For recipe items the same happens on the ingredients; `none` items skip it.

3. **Ticket Merge**

    - Select multiple open tickets (same shift)
    - Merge into one (user chooses the target; the others become "merged")
    - Totals are recalculated on the target; discounts combine into one fixed amount
    - Keep both order numbers in display and on the receipt

4. **Split Charges**

    - One ticket, multiple charges (cash + gcash), sent as amounts
    - Each charge gets its own receipt listing every item with a prorated discount
    - No per-item assignment (dropped by design)
    - The server validates the sum against the recomputed total before payment

5. **Receipt Printing**

    - Auto-print after payment (no user action needed)
    - Use Goojrpt PT-210 SDK for React Native
    - Fallback to digital receipt if printer offline
    - The server returns the printable payload and logs reprints; it does not talk to the printer

6. **KDS Item Completion**

    - Store in UI state only (no DB timestamp in v1)
    - When item marked done: remove from kitchen view
    - On page refresh: re-fetch orders and re-calculate completion UI

7. **Database Transactions** ✅

    - Payment, inventory deduction, ticket close and receipt generation run in one transaction
    - Inventory rows, tickets, shifts and refunds are locked (`lockForUpdate`) — tickets in a fixed id order for merges — to prevent races between terminals

8. **WebSocket Broadcasting** _(planned)_
    - Broadcast to `shift.{shift_id}` channel
    - All terminals in same shift get real-time updates
    - Exclude sender with `.toOthers()` to avoid echo

9. **Money handling**

    - Model-backed API responses return money as decimal strings on MySQL; computed values are numbers
    - Charges and refunds are compared in whole centavos

---

## 15. TESTING CHECKLIST

Automated (Pest) coverage today is marked ✅; the rest is manual or still to write. Run the suite with `php artisan test`.

- [ ] User login (API) & role-based access — _no dedicated API login test yet_
- [ ] Open shift, sync menu — _menu ✅ (`ItemApiTest`); shift open/close untested_
- [ ] Create ticket with auto-duplicate names (john → john2) — _no ticket create test yet_
- [x] Add items (reserves inventory) — direct and recipe modes
- [ ] Edit item quantity — _endpoint not built_
- [ ] Apply discount — _ticket discount untested; discount proration ✅ (`ReceiptTest`)_
- [x] Split payment (cash + gcash)
- [x] Charge sum must equal total exactly
- [x] Process payment (issues one receipt per charge; rollback if receipt fails)
- [x] Verify inventory deducted; a paid ticket can't be charged twice
- [x] Merge tickets (rules, chained merges, single receipt with all order numbers)
- [ ] Cancel ticket (unreserve inventory) — _untested_
- [ ] Void item with passcode — _untested_
- [ ] Terminal 1 sees own orders, KDS sees all — _KDS not built_
- [ ] Kitchen marks item done (UI only) — _KDS not built_
- [ ] Request refund, manager approves (restore inventory; cash refund reduces expected cash) — _untested_
- [x] View receipt history (all terminals visible), filters, search, reprint log
- [ ] WebSocket broadcasts on new order — _not built_
- [ ] Close shift, verify totals (incl. blocked while tickets are open) — _partly covered by the merge test; otherwise untested_
- [ ] Printer offline, fallback to digital receipt — _POS app_
- [ ] Token expiry & re-login flow — _POS app_

Known failing tests (stale or affected by the items-route auth change): starter-kit registration / email verification / password reset / profile tests, `CategoryTest`, `CategoryServiceTest`, and `ItemApiTest > the items endpoints require authentication`.

---

## 16. CONTACT & SUPPORT

**For questions on this spec:**

- Review detailed sections
- Check API endpoint documentation
- Refer to database schema diagrams
- Test incrementally (don't wait for full integration)

---

**End of Enhanced Specification v1.2**
