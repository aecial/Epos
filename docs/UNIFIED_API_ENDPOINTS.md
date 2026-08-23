# Restaurant POS API — Unified Endpoints (v1.0 Final)

**Base URL:** `http://nuc-ip:8000/api`  
**Authentication:** Bearer token via Laravel Sanctum (except login/register)  
**Response Format:** Standard JSON with success flag

---

## RESPONSE FORMAT (All Endpoints)

### Success Response
```json
{
  "success": true,
  "data": { /* payload */ },
  "message": "Optional success message"
}
```

### Error Response
```json
{
  "success": false,
  "message": "User-friendly error message",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

---

## 1. AUTHENTICATION ENDPOINTS

### POST `/auth/register`
**Description:** Create new user (admin-only in production)

**Body:**
```json
{
  "name": "John Doe",
  "email": "john@restaurant.com",
  "password": "securepassword",
  "role": "cashier"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user": { "id": 1, "name": "John Doe", "email": "john@...", "role": "cashier" },
    "token": "sanctum_token_here"
  }
}
```

**Auth:** None (middleware enforces admin in production)

---

### POST `/auth/login`
**Description:** Authenticate user and get token

**Body:**
```json
{
  "email": "john@restaurant.com",
  "password": "securepassword"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user": { "id": 1, "name": "John Doe", "role": "cashier" },
    "token": "sanctum_token_here"
  }
}
```

**Auth:** None

---

### GET `/auth/me`
**Description:** Get current authenticated user

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@...",
    "role": "cashier",
    "status": "active"
  }
}
```

**Auth:** Required

---

### POST `/auth/logout`
**Description:** Logout (revoke token)

**Response:**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

**Auth:** Required

---

## 2. SHIFT ENDPOINTS

### POST `/shifts`
**Description:** Open new shift (manager/admin only)

**Body:**
```json
{
  "starting_cash": 5000.00
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "opened_by": 2,
    "status": "open",
    "starting_cash": 5000.00,
    "opened_at": "2026-08-10T14:00:00Z",
    "total_revenue": 0.00,
    "total_cash": 0.00,
    "total_gcash": 0.00
  }
}
```

**Auth:** Required (manager, admin)

**Backend Logic:**
- Validate only one active shift exists
- Enforce UNIQUE INDEX on status='open'
- Set `opened_by = current_user.id`
- Return shift_id immediately (POS needs it for menu sync)

---

### GET `/shifts/active`
**Description:** Get currently open shift

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "opened_by": 2,
    "status": "open",
    "starting_cash": 5000.00,
    "opened_at": "2026-08-10T14:00:00Z"
  }
}
```

**Returns:** 404 if no shift open

**Auth:** Required

---

### GET `/shifts/{id}`
**Description:** Get shift details with totals

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "opened_by": 2,
    "status": "open",
    "starting_cash": 5000.00,
    "opened_at": "2026-08-10T14:00:00Z",
    "closed_at": null,
    "total_revenue": 1250.00,
    "total_cash": 750.00,
    "total_gcash": 500.00,
    "ticket_count": 5
  }
}
```

**Auth:** Required

---

### PUT `/shifts/{id}/close`
**Description:** Close shift and calculate totals (including expenses/additions)

**Body:**
```json
{
  "closing_cash": 8200.00
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "status": "closed",
    "closed_at": "2026-08-10T22:00:00Z",
    "starting_cash": 5000.00,
    "total_revenue": 1250.00,
    "total_cash": 750.00,
    "total_gcash": 500.00,
    "total_additions": 500.00,
    "total_expenses": 200.00,
    "expected_cash": 6550.00,
    "closing_cash": 8200.00,
    "discrepancy": 1650.00
  }
}
```

**Auth:** Required (manager, admin)

**Backend Logic:**
- Set `status = 'closed'`, `closed_at = now()`
- Calculate totals:
  - `total_revenue` = SUM(tickets.total WHERE status = 'paid')
  - `total_cash` = SUM(charges.amount WHERE payment_method = 'cash')
  - `total_gcash` = SUM(charges.amount WHERE payment_method = 'gcash')
  - `total_additions` = SUM(shift_transactions.amount WHERE type = 'addition' AND deleted_at IS NULL)
  - `total_expenses` = SUM(shift_transactions.amount WHERE type = 'expense' AND deleted_at IS NULL)
  - `expected_cash` = starting_cash + total_cash + total_additions - total_expenses
- Validate all open tickets are paid or merged
- Wrap in transaction

---

## 3. SHIFT TRANSACTIONS ENDPOINTS (Expenses & Cash Additions)

### POST `/shifts/{id}/transactions`
**Description:** Add expense or cash addition to shift (manager/admin only, POS only)

**Body:**
```json
{
  "type": "expense",
  "amount": 500.00,
  "reason": "Supplies purchase - rice and oil"
}
```

OR

```json
{
  "type": "addition",
  "amount": 1000.00,
  "reason": "Owner cash deposit"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "shift_id": 1,
    "type": "expense",
    "amount": 500.00,
    "reason": "Supplies purchase",
    "created_by": 2,
    "created_by_name": "Manager User",
    "created_at": "2026-08-10T14:30:00Z"
  }
}
```

**Auth:** Required (manager, admin only)

**Backend Logic:**
- Validate shift exists and status = 'open'
- Create shift_transaction record
- Set `created_by = current_user.id`
- Calculate new totals for display (no broadcast to other terminals)

---

### GET `/shifts/{id}/transactions`
**Description:** Get all expenses and cash additions for a shift

**Query Params:** `?page=1&per_page=50`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "type": "addition",
      "amount": 500.00,
      "reason": "Owner cash deposit",
      "created_by": 2,
      "created_by_name": "Manager User",
      "created_at": "2026-08-10T14:20:00Z",
      "deleted_at": null
    },
    {
      "id": 2,
      "type": "expense",
      "amount": 200.00,
      "reason": "Supplies purchase",
      "created_by": 2,
      "created_by_name": "Manager User",
      "created_at": "2026-08-10T14:30:00Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "total": 2,
    "per_page": 50,
    "current_page": 1
  }
}
```

**Auth:** Required (manager, admin)

---

### PUT `/shifts/{shift_id}/transactions/{id}`
**Description:** Edit expense or cash addition (manager/admin only)

**Body:**
```json
{
  "amount": 600.00,
  "reason": "Supplies purchase - rice, oil, and spices"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 2,
    "type": "expense",
    "amount": 600.00,
    "reason": "Supplies purchase - rice, oil, and spices",
    "created_by": 2,
    "updated_at": "2026-08-10T14:35:00Z"
  }
}
```

**Auth:** Required (manager, admin only)

---

### DELETE `/shifts/{shift_id}/transactions/{id}`
**Description:** Delete expense or cash addition (soft-delete, excludes from totals)

**Response:**
```json
{
  "success": true,
  "message": "Transaction deleted"
}
```

**Auth:** Required (manager, admin only)

**Backend Logic:**
- Soft-delete: set `deleted_at = now()`
- Not shown in shift totals (WHERE deleted_at IS NULL)
- Can be restored by re-opening if needed

---

## 4. MENU MANAGEMENT ENDPOINTS (Admin/Back Office)

### GET `/categories`
**Description:** List all categories

**Query Params:** `?status=active`

**Response:**
```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Mains", "display_order": 1, "status": "active" },
    { "id": 2, "name": "Sides", "display_order": 2, "status": "active" }
  ]
}
```

**Auth:** Required

---

### POST `/categories`
**Description:** Create category

**Body:**
```json
{
  "name": "Drinks",
  "icon_url": "https://...",
  "display_order": 3
}
```

**Auth:** Required (admin only)

---

### PUT `/categories/{id}`
**Description:** Update category

**Body:** Same as create

**Auth:** Required (admin only)

---

### DELETE `/categories/{id}`
**Description:** Delete category

**Auth:** Required (admin only)

**Backend Logic:**
- Soft-delete or prevent if items exist

---

### GET `/items`
**Description:** List all items with modifiers

**Query Params:** `?category_id=1` `?status=active` `?page=1&per_page=20`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Fried Itik",
      "category_id": 1,
      "base_price": 100.00,
      "cost_price": 40.00,
      "quantity": 5,
      "reserved_quantity": 2,
      "available": 3,
      "status": "active",
      "modifiers": [
        { "id": 5, "name": "Large", "price_modifier": 50.00 }
      ]
    }
  ],
  "meta": {
    "total": 25,
    "per_page": 20,
    "current_page": 1
  }
}
```

**Auth:** Required

---

### POST `/items`
**Description:** Create menu item

**Body:**
```json
{
  "category_id": 1,
  "name": "Fried Itik",
  "description": "Crispy fried duck",
  "base_price": 100.00,
  "cost_price": 40.00,
  "quantity": 20,
  "image_url": "https://..."
}
```

**Auth:** Required (admin only)

---

### PUT `/items/{id}`
**Description:** Update item

**Body:** Same as create

**Auth:** Required (admin only)

---

### DELETE `/items/{id}`
**Description:** Delete item

**Auth:** Required (admin only)

---

### PATCH `/items/{id}/quantity`
**Description:** Adjust item inventory (mid-shift restocking)

**Body:**
```json
{
  "quantity": 25
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Fried Itik",
    "quantity": 25,
    "reserved_quantity": 2,
    "available": 23
  }
}
```

**Auth:** Required (manager, admin)

**Backend Logic:**
- Direct quantity adjustment (for corrections/restocking)
- Note: Modifies `quantity`, not `reserved_quantity`

---

### GET `/items/{id}/modifiers`
**Description:** Get all modifiers for an item

**Response:**
```json
{
  "success": true,
  "data": [
    { "id": 5, "name": "Small", "price_modifier": 0.00, "display_order": 1 },
    { "id": 6, "name": "Medium", "price_modifier": 20.00, "display_order": 2 },
    { "id": 7, "name": "Large", "price_modifier": 50.00, "display_order": 3 }
  ]
}
```

**Auth:** Required

---

### POST `/items/{id}/modifiers`
**Description:** Create modifier for item

**Body:**
```json
{
  "name": "Large",
  "price_modifier": 50.00,
  "display_order": 3
}
```

**Auth:** Required (admin only)

---

### PUT `/modifiers/{id}`
**Description:** Update modifier

**Body:**
```json
{
  "name": "Extra Large",
  "price_modifier": 75.00
}
```

**Auth:** Required (admin only)

---

### DELETE `/modifiers/{id}`
**Description:** Delete modifier

**Auth:** Required (admin only)

---

## 5. POS MENU ENDPOINT (Fetch for Tablets)

### GET `/menu`
**Description:** Get complete menu for POS (all items + modifiers with inventory state)

**Query Params:** `?shift_id={id}` (required)

**Response:**
```json
{
  "success": true,
  "data": {
    "shift_id": 1,
    "categories": [
      {
        "id": 1,
        "name": "Mains",
        "display_order": 1,
        "items": [
          {
            "id": 1,
            "name": "Fried Itik",
            "base_price": 100.00,
            "quantity": 5,
            "reserved_quantity": 2,
            "available": 3,
            "image_url": "https://...",
            "modifiers": [
              {
                "id": 5,
                "name": "Large",
                "price_modifier": 50.00,
                "display_order": 1
              }
            ]
          }
        ]
      }
    ]
  }
}
```

**Auth:** Required

**Backend Logic:**
- Return all active items grouped by category
- Include inventory state (quantity, reserved_quantity, available)
- Include all modifiers for each item
- Order by display_order

---

## 6. TICKET ENDPOINTS (Core POS — Terminal-Specific)

### POST `/tickets`
**Description:** Create new open ticket (POS-specific)

**Body:**
```json
{
  "shift_id": 1,
  "terminal_id": "POS-01",
  "customer_name": "john",
  "order_type": "dine_in"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "shift_id": 1,
    "terminal_id": "POS-01",
    "order_number": "#001",
    "customer_name": "john",
    "order_type": "dine_in",
    "status": "open",
    "subtotal": 0.00,
    "discount_amount": 0.00,
    "total": 0.00,
    "created_at": "2026-08-10T14:30:00Z"
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Validate active shift exists
- **Auto-check** for duplicate customer_name in open tickets (same shift):
  - If "john" exists → create "john2"
  - If "john2" exists → create "john3"
  - Use UNIQUE INDEX for enforcement
- Generate auto-increment order_number
- Set `created_by = current_user.id`
- Set `terminal_id` from request (crucial for isolation)
- Initialize `status = 'open'`, `subtotal = 0`, `total = 0`
- Broadcast: `ticket.created` event to `shift.{shift_id}`

---

### GET `/tickets`
**Description:** List open tickets (terminal-specific on POS, all on back office)

**Query Params:** 
- `?shift_id={id}` (required)
- `?terminal_id={id}` (filters by terminal — used by POS)
- `?status=open` (default)
- `?page=1&per_page=20`

**Response (POS Terminal 1):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "order_number": "#001",
      "customer_name": "john",
      "order_type": "dine_in",
      "status": "open",
      "terminal_id": "POS-01",
      "item_count": 3,
      "total": 350.00,
      "created_at": "2026-08-10T14:30:00Z",
      "elapsed_seconds": 145
    },
    {
      "id": 2,
      "order_number": "#002",
      "customer_name": "john2",
      "order_type": "takeout",
      "status": "open",
      "terminal_id": "POS-01",
      "item_count": 2,
      "total": 150.00,
      "created_at": "2026-08-10T14:35:00Z",
      "elapsed_seconds": 95
    }
  ]
}
```

**Auth:** Required

**Backend Logic:**
- **POS:** Filter by `terminal_id` to show only own orders
- **Back Office:** Ignore `terminal_id`, show all
- Calculate `elapsed_seconds` from `created_at`

---

### GET `/tickets/{id}`
**Description:** Get single ticket with all items and charges

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "order_number": "#001",
    "customer_name": "john",
    "order_type": "dine_in",
    "terminal_id": "POS-01",
    "status": "open",
    "subtotal": 400.00,
    "discount_amount": 50.00,
    "discount_percent": 12.5,
    "total": 350.00,
    "items": [
      {
        "id": 10,
        "ticket_item_id": 10,
        "item_id": 1,
        "name": "Fried Itik",
        "quantity": 2,
        "unit_price": 100.00,
        "modifier": {
          "id": 5,
          "name": "Large",
          "price_modifier": 50.00
        },
        "line_total": 300.00,
        "notes": "Extra crispy"
      },
      {
        "id": 11,
        "ticket_item_id": 11,
        "item_id": 2,
        "name": "Rice",
        "quantity": 2,
        "unit_price": 50.00,
        "modifier": null,
        "line_total": 100.00,
        "notes": null
      }
    ],
    "charges": [],
    "created_at": "2026-08-10T14:30:00Z"
  }
}
```

**Auth:** Required

---

### POST `/tickets/{id}/items`
**Description:** Add item to open ticket (reserves inventory)

**Body:**
```json
{
  "item_id": 1,
  "quantity": 2,
  "modifier_id": 5,
  "notes": "Extra crispy"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "ticket_item_id": 10,
    "item_id": 1,
    "name": "Fried Itik",
    "quantity": 2,
    "unit_price": 100.00,
    "modifier_price": 50.00,
    "line_total": 300.00,
    "ticket": {
      "id": 1,
      "subtotal": 400.00,
      "total": 350.00,
      "discount_amount": 50.00
    }
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Validate ticket exists and status = 'open'
- Validate available qty (qty - reserved_qty) ≥ requested quantity
- Allow going negative in reserved state (with warning on frontend)
- **Reserve inventory:** `item.reserved_quantity += qty`
- Create ticket_item record
- Recalculate ticket.subtotal
- Wrap in transaction
- Broadcast: `ticket.updated` event

---

### PATCH `/tickets/{id}/items/{ticket_item_id}`
**Description:** Update item quantity in ticket

**Body:**
```json
{
  "quantity": 3
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "ticket_item_id": 10,
    "quantity": 3,
    "line_total": 450.00,
    "ticket": {
      "subtotal": 550.00,
      "total": 500.00
    }
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Validate item exists in ticket
- Calculate difference: new_qty - old_qty
- Update reserved: `item.reserved_qty += diff`
- Update line_total and ticket subtotal
- Broadcast: `inventory.updated` event

---

### DELETE `/tickets/{id}/items/{ticket_item_id}`
**Description:** Remove item from ticket (unreserve inventory)

**Response:**
```json
{
  "success": true,
  "data": {
    "ticket": {
      "subtotal": 100.00,
      "total": 50.00
    }
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Delete ticket_item
- Unreserve inventory: `item.reserved_qty -= qty`
- Recalculate ticket totals
- Broadcast: `inventory.updated` event

---

### PATCH `/tickets/{id}/discount`
**Description:** Apply discount to ticket

**Body:**
```json
{
  "discount_amount": 50.00
}
```

OR

```json
{
  "discount_percent": 10.0
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "subtotal": 400.00,
    "discount_amount": 50.00,
    "discount_percent": 12.5,
    "total": 350.00
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Accept either amount or percent
- Recalculate total
- Both can be sent; use one (amount takes precedence if both)
- Broadcast: `ticket.updated` event

---

### POST `/tickets/{id}/merge`
**Description:** Merge two or more tickets into one

**Body:**
```json
{
  "merge_from_ticket_ids": [2, 3]
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "order_number": "#001",
    "status": "open",
    "subtotal": 750.00,
    "total": 700.00,
    "merged_tickets": [
      { "id": 2, "order_number": "#002" },
      { "id": 3, "order_number": "#003" }
    ],
    "items": [
      /* combined items from all three tickets */
    ]
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Validate all tickets exist, same shift, all open
- Move all items from `merge_from` to main ticket
- Recalculate main ticket totals
- Set merged tickets' `status = 'merged'` and `merged_into_ticket_id = {main_id}`
- Keep original order numbers visible on receipt
- Wrap in transaction
- Broadcast: `ticket.merged` event

---

### POST `/tickets/{id}/cancel`
**Description:** Cancel open ticket (unreserve all items)

**Body:**
```json
{
  "reason": "Customer cancelled"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Ticket cancelled, inventory restored"
}
```

**Auth:** Required (cashier+, manager)

**Backend Logic:**
- Only cancel if status = 'open'
- Unreserve all items: `item.reserved_qty -= qty`
- Set ticket status to 'cancelled'
- Broadcast: `inventory.updated` event

---

## 7. PAYMENT ENDPOINTS (Split Charges)

### POST `/tickets/{id}/charges`
**Description:** Create charges for ticket (can be multiple for split payment)

**Body:**
```json
{
  "charges": [
    {
      "payment_method": "cash",
      "amount": 175.00
    },
    {
      "payment_method": "gcash",
      "amount": 175.00,
      "payment_reference": "GCash_TXN_12345"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "charges": [
      {
        "id": 1,
        "payment_method": "cash",
        "amount": 175.00,
        "status": "pending"
      },
      {
        "id": 2,
        "payment_method": "gcash",
        "amount": 175.00,
        "status": "pending"
      }
    ]
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Validate sum of amounts = ticket.total
- Create charge records with status = 'pending'
- If single charge (no split), status can be 'paid' immediately
- Wrap in transaction

---

### POST `/charges/{charge_id}/items`
**Description:** Assign items to specific charge (for receipt breakdown)

**Body:**
```json
{
  "items": [
    {
      "ticket_item_id": 10,
      "quantity": 1
    },
    {
      "ticket_item_id": 11,
      "quantity": 1
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "charge_id": 1,
    "payment_method": "cash",
    "amount": 175.00,
    "items": [
      { "ticket_item_id": 10, "quantity": 1 },
      { "ticket_item_id": 11, "quantity": 1 }
    ]
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Create charge_item records
- Validate total quantities across charges match ticket_items
- Calculate prorated discount per charge

---

### PUT `/tickets/{id}/close`
**Description:** Process all charges and close ticket (deduct inventory, mark paid)

**Body:**
```json
{
  "confirm": true
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "order_number": "#001",
    "status": "paid",
    "closed_at": "2026-08-10T14:35:00Z",
    "charges": [
      { "id": 1, "payment_method": "cash", "amount": 175.00, "status": "paid" },
      { "id": 2, "payment_method": "gcash", "amount": 175.00, "status": "paid" }
    ]
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Validate all charges exist and items assigned
- Validate sum of charges = ticket.total
- For each ticket_item:
  - `item.quantity -= qty`
  - `item.reserved_quantity -= qty`
- Set all charges `status = 'paid'`, `paid_at = now()`
- Set ticket `status = 'paid'`, `closed_at = now()`
- Update shift totals:
  - `total_revenue += ticket.total`
  - `total_cash += cash_charges.amount`
  - `total_gcash += gcash_charges.amount`
- **AUTO-GENERATE RECEIPT(S)** for each charge
- **AUTO-PRINT** each receipt to Goojrpt PT-210
- **DISPLAY** receipt on POS screen
- Save to receipt_history table
- Wrap in transaction
- Broadcast: `ticket.paid` event

---

### GET `/charges/{charge_id}/receipt`
**Description:** Get receipt data for printing/display

**Response:**
```json
{
  "success": true,
  "data": {
    "receipt_number": "REC-2026-08-10-001",
    "charge_id": 1,
    "payment_method": "cash",
    "ticket_order_number": "#001",
    "customer_name": "john",
    "order_type": "dine_in",
    "terminal_id": "POS-01",
    "cashier_name": "Maria",
    "timestamp": "2026-08-10T14:35:00Z",
    "items": [
      {
        "name": "Fried Itik (Large)",
        "quantity": 1,
        "unit_price": 150.00,
        "line_total": 150.00
      },
      {
        "name": "Rice",
        "quantity": 1,
        "unit_price": 50.00,
        "line_total": 50.00
      }
    ],
    "subtotal": 200.00,
    "discount_prorated": 25.00,
    "total": 175.00,
    "paid": true,
    "paid_at": "2026-08-10T14:35:00Z"
  }
}
```

**Auth:** Required

---

## 8. RECEIPT HISTORY ENDPOINTS

### GET `/receipts`
**Description:** List receipts (all terminals visible on POS, filterable on back office)

**Query Params (POS):**
- `?terminal_id=POS-01` (optional, shows own if not specified)
- `?shift_id={id}`
- `?page=1&per_page=20`

**Query Params (Back Office):**
- `?shift_id={id}`
- `?date_from=2026-08-10`
- `?date_to=2026-08-11`
- `?payment_method=cash`
- `?page=1&per_page=20`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "receipt_number": "REC-2026-08-10-001",
      "order_number": "#001",
      "customer_name": "john",
      "payment_method": "cash",
      "amount": 175.00,
      "terminal_id": "POS-01",
      "printed_at": "2026-08-10T14:35:00Z",
      "is_reprint": false
    }
  ]
}
```

**Auth:** Required

---

### GET `/receipts/{receipt_id}`
**Description:** Get full receipt details

**Response:** (Same as `/charges/{charge_id}/receipt`)

**Auth:** Required

---

### POST `/receipts/{receipt_id}/reprint`
**Description:** Reprint receipt (marks as reprint, adds watermark)

**Response:**
```json
{
  "success": true,
  "message": "Receipt sent to printer",
  "data": {
    "receipt_number": "REC-2026-08-10-001",
    "is_reprint": true,
    "printed_at": "2026-08-10T15:00:00Z"
  }
}
```

**Auth:** Required

**Backend Logic:**
- Create new receipt_history entry with `is_reprint = true`
- Send to thermal printer with "DUPLICATE RECEIPT" watermark
- Display on POS screen

---

## 9. REFUND ENDPOINTS

### POST `/refunds`
**Description:** Request refund (cashier initiates, admin approves)

**Body:**
```json
{
  "ticket_id": 1,
  "amount": 350.00,
  "reason": "Customer changed mind",
  "charge_id": null
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "ticket_id": 1,
    "amount": 350.00,
    "reason": "Customer changed mind",
    "status": "pending",
    "requested_by": 3,
    "requested_at": "2026-08-10T14:40:00Z"
  }
}
```

**Auth:** Required (cashier+)

**Backend Logic:**
- Validate ticket exists and status = 'paid'
- Validate amount ≤ ticket.total
- Create refund record with status = 'pending'
- Broadcast: `refund.requested` event (notify admin)

---

### GET `/refunds`
**Description:** List refunds (pending for admin, all for back office)

**Query Params:**
- `?status=pending` (default for admin dashboard)
- `?shift_id={id}`
- `?page=1&per_page=20`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "ticket_id": 1,
      "order_number": "#001",
      "amount": 350.00,
      "reason": "Customer changed mind",
      "status": "pending",
      "requested_by": "Maria",
      "requested_at": "2026-08-10T14:40:00Z"
    }
  ]
}
```

**Auth:** Required (admin, manager)

---

### PUT `/refunds/{id}/approve`
**Description:** Approve refund (admin-only, reverses inventory)

**Body:**
```json
{
  "notes": "Approved - customer error"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "status": "approved",
    "approved_by": 1,
    "approved_at": "2026-08-10T14:45:00Z"
  }
}
```

**Auth:** Required (admin only)

**Backend Logic:**
- Set `status = 'approved'`, `approved_by = current_user.id`, `approved_at = now()`
- For each ticket_item in ticket:
  - `item.quantity += qty` (restore to inventory)
  - `item.reserved_quantity -= qty` (clear reserve)
- Update shift totals:
  - `total_revenue -= refund.amount`
  - Adjust cash/gcash based on which charge was refunded
- Broadcast: `refund.approved` event
- Wrap in transaction

---

### PUT `/refunds/{id}/reject`
**Description:** Reject refund (admin-only)

**Body:**
```json
{
  "notes": "Does not meet criteria"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "status": "rejected",
    "approved_by": 1,
    "approved_at": "2026-08-10T14:45:00Z"
  }
}
```

**Auth:** Required (admin only)

---

## 10. KITCHEN DISPLAY SYSTEM (KDS) ENDPOINTS

### GET `/kds/orders`
**Description:** Get all open orders for kitchen display (all terminals in shift)

**Query Params:**
- `?shift_id={id}` (required)
- `?status=open` (default)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "ticket_id": 1,
      "order_number": "#001",
      "customer_name": "john",
      "order_type": "dine_in",
      "terminal_id": "POS-01",
      "status": "open",
      "items": [
        {
          "ticket_item_id": 10,
          "name": "Fried Itik (Large)",
          "quantity": 2,
          "notes": "Extra crispy",
          "completed": false
        },
        {
          "ticket_item_id": 11,
          "name": "Rice",
          "quantity": 2,
          "notes": null,
          "completed": false
        }
      ],
      "created_at": "2026-08-10T14:30:00Z",
      "elapsed_seconds": 240
    },
    {
      "ticket_id": 2,
      "order_number": "#002",
      "customer_name": "maria",
      "order_type": "takeout",
      "terminal_id": "POS-02",
      "status": "open",
      "items": [
        {
          "ticket_item_id": 12,
          "name": "Pork Sinigang (Large)",
          "quantity": 1,
          "notes": "No onions",
          "completed": false
        }
      ],
      "created_at": "2026-08-10T14:32:00Z",
      "elapsed_seconds": 208
    }
  ]
}
```

**Auth:** Required

**Backend Logic:**
- Filter for `status = 'open'` (not yet paid)
- Include all items from all terminals in shift
- Calculate elapsed time from created_at to now()
- Sort by created_at (oldest first — longest waiting)
- Include `completed` flag (UI state only, stored frontend)

---

### PATCH `/kds/orders/{ticket_id}/items/{ticket_item_id}`
**Description:** Mark item as completed (kitchen marks done, removes from KDS screen)

**Body:**
```json
{
  "completed": true
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "ticket_id": 1,
    "ticket_item_id": 10,
    "completed": true
  }
}
```

**Auth:** Required (kitchen)

**Backend Logic:**
- No DB state change (v1: UI state only)
- Broadcast: `item.completed` event (notify POS terminals)
- Frontend removes from KDS view

---

## 11. EMPLOYEE ENDPOINTS (Admin Only)

### GET `/employees`
**Description:** List all employees

**Query Params:** `?role=cashier` `?status=active`

**Auth:** Required (admin only)

---

### POST `/employees`
**Description:** Create employee

**Body:**
```json
{
  "name": "Maria Santos",
  "email": "maria@restaurant.com",
  "password": "securepassword",
  "role": "cashier"
}
```

**Auth:** Required (admin only)

---

### PUT `/employees/{id}`
**Description:** Update employee

**Body:**
```json
{
  "name": "Maria Santos",
  "role": "manager",
  "status": "active"
}
```

**Auth:** Required (admin only)

---

### DELETE `/employees/{id}`
**Description:** Delete employee (soft-delete)

**Auth:** Required (admin only)

---

## 12. REAL-TIME WEBSOCKET EVENTS

### Broadcasting Channel
- `shift.{shift_id}` → all users in same shift

### Events

**1. ticket.created**
```json
{
  "event": "ticket.created",
  "data": {
    "id": 1,
    "order_number": "#001",
    "customer_name": "john",
    "terminal_id": "POS-01",
    "order_type": "dine_in",
    "status": "open",
    "created_at": "2026-08-10T14:30:00Z"
  }
}
```

**2. ticket.updated**
```json
{
  "event": "ticket.updated",
  "data": {
    "id": 1,
    "total": 350.00,
    "items_count": 4
  }
}
```

**3. ticket.paid**
```json
{
  "event": "ticket.paid",
  "data": {
    "id": 1,
    "order_number": "#001",
    "total": 350.00,
    "paid_at": "2026-08-10T14:35:00Z"
  }
}
```

**4. ticket.merged**
```json
{
  "event": "ticket.merged",
  "data": {
    "merged_into_ticket_id": 1,
    "merged_from": [2, 3],
    "total": 700.00
  }
}
```

**5. inventory.updated**
```json
{
  "event": "inventory.updated",
  "data": {
    "item_id": 1,
    "name": "Fried Itik",
    "quantity": 19,
    "reserved_quantity": 2,
    "available": 17
  }
}
```

**6. item.completed**
```json
{
  "event": "item.completed",
  "data": {
    "ticket_id": 1,
    "ticket_item_id": 10
  }
}
```

**7. refund.requested**
```json
{
  "event": "refund.requested",
  "data": {
    "id": 1,
    "ticket_id": 1,
    "amount": 350.00,
    "requested_by": "Maria"
  }
}
```

**8. refund.approved**
```json
{
  "event": "refund.approved",
  "data": {
    "refund_id": 1,
    "ticket_id": 1,
    "amount": 350.00,
    "approved_by": "Admin"
  }
}
```

---

## 13. ERROR RESPONSES

### 401 Unauthorized
```json
{
  "success": false,
  "message": "Unauthenticated"
}
```

### 403 Forbidden
```json
{
  "success": false,
  "message": "This action is unauthorized"
}
```

### 404 Not Found
```json
{
  "success": false,
  "message": "Ticket not found"
}
```

### 409 Conflict (Business Logic)
```json
{
  "success": false,
  "message": "No active shift. Open a shift first."
}
```

### 422 Unprocessable Entity (Validation)
```json
{
  "success": false,
  "errors": {
    "customer_name": ["Customer name is required"],
    "order_type": ["Order type must be dine_in or takeout"]
  }
}
```

---

## 14. PAGINATION

### Query Params
```
GET /api/tickets?page=1&per_page=20
```

### Response Format
```json
{
  "success": true,
  "data": [ /* array */ ],
  "meta": {
    "total": 150,
    "per_page": 20,
    "current_page": 1,
    "last_page": 8
  }
}
```

---

## 15. KEY IMPLEMENTATION NOTES

### Terminal Isolation
- **POS Screens:** Always pass `terminal_id` in requests
- **Queries:** Filter by `terminal_id` to show only own orders
- **KDS:** Ignore terminal_id, show all terminals' orders
- **Back Office:** Ignore terminal_id, show all orders

### Inventory Flow
```
ADD to ticket:
  reserved_qty += qty

REMOVE from ticket:
  reserved_qty -= qty

PAYMENT processed:
  quantity -= qty
  reserved_qty -= qty

REFUND approved:
  quantity += qty
  reserved_qty -= qty
```

### Transactions
- Wrap payment + inventory changes in DB::transaction()
- Prevents race conditions with multiple POS terminals

### WebSocket Broadcasting
- Use `channel('shift.' . $shift_id)`
- All terminals in same shift get updates
- Use `.toOthers()` to avoid echo

---

**End of Unified API Endpoints v1.0**
