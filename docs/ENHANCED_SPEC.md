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
- **Account-bound** POS screens (a cashier sees only the tickets they opened; a manager/admin logged into a POS sees every ticket)
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
├─ snapshots → item_name (the typed name for a Custom item), item_cost_price, unit_price (item_cost_price: a recipe item's ingredient cost, otherwise cost_price), line_type (item | fee | custom), is_stockless (picked with a stockless variant: no stock, ₱0 cost) at the time of adding
├─ has many → ticket_item_modifier (snapshotted modifier name + price, plus `is_stockless_variant`)
├─ has many → ticket_item_ingredients (recipe lines, written at payment: ingredient, quantity used, unit, cost per unit; refunds restock from it)
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

- Managers/admins only can add and edit via POS "Shift Settings"; deleting an entry is open to any authenticated staff (deliberate asymmetry — a cashier can remove their own mistaken entry without waiting for a manager)
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

This matrix reflects what the code enforces today. "Manage" means the back-office and inventory management routes, which check `admin or manager` — every read, create, update and delete route for categories, items, modifiers, modifier groups, ingredients, ingredient groups and users carries a `can:` middleware backed by a Policy (`App\Policies\*`, all composing `AuthorizesBackOffice`), not just the create/update form requests. Only the dashboard, the back-office hub and account settings stay open to every role.

| Action                                         | Admin | Manager | Cashier |
| ---------------------------------------------- | ----- | ------- | ------- |
| Login to the POS (API)                         | ✅    | ✅      | ✅      |
| Login to the back office                       | ✅    | ✅      | ❌ (refused: "Cashier accounts sign in on the POS only.") |
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
| View Reports & Dashboard                       | ✅    | ✅      | ❌      |

**Passcodes:** a 4-digit PIN stored hashed on the user. For a void or refund decision a manager/admin types their passcode on the POS and the POS sends only `passcode`; the server checks it against every active admin/manager and records the one it belongs to as the approver. Cashiers and inactive users never match. A passcode that matches nobody is refused (`403`); one that matches more than one approver is also refused (`403`) until it is changed in the back office, so passcodes must be unique among active managers/admins (the Employees form enforces this). Five failed attempts in a minute by the signed-in user return `429`. The signed-in cashier is recorded as the requester, the approver as the authorizer.

**Ticket access (server-enforced, account bound):**
- **A cashier sees and acts only on tickets they opened** (`created_by`). Another cashier's ticket is `404`, on the list and on every per-ticket action, including payment.
- **A manager or admin sees and acts on every ticket,** from any POS login: oversight, and settling a tab when its cashier has left.
- **Merging:** a cashier may merge only tickets they opened; a manager/admin may merge any tickets in the same shift.
- **Not scoped:** KDS, receipts and refunds see every ticket.
- **`terminal_id`** is only a label the POS sends on creation and an optional list filter; it never decides access.

---

## 6. POS TERMINAL SPECIFICATIONS

### Screen Hierarchy (React Native) — _planned; the API behind each screen is implemented_

Each POS device has a fixed `terminal_id` (e.g. `POS-01`) that it sends when creating tickets, as a label. Which tickets a screen shows follows the logged-in account, not the device.

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
- **Cashier sees only their own open tickets** in the sidebar (`GET /tickets`, scoped server-side by account); a manager/admin sees everyone's
- Receipt history is **not** terminal-filtered: every terminal sees every receipt (`GET /receipts`)

#### 4. **CartScreen**

- Items with quantities and modifiers
- Edit quantity (+ / -) via `PATCH /tickets/{id}/items/{ticketItem}` — no passcode; dropping to zero still means voiding the line with a manager/admin passcode
- Remove item — a manager/admin types their passcode; the passcode alone identifies them (`DELETE /tickets/{id}/items/{ticketItemId}` with `{ "passcode" }`; `meta.approver` in the response names who approved)
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
- A ticket discounted all the way to a **₱0 total** (a comp) shows no payment buttons to pick between — one **"Close (no charge)"** action sends a single `amount: 0` charge so the order still closes and stock still comes off the shelf
- `POST /tickets/{id}/charges`; the response contains one receipt payload per charge
- **AUTO-PRINT Receipt** for each charge after confirmation
- **Show Receipt on Screen** (can print again)

#### 6. **OrderHistoryScreen**

- List of open tickets (own tickets for a cashier; all tickets for a manager/admin)
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
- Approve / reject with a manager/admin passcode only — the server identifies the approver from it (`meta.approver` in the response)

---

## 7. BACK OFFICE (Inertia React) SPECIFICATIONS

The back office is a set of Inertia pages served by session-authenticated Laravel routes (`routes/web.php`, `auth` middleware). It does not use the `/api/v1` token API. Login is by **username** + password. Menu and inventory management is restricted to admin/manager.

### Implemented ✅

| Route | Page | What it does |
| ----- | ---- | ------------ |
| `/login` | Login | Username + password (session). Active managers/admins only: a cashier is refused ("Cashier accounts sign in on the POS only.") and so is an inactive account; a session that later belongs to one (deactivated or demoted) is signed out on its next request (`EnsureBackOfficeUser`). There is no self-registration, email verification or password reset; accounts are created and passwords reset by a manager/admin on the Employees pages |
| `/dashboard` | Dashboard | Landing page, refreshes every minute; sales count when paid. **Shift:** open since/by, cash the drawer should hold, open orders (or "No shift open"). **Today so far vs the same clock time yesterday:** net sales, tickets paid, average ticket, gross profit + margin, net profit, approved refunds (▲/▼ change). **Sales by hour** (today vs all of yesterday), **payment mix** (cash vs GCash), **top 5 items** today. **Needs attention:** refunds waiting for approval (oldest wait), kitchen orders waiting 20+ min, raw materials and direct-stock items at or below their **reorder level**, dishes sold today with no cost set - each links to where it's fixed. **Last 7 days:** net sales and net profit per day. No open/close-shift buttons: shifts stay on the POS |
| `/back-office` | Hub | Four management cards - Categories, Items (by status), Modifiers, Raw materials - each showing its counts and linking to its management page, then their health (`MenuHealthService`): **Needs fixing** - recipe items with no ingredients, items marked available that can't be made (out of direct stock, or a raw material ran out, counting reserved stock), items with no cost (recipe: raw materials with no cost; special items excluded), required modifier groups with no active modifiers, hidden/inactive categories with available items, raw materials no recipe uses - each linking to its edit page, or "Everything's set up correctly"; and **Stock** - every raw material and direct-stock item with on hand, reserved, available and reorder level, sorted Out, Low, then OK, with a "Low & out only" filter |
| `/category-management`, `/create-category`, `/categories/{id}/edit` | Categories | CRUD with `name`, `type` (Menu or Special; locked once the category has items), `status` (active/inactive) and the `is_visible_to_pos` toggle; item counts |
| `/item-management`, `/create-item`, `/items/{id}/edit` | Items | Table of name, price, cost, margin, category, stock, available stock and status. Create/edit/delete with `inventory_type` (`direct` | `recipe` | `none`), stock quantities (plus an optional reorder level for direct stock; the list shows "Low" at or below it),| `recipe` \| `none`), stock quantities, status (`available` \| `unavailable` \| `hidden`), and attached modifiers with a per-item price and display order. Recipe items get their ingredient requirements (ingredient, quantity, unit). In a **Special category** the form hides inventory, cost, quantity, recipe and modifiers and shows a **Pricing** choice — fixed amount, *Fee item* (cashier enters the amount) or *Custom item* (cashier enters the name and amount); `base_price` becomes the "Default amount" |
| `/modifier-management`, `/create-modifier-group`, `/create-modifier`, `/modifier-groups/{id}/edit`, `/modifiers/{id}/edit` | Modifier groups & modifiers | Reusable groups (with `is_required`) and modifiers; the price is set per item when a modifier is attached. A modifier can be a **Stockless variant** (checkbox; "Stockless" badge on the list): e.g. "Lagi" - the line it's picked on takes no stock, costs ₱0, is always ₱0 in price (forced on every dish it's attached to; the item form shows "₱0 · stockless" instead of a price box), and it shows in the kitchen but never on the receipt |
| `/ingredient-management`, `/create-ingredient-group`, `/create-ingredient`, `/ingredient-groups/{id}/edit`, `/ingredients/{id}/edit` | Ingredient groups & ingredients | CRUD; ingredients carry a unit (`piece`, `kg`, `gram`, `liter`, `ml`), decimal quantity, `cost_per_unit` and an optional reorder level (the list shows "Low" at or below it) |
| `/employee-management`, `/users/create`, `/users/{id}/edit` | Employees | CRUD. Creates `manager` and `cashier` accounts (admins are seeded). A 4-digit passcode can only be set on a manager and must not already belong to another active manager/admin (the POS identifies the approver from the passcode alone). Status active/inactive |
| `/shifts`, `/shifts/{id}` | Shifts | View only, admin/manager. The list shows every shift newest first (20 per page): opened/closed time, opened by, starting cash, revenue, cash, GCash, expected cash, counted cash and discrepancy. The detail page is the shift close report (prints without the sidebar): the cash-drawer breakdown (starting cash + cash sales + additions − expenses − cash refunds = expected cash, then counted cash and the discrepancy), revenue/GCash/all refunds, ticket counts by status, and the expenses/additions and refunds lists. An open shift shows live totals; a closed shift shows its closing snapshot. Opening/closing a shift and cash movements stay on the POS. The ticket counts link to the matching filtered Tickets list |
| `/tickets`, `/tickets/{id}` | Tickets | View only, admin/manager. Every ticket from every terminal and cashier (not account-bound like the POS), newest first, 20 per page: order number, customer, order type, item count, status, opened/closed time, cashier, terminal, payment method(s) and total. Filters: status, shift, payment method, opened date range, and search by order number, customer name or receipt number. The detail page shows the lines (modifiers, kitchen notes, Fee/Custom labels, lines merged in from another ticket, voided lines with who approved/requested the void), subtotal/discount/total, payments (method, tendered, change, reference, cashier, receipt number, and a "View receipt" link), refunds (status, lines, reason, requested/decided by) and merge links (merged into / merged from). The payments and refunds panels appear only when the ticket has any. Cost price is not shown. Creating, changing, paying, cancelling and refunding tickets stay on the POS |
| `/receipts/{id}` | Receipt | Admin/manager, opened from a ticket's payments (no receipts list - tickets are searchable by receipt number instead). Shows the receipt exactly as issued (the stored `receipts.payload`, receipt-width layout, no kitchen notes) and its print history (the original print, then each duplicate with who printed it). **Print duplicate** logs a reprint through `ReceiptService::ReprintReceipt` - the same log as a POS reprint - then prints with the "DUPLICATE RECEIPT" watermark |
| `/sales` | Items Sold | Admin/manager. What sold in a day or date range (default today, Asia/Manila; previous/next day, Today, Yesterday, Print). A line counts as sold when its ticket is paid (`closed_at`), whatever its shift, so an open shift shows live; open, cancelled and merged-source tickets and voided lines don't count, and still-open tickets are noted separately as "not yet paid". Per item (fees and custom lines get their own rows): qty, gross, its share of ticket discounts, net sales, refunds approved in the period, cost (the line's snapshotted cost × qty), profit and margin; a dish sold with no cost is flagged "No cost set". Summary: items sold, net sales, refunds, cost, gross profit (sales − refunds − cost) and net profit (gross profit − drawer expenses). **By raw material** tab (`?view=raw-materials`): per ingredient, grouped under its ingredient group - used (from the usage recorded at payment), returned by refunds approved in the period, net used, cost at the recorded cost per unit, and current stock/available; under each, the dishes that used it (servings, amount, and the dish's own sales and profit, shown for context and never summed per ingredient). Items sold without raw materials are listed separately, including stockless variant rows. A line picked with a stockless variant (e.g. "Lagi") is its own row, "Sisig Itik · Lagi", beside the normal dish, with ₱0 cost and never flagged "No cost set" |
| `/refunds` | Refunds | View only, admin/manager. **No approve/reject here** - refunds are approved or rejected on a POS terminal with a manager/admin passcode (`PUT /api/v1/refunds/{id}/approve` or `/reject`). Refunds still pending are pinned on top with how long they've waited. Below, every refund across shifts, newest first, 20 per page: requested time, status, ticket (linked) and receipt number, refunded items, reason, payment method, requested by, decided by/at, amount. Filters: status, payment method, requested date range, and search by order number, customer name or receipt number. Cards total approved (cash/GCash), rejected and pending refunds for the filters (ignoring the status filter), with a breakdown by who requested and who decided them. The pending count is shared with managers/admins (`pendingRefunds`) as a badge on the sidebar's Refunds entry |
| `/kitchen-orders` | Kitchen Orders | Admin/manager. The same cards as the KDS feed (`KdsService::GetOpenOrders`, identical to `GET /api/v1/kds/orders`): open tickets oldest first with only their pending, non-fee lines, no prices. Each card shows a waiting timer (amber at 10 min, red at 20) and links to the ticket. Refreshed by polling every 5 seconds (the back office has no Reverb client). **Bumping:** tap a line to bump it, or the customer name to bump the whole order (`PATCH /kitchen-orders/items/{id}/complete`, `PATCH /kitchen-orders/{ticket}/complete`), through the same `TicketService` methods and `kds.orders` broadcasts as the tablet. Bumping a ticket that was paid/cancelled meanwhile flashes an error. There is no undo here; a bumped line can be un-bumped only from the tablet API |
| `/users/{id}/sessions` | Employees → Devices | POS tokens never expire on their own, so this is the only way to end one: lists every device signed in as that user (`device_name`, signed-in/last-used time) with a per-device "Sign out" and a "Sign out everywhere" button. Not available for admin accounts, same protection as editing/deleting one |
| `/settings/*` | Profile, password, appearance | Starter-kit account settings |

Notes:

- Margin is calculated on the frontend: `(base_price − cost_price) / base_price × 100`. For recipe items the cost is the sum of `cost_per_unit × quantity_required` across the recipe.
- Item images are stored as an `image_url` string. **File upload is not implemented yet.**
- Restocking a direct item is done by editing its quantity on the item form; ingredient stock is adjusted on the ingredient form. There is no separate bulk-adjust screen.
- The back-office item list has a client-side search (name or category) but no category/status filter; the POS `GET /api/v1/items` supports a `category_id` filter.

### Planned (data and API exist; no Inertia pages yet)

#### `/reports` (Optional v1)

- Daily sales and item popularity - covered by `/sales` (Items Sold) above
- Payment method breakdown
- Employee performance (if tracking)

---

## 8. KDS SCREEN (Kitchen Display System) — _API implemented; client app planned_

> `GET /api/v1/kds/orders` and the realtime `kds.orders` channel (Laravel Reverb) are implemented — see `UNIFIED_API_ENDPOINTS.md` §8.5. The feed is ordered strictly by `created_at` ASC, excludes paid/cancelled/merged tickets, voided lines, `fee` lines, and **completed lines** — a bumped item drops off the feed entirely rather than appearing checked off, and a ticket with nothing pending disappears from the feed until a later add-on gives it something new to show (that add-on then appears alone, not mixed back in with what was already served). Completion is persisted as `ticket_items.completed_at` (toggled via `PATCH /api/v1/kds/orders/items/{ticketItem}/complete`, broadcast on toggle) and swept back to null nightly by the `kds:clear-completed` scheduled command — operational kitchen-workflow state, not audit history, superseding the original "UI-only, no DB timestamp" plan. The actual tablet display (React Native) below is still unbuilt — only the backend it will call exists today; the mockup below predates the "bump = disappear" decision and needs revisiting once that display is built.

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

## 10. REAL-TIME UPDATES (WebSockets) — _implemented for KDS; the rest is still planned_

> Laravel Reverb (first-party, Pusher-protocol-compatible) is installed — `beyondcode/laravel-websockets` was never adopted; it's effectively unmaintained and Reverb replaces it outright, settling the decision CLAUDE.md previously left open. Only the KDS channel below is wired up. POS terminals still rely on the manual sync button/polling for everything else (`GET /items`, `GET /tickets`, `GET /shifts/active`) — `inventory.updated`, `refund.requested` and `refund.approved` remain unbroadcast.

### Broadcasting Channels

- `kds.orders` → **implemented**. One private channel, authenticated via Sanctum at `POST /api/v1/broadcasting/auth` (not the default session-guarded `/broadcasting/auth` — see `UNIFIED_API_ENDPOINTS.md` §8.5). Deliberately not shift- or terminal-scoped: the KDS must show every terminal's orders, and since only one shift is ever open at a time, a shift-scoped channel would force constant resubscription for no benefit.
- `shift.{shift_id}` → not implemented. Originally planned for general POS/back-office sync.
- `terminal.{terminal_id}` → not implemented.

### Events Broadcast on `kds.orders` — implemented

1. **ticket.created** — a new open ticket

    ```json
    { "event": "ticket.created", "data": { ticket_id, order_number, customer_name, order_type, created_at, items: [...] } }
    ```

2. **ticket.updated** — items added/voided/quantity changed, or a merge target's lines changed (same shape as `ticket.created`)

3. **ticket.paid** — the ticket left the KDS feed because it was paid

    ```json
    { "event": "ticket.paid", "data": { ticket_id, order_number } }
    ```

4. **ticket.cancelled** — the ticket left the feed because it was cancelled. Not in the original event list; added to close a gap (nothing told the KDS to drop a cancelled order)

    ```json
    { "event": "ticket.cancelled", "data": { ticket_id, order_number } }
    ```

5. **ticket.merged** — one or more tickets were folded into a target and left the feed

    ```json
    { "event": "ticket.merged", "data": { ticket_id, order_number, removed_ticket_ids: [...], removed_order_numbers: [...] } }
    ```

6. **item.completed** — kitchen marked a line done

    ```json
    { "event": "item.completed", "data": { ticket_id, ticket_item_id } }
    ```

7. **item.uncompleted** — kitchen marked a line not-done again. Not in the original event list, which had no undo counterpart despite the UI spec (§8) allowing it

    ```json
    { "event": "item.uncompleted", "data": { ticket_id, ticket_item_id } }
    ```

A ticket card's payload never includes prices or `terminal_id`, matching §8's "no prices... no terminal info" requirement. Changing a ticket's discount broadcasts nothing — the KDS card shows no money, so a discount change is invisible to it.

### Events — not implemented

- **inventory.updated** — no KDS or other current consumer.
- **refund.requested** / **refund.approved** — no KDS or other current consumer.

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
- [x] Refund request + passcode approval/rejection, inventory restored, cash refunds netted from expected cash; requested item/quantity/amount are validated against the ticket and its purchase/payment history, not trusted from the client (`RefundValidationTest`)
- [x] Special items: Special categories with Fee items (cashier enters the amount) and Custom items (cashier enters the name and amount) — API, receipts and back office
- [x] Categories endpoint for the POS (`GET /categories`, `GET /categories/{id}`; active + POS-visible only)
- [x] Ticket line quantity edit (`PATCH /tickets/{id}/items/{ticketItem}`; no passcode — see §6 CartScreen)
- [x] KDS order feed (`GET /kds/orders`) + item completion API (`PATCH .../complete`), persisted as `ticket_items.completed_at` and broadcast live — see §8, §10
- [x] Realtime broadcasting for the KDS channel (Laravel Reverb) — `KdsBroadcastTest`; a Sanctum-scoped tablet token (`kds:read`/`kds:complete` abilities, `KdsTokenScopeTest`) can reach only the KDS endpoints

**Still to build**

- [x] Ticket access bound to the account (a cashier sees only their own tickets; managers/admins see all) — `TicketOwnershipTest`
- [~] Real-time WebSocket updates — KDS channel implemented; general POS/back-office sync (`shift.{shift_id}`, `inventory.updated`, `refund.*`) is not
- [ ] React Native POS app, including auto-print to the thermal printer
- [ ] KDS app (tablet client UI) — the API and realtime channel it will call are implemented; the display itself is not
- [x] Back-office Shifts pages: history list + close report, view only — `ShiftPagesTest`
- [x] Back-office Tickets pages: filtered history + ticket detail (lines, payments, refunds, merges), view only, receipt-number search and a receipt view with duplicate printing — `TicketPagesTest`, `ReceiptPageTest`
- [x] Back-office Kitchen Orders page: the KDS feed polled every 5 seconds, with bumping (line or whole order), admin/manager — `KitchenOrdersPageTest`
- [x] Back-office Items Sold page (`/sales`): per-item sales, cost and profit, net profit after expenses, live for an open shift - `SalesReportPageTest`; recipe-item lines snapshot their ingredient cost - `TicketItemCostTest`
- [x] Ingredient usage recorded at payment (`ticket_item_ingredients`), refunds restock exactly that, and an Items Sold "By raw material" tab - `IngredientUsageTest`, `SalesReportPageTest`
- [x] Stockless variant modifiers (e.g. "Lagi"): no stock, ₱0 cost and price, kitchen-only, own Items Sold row - `StocklessVariantTest`
- [x] App timezone Asia/Manila (receipt days, "today", the nightly KDS sweep)
- [x] Back-office Refunds page: pending refunds, filterable history, totals by status and person, sidebar pending badge, view only (approval stays on the POS) - `RefundPagesTest`
- [x] Back-office dashboard - `DashboardPageTest`; back office limited to active managers/admins - `BackOfficeAccessTest`; reorder levels with "running low" warnings - `ReorderLevelTest`

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
- **403 Forbidden** — Insufficient role, a passcode that matches no active admin/manager, or a passcode shared by more than one of them
- **404 Not Found** — Resource doesn't exist, another cashier's ticket, or no active shift where one is required
- **409 Conflict** — Business-rule violation: shift already open, no active shift, open tickets block close, charge total mismatch, insufficient stock, ticket not open, refund already decided, a refund exceeding what was purchased/paid, tickets in different shifts, a cashier merging a ticket they didn't open. Back office only: deleting a category/item/modifier/modifier group/ingredient/ingredient group still referenced by sales history or a recipe (flashed as a readable error, not a raw `500`)
- **422 Unprocessable Entity** — Validation failed
- **429 Too Many Requests** — Login throttled (6 attempts per minute), or 5 failed passcode attempts in a minute

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
│  └─ WebSocket server (Laravel Reverb, port 8080) — implemented for the KDS channel
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
- WebSockets (Laravel Reverb) at `ws://nuc-ip:8080` — implemented for the KDS channel
- No offline queue: the POS needs a live connection to the NUC (locked decision). Remote access via Tailscale.

---

## 14. NOTES FOR DEVELOPMENT

### Key Implementation Points

1. **Terminal ID and ticket access**

    - Each POS tablet sends `terminal_id` on ticket creation, as a label (and optional list filter)
    - Access is by account, not device: `TicketPolicy::manage` gates every per-ticket route (cashier: own tickets; manager/admin: all); receipts and refunds stay unscoped

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

8. **WebSocket Broadcasting** — implemented for KDS, _general POS/back-office sync still planned_
    - KDS: every relevant `TicketService`/`PaymentService` mutation broadcasts on the private `kds.orders` channel (Laravel Reverb), fired after its `DB::transaction()` returns so a rollback never produces a false push; wrapped in try/catch so a Reverb outage degrades to the tablet's normal polling fallback instead of a failed request. No `.toOthers()` - the KDS channel has no echo concern since the terminal that made the change isn't the one subscribed to it
    - General POS sync (`shift.{shift_id}`, with `.toOthers()` to avoid echoing a terminal's own change back to itself) remains unimplemented

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
- [x] Edit item quantity — raise/lower without a passcode, insufficient stock, zero rejected, voided/closed ticket rejected (`TicketItemQuantityTest`)
- [ ] Apply discount — _ticket discount untested; discount proration ✅ (`ReceiptTest`)_
- [x] Split payment (cash + gcash)
- [x] Charge sum must equal total exactly
- [x] Process payment (issues one receipt per charge; rollback if receipt fails)
- [x] Verify inventory deducted; a paid ticket can't be charged twice
- [x] Merge tickets (rules, chained merges, single receipt with all order numbers)
- [ ] Cancel ticket (unreserve inventory) — _untested_
- [x] Void item with passcode (approver identified from the passcode alone; `PasscodeApprovalTest`)
- [x] A cashier sees only their own orders; another cashier can't see, act on or merge them; a manager/admin sees and acts on all — `TicketOwnershipTest`
- [x] KDS sees all terminals, strictly `created_at` ASC, no prices/terminal info — `KdsOrdersFeedTest`
- [x] Kitchen marks item done / undone (persisted, not UI-only) — `KdsItemCompletionTest`
- [x] Request refund, manager approves (restore inventory; cash refund reduces expected cash) — `RefundValidationTest`, `PasscodeApprovalTest`
- [x] View receipt history (all terminals visible), filters, search, reprint log
- [x] WebSocket broadcasts on ticket/item changes (KDS channel) — `KdsBroadcastTest`; general POS broadcasts (`shift.{shift_id}`) still unbuilt
- [ ] Close shift, verify totals (incl. blocked while tickets are open) — _partly covered by the merge test; otherwise untested_
- [ ] Printer offline, fallback to digital receipt — _POS app_
- [x] Token expiry & re-login flow — tokens never expire on their own; a manager/admin can revoke one from Employee Management → Devices, and the revoked token is immediately rejected by the API (`UserSessionsTest`). The POS app's re-login-on-401 UI is still to build

No known failing tests — `php artisan test` is green.

---

## 16. CONTACT & SUPPORT

**For questions on this spec:**

- Review detailed sections
- Check API endpoint documentation
- Refer to database schema diagrams
- Test incrementally (don't wait for full integration)

---

**End of Enhanced Specification v1.2**
