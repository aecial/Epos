# Restaurant POS & Back Office System — ENHANCED SPECIFICATION (v1.0 Final)

**Project Name:** Restaurant POS + Back Office Admin Panel + KDS  
**Status:** Ready for Development  
**Last Updated:** August 2026

---

## 1. PROJECT OVERVIEW

A complete point-of-sale (POS), kitchen display system (KDS), and restaurant management system designed to run on a local area network (LAN) via Intel NUC with optional cloud fallback.

### System Components
1. **Laravel REST API** — Backend serving menu data, tickets, shifts, and inventory
2. **Next.js Back Office** — Admin panel for managing menu, employees, and shift analytics
3. **React Native POS** — Tablet-based point-of-sale for taking orders (multiple terminals)
4. **KDS Screen** — Kitchen Display System showing live orders with timers
5. **Receipt Printer** — Thermal printer (Goojrpt PT-210) + digital receipt history

### Core Business Logic
- **Single active shift** per service (manager opens → employees join → orders created)
- **Real-time inventory** tracking with reserve/deduct/refund system
- **Terminal-specific** POS screens (each tablet sees only its own active orders)
- **Unified KDS** (all terminals' orders visible to kitchen)
- **Split charges** (cash + GCash on same ticket, separate receipts)
- **Ticket merging** (combine 2+ tickets into one bill, keep existing order numbers)
- **Auto-print receipts** after payment completion
- **Receipt history** accessible on POS (no terminal filters) and Back Office (with filters)

---

## 2. DATABASE SCHEMA

### Key Tables & Relationships

```
users (employees)
├─ opens → shifts
├─ creates → tickets
├─ requests → refunds
└─ approves → refunds

shifts (service sessions)
├─ contains → tickets
├─ contains → charges (via tickets)
└─ tracks → totals (revenue, cash, gcash)

categories (menu groups)
└─ has many → items

items (menu items with inventory)
├─ has many → modifiers
├─ has many → ticket_items
├─ tracks → quantity (actual stock)
├─ tracks → reserved_quantity (pending orders)
└─ tracks → cost_price (for margin calculation)

modifiers (item adjustments: sizes, flavors)
└─ selected in → ticket_items (with price modifier)

tickets (open/paid orders)
├─ contains → ticket_items (line items)
├─ triggers → charges (payment records)
├─ can merge → merged_into_ticket_id
├─ reserves → inventory
└─ tracks → created_by, terminal_id

ticket_items (line items in ticket)
├─ references → item, modifier
├─ assigned to → charge_items (receipt breakdown)
└─ tracked for → completion (KDS UI state only)

charges (payment records, one per method)
├─ contains → charge_items (which items on this receipt)
└─ generates → thermal receipt

charge_items (receipt breakdown)
└─ maps → ticket_items to specific charges

refunds (refund tracking with approval)
├─ references → ticket, charge
└─ reverses → inventory on approval
```

### Core Constraints
- One active shift at a time (`shifts.status = 'open'`)
- Unique customer name per open shift per terminal (auto-append: john → john2 → john3)
- Available qty = `quantity - reserved_quantity` (can display negative with warning)
- Charge sum must equal ticket total before payment processing
- Ticket merge: keep both order numbers, update ticket totals

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
```
Ticket Ready to Pay
    ↓
Create Charge 1 (Cash ₱175)
Create Charge 2 (GCash ₱175)
    ↓
Assign Items to Charges
(john's burger to cash, john's rice to gcash)
    ↓
Validate: Charge Sum = Ticket Total
    ↓
Process Payment (Mark Charges as Paid)
    ↓
Deduct Inventory + Clear Reserves
    ↓
Close Ticket (status = 'paid')
    ↓
AUTO-PRINT Receipt 1 (Cash) → Goojrpt PT-210
AUTO-PRINT Receipt 2 (GCash) → Goojrpt PT-210
    ↓
Display Receipt on POS Screen (for customer viewing)
    ↓
Save to Receipt History (accessible to all terminals + back office)
```

### Ticket Merge Flow
```
Open Tickets:
  #001 - john (₱350)
  #002 - john2 (₱250)
  #003 - maria (₱400)

Cashier selects: "Merge #001 and #002 into one bill"
    ↓
System combines items + discounts
    ↓
New Merged Bill:
  Order Numbers: #001, #002
  Items: john's items + john2's items
  Total: ₱600
    ↓
One Payment Process (cash + gcash split still works)
    ↓
#001 & #002 status → 'merged'
    ↓
Print ONE receipt showing both order numbers
```

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
```

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
+ Sales (all terminals):    ₱1,250
+ Cash Additions:           ₱  500
- Expenses:                 -₱  200
─────────────────────────────────────
EXPECTED TOTAL CASH:        ₱6,550
```

**Features:**
- Managers/admins only can add via POS "Shift Settings"
- Free-text reason (e.g., "Owner deposit", "Supply purchase")
- Track who added it and when
- Can edit/delete entries mid-shift
- Soft-delete (not shown in totals if deleted)
- Synced across all terminals (no real-time broadcast, but pulled on refresh)
- Visible at shift close for cash reconciliation

---

## 4. TECHNOLOGY STACK

| Component | Technology | Purpose |
|-----------|-----------|---------|
| **Backend** | Laravel 10+ | REST API with Sanctum |
| **Database** | MySQL 8.0+ | Persistent data storage |
| **Authentication** | Laravel Sanctum | Token-based stateless auth |
| **Admin Panel** | Next.js 14+ | React-based dashboard |
| **Admin Styling** | TailwindCSS | Professional UI |
| **POS App** | React Native (Expo) | Tablet-optimized checkout |
| **KDS Screen** | React or Next.js | Kitchen display (large screen) |
| **State Management** | Zustand or Context | Client-side state |
| **HTTP Client** | Axios | API requests with interceptors |
| **Thermal Printer** | Goojrpt PT-210 | Receipt printing via Bluetooth/USB |
| **Receipt Printing Library** | react-native-thermal-receipt-printer or Goojrpt SDK | Print integration |
| **Real-time Updates** | Laravel WebSockets + Pusher JS | Live order broadcast |
| **Deployment** | Intel NUC (Docker) | Local network hosting |
| **Network** | WiFi Mesh (TP-Link Deco M5) | POS tablet connectivity |

---

## 5. AUTHENTICATION & ROLES

### Role Matrix

| Action | Admin | Manager | Cashier |
|--------|-------|---------|---------|
| Login | ✅ | ✅ | ✅ |
| Create Users | ✅ | ❌ | ❌ |
| Manage Menu (Items/Categories) | ✅ | ❌ | ❌ |
| Open/Close Shifts | ✅ | ✅ | ❌ |
| Create Tickets (Orders) | ❌ | ❌ | ✅ |
| View Own Terminal Orders | ❌ | ❌ | ✅ |
| View All Orders (Back Office) | ✅ | ✅ | ❌ |
| Approve Refunds | ✅ | ✅ | ❌ |
| Request Refunds | ✅ | ✅ | ✅ |
| View Receipts | ✅ | ✅ | ✅ |
| View Reports | ✅ | ✅ | ❌ |

---

## 6. POS TERMINAL SPECIFICATIONS

### Screen Hierarchy (React Native)

#### 1. **LoginScreen**
- Email + password
- Token saved to secure storage
- Redirect to `ShiftSetupScreen`

#### 2. **ShiftSetupScreen**
- If shift open → auto-sync menu → go to MenuScreen
- If no shift → show "Open New Shift" form
- Input starting cash, get shift_id
- Call `GET /api/menu?shift_id={id}` to load items

#### 3. **MenuScreen** (Main Dashboard)
- Horizontal category pills
- Grid of items (image, name, price)
- **Manual sync button** (refresh inventory)
- Tap item → add to cart
- Cart badge (top-right) → go to CartScreen
- **Terminal sees only own active tickets** in sidebar
- View receipt history (filter by own terminal or all?)

#### 4. **CartScreen**
- Items with quantities
- Edit quantity (+ / -)
- Remove item
- Apply discount (₱ or %)
- Order name (auto-set, editable)
- Order type selector (dine-in / takeout)
- Checkout button

#### 5. **CheckoutScreen**
- Ticket summary with items
- Subtotal + discount = total
- Payment method buttons:
  - Cash (full)
  - GCash (full)
  - Split (cash + gcash)
- For split: drag-and-drop items to charges OR input amounts
- Validate charge sum = total
- **AUTO-PRINT Receipt** after confirmation
- **Show Receipt on Screen** (can print again)

#### 6. **OrderHistoryScreen**
- List of open tickets (own terminal only)
- Can merge 2+ tickets
- Can cancel ticket (before payment)
- Can view/edit in-progress tickets

#### 7. **ReceiptHistoryScreen**
- View past receipts (own terminal or all?)
- Filter by date range (optional for v1)
- Tap to view/reprint
- No terminal filter for POS (all visible)

#### 8. **Shift/Settings Screen** (Manager/Admin only)
- Current shift info
- Close shift button (confirmation modal)
- **Add Expense button** → modal with reason & amount
- **Add Cash Addition button** → modal with reason & amount
- List of today's expenses & additions (can edit/delete)
- Running shift totals showing:
  - Starting Cash
  - Sales (all terminals)
  - Cash Additions
  - Expenses
  - Expected Total Cash

---

## 7. BACK OFFICE (Next.js) SPECIFICATIONS

### Admin Pages

#### `/login`
- Email + password
- Persist token
- Redirect to `/dashboard`

#### `/dashboard`
- Active shift status
- Today's stats: orders count, total revenue, cash/gcash breakdown
- Quick action buttons: Open Shift, Close Shift, View Reports
- Pending refunds widget

#### `/items`
- Table: id, name, price, cost_price, category, quantity, reserved, available, status
- Create item (form: name, base_price, cost_price, category, image, quantity)
- Edit item
- Delete item
- Bulk quantity adjustment (mid-shift restocking)
- Filter by category, status
- Search by name

#### `/categories`
- Table: id, name, icon, display_order
- CRUD operations

#### `/items/{id}/modifiers`
- List modifiers for item
- Add/edit/delete modifier (name, price_modifier)

#### `/employees`
- Table: id, name, email, role, status
- CRUD operations
- Password reset

#### `/shifts`
- Historical shift list (with totals)
- Click to view details: opening time, closing time, total revenue, cash/gcash breakdown
- Shift close report

#### `/orders` (Sales/Receipts)
- All orders (not terminal-filtered, unlike POS)
- Filter by date, payment method, shift
- Click order to see receipt details
- Search by order number or customer name

#### `/refunds`
- Pending refunds list (awaiting approval)
- Approve/reject with notes
- History of approved/rejected refunds

#### `/reports` (Optional v1)
- Daily sales
- Item popularity
- Payment method breakdown
- Employee performance (if tracking)

---

## 8. KDS SCREEN (Kitchen Display System)

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
1. Payment processed → closes ticket
2. System generates receipt data
3. Connects to Goojrpt PT-210 via Bluetooth/USB
4. **Prints Receipt automatically** (no user action needed)
5. Also displays on POS screen for customer verification

### Receipt Format (Per Charge)

```
═══════════════════════════════════════
        RESTAURANT NAME
        123 Main Street
        +63 912 345 6789
═══════════════════════════════════════
RECEIPT - CHARGE 1 (CASH)
Order #001 - john (Dine-in)
Date: 2026-08-10 14:35:00
Cashier: Maria
═══════════════════════════════════════

1x Fried Itik (Large)        ₱150.00
1x Rice                      ₱ 50.00
                            ─────────
Subtotal                     ₱200.00
Discount (prorated)          -₱25.00
                            ─────────
TOTAL (CASH)                 ₱175.00

═══════════════════════════════════════
Thank you! Please come again.
═══════════════════════════════════════
```

### For Split Charges
- Each charge prints **separately** (Receipt 1: Cash, Receipt 2: GCash)
- Both show same order number and items
- Totals reflect prorated discount per charge

### For Merged Tickets
- One receipt showing both order numbers (#001, #002)
- Combined items and totals
- If split: two receipts with both order numbers

### Receipt History (POS)
- Accessible to all terminals (no filter)
- Shows: date, order number, customer name, total, payment method
- Click to view full receipt details + reprint option
- Reprint shows "DUPLICATE RECEIPT" watermark

---

## 10. REAL-TIME UPDATES (WebSockets)

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

### Must-Have ✅
- [x] User authentication (Sanctum)
- [x] Shift open/close
- [x] Menu management (items, categories, modifiers)
- [x] Ticket creation with auto-duplicate names
- [x] Item add/remove from ticket (inventory reserve)
- [x] Discount application
- [x] Split charge payment (cash + gcash)
- [x] Auto-print thermal receipt
- [x] Inventory decrement on payment
- [x] Ticket merging
- [x] KDS order display + item completion (UI only)
- [x] Back office order/receipt viewing
- [x] Terminal isolation (POS sees own orders only)
- [x] Receipt history (all terminals visible on POS)
- [x] Refund request + admin approval
- [x] Real-time WebSocket updates
- [x] Shift expenses & cash additions (manager/admin tracked from POS)
- [x] Shift cash reconciliation with expected totals

### Nice-to-Have (v1)
- [ ] Receipt reprint with watermark
- [ ] Order status color-coding (age-based)
- [ ] Receipt history filters (back office)
- [ ] Image uploads for items
- [ ] Cost price → margin calculation (frontend)

### Defer to v1.1+
- [ ] Offline order queueing
- [ ] Advanced analytics/reporting
- [ ] Expense tracking
- [ ] Leave/attendance scheduling
- [ ] Barcode/QR code scanning
- [ ] Multi-location support
- [ ] Delivery integration (GrabFood, Foodpanda)
- [ ] Kitchen staff performance metrics
- [ ] Inventory alerts/reordering

---

## 12. ERROR HANDLING

### Standard Response Format
```json
{
  "success": false,
  "message": "User-friendly error message",
  "errors": { "field": ["Validation error 1"] }
}
```

### Common Errors
- **401 Unauthorized** — Invalid/expired token
- **403 Forbidden** — Insufficient role
- **404 Not Found** — Resource doesn't exist
- **409 Conflict** — No active shift, duplicate name, etc.
- **422 Unprocessable Entity** — Validation failed

---

## 13. DEPLOYMENT ARCHITECTURE

```
WiFi Mesh (TP-Link Deco M5)
│
├─ Intel NUC (LAN)
│  ├─ Laravel API (Port 8000)
│  ├─ MySQL Database
│  └─ Laravel WebSockets (Port 6001)
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
│  └─ React/Next.js Web App
│
└─ Admin Laptop (WiFi)
   └─ Next.js Dashboard (Browser)
```

### Network Setup
- All devices on same LAN via WiFi mesh
- API accessible at `http://nuc-ip:8000`
- WebSockets at `ws://nuc-ip:6001`
- No internet required for operations (offline-first)

---

## 14. NOTES FOR DEVELOPMENT

### Key Implementation Points

1. **Terminal ID Tracking**
   - Each POS tablet sends `terminal_id` on ticket creation
   - Used to isolate orders and receipts per terminal

2. **Inventory Reserve Logic**
   ```
   Add item to ticket: reserved_qty += qty
   Remove item: reserved_qty -= qty
   Process payment: qty -= qty, reserved_qty -= qty
   Approve refund: qty += qty, reserved_qty -= qty
   ```

3. **Ticket Merge**
   - Select multiple open tickets
   - Merge into one (user chooses which to merge into, others become "merged")
   - Recalculate totals
   - Keep both order numbers in display

4. **Split Charges**
   - One ticket, multiple charges (cash + gcash)
   - Each charge gets its own receipt
   - Frontend drag-and-drop to assign items
   - Validate sum before payment

5. **Receipt Printing**
   - Auto-print after payment (no user action needed)
   - Use Goojrpt PT-210 SDK for React Native
   - Fallback to digital receipt if printer offline

6. **KDS Item Completion**
   - Store in UI state only (no DB timestamp in v1)
   - When item marked done: remove from kitchen view
   - On page refresh: re-fetch orders and re-calculate completion UI

7. **Database Transactions**
   - Wrap payment + inventory changes in single transaction
   - Prevents race conditions with multiple POS terminals

8. **WebSocket Broadcasting**
   - Broadcast to `shift.{shift_id}` channel
   - All terminals in same shift get real-time updates
   - Exclude sender with `.toOthers()` to avoid echo

---

## 15. TESTING CHECKLIST

- [ ] User login & role-based access
- [ ] Open shift, auto-sync menu
- [ ] Create ticket with auto-duplicate names (john → john2)
- [ ] Add items (reserves inventory)
- [ ] Edit item quantity
- [ ] Apply discount
- [ ] Split payment (cash ₱175 + gcash ₱175)
- [ ] Assign items to charges
- [ ] Process payment (auto-print receipt)
- [ ] Verify inventory deducted
- [ ] Merge two tickets
- [ ] Cancel ticket (unreserve inventory)
- [ ] Terminal 1 sees own orders, KDS sees all
- [ ] Kitchen marks item done (UI only)
- [ ] Request refund, admin approves (restore inventory)
- [ ] View receipt history (all terminals visible)
- [ ] WebSocket broadcasts on new order
- [ ] Close shift, verify totals
- [ ] Printer offline, fallback to digital receipt
- [ ] Token expiry & re-login flow

---

## 16. CONTACT & SUPPORT

**For questions on this spec:**
- Review detailed sections
- Check API endpoint documentation
- Refer to database schema diagrams
- Test incrementally (don't wait for full integration)

---

**End of Enhanced Specification v1.0**
