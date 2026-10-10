# KDS App Guide (React Native)

A build guide for the kitchen display: a tablet in the kitchen showing every order still to cook, from every POS phone, oldest first. The cooks tap to "bump" what's done. It talks to the Laravel server on the Intel NUC (`/api/v1`). The server side is already built and tested.

The authoritative API contract is `docs/UNIFIED_API_ENDPOINTS.md` in the Laravel repo (§8.5 KDS, §8.6 offline sync). If this guide and that file ever disagree, that file wins.

---

## 1. What the KDS is (and isn't)

- **A live list of what to cook.** Open orders only, strictly **oldest first** (first in, first out across all phones).
- **Bump = gone.** Tapping a done item removes it from the screen; it does **not** stay as a ticked box. When every item on an order is bumped, the order disappears. If the cashier later adds something to that order, the order comes back showing **only the new items**.
- **No money, no terminals.** The feed never contains prices or which phone took the order.
- **Fees never show** (e.g. a delivery fee isn't food). Custom items (a dish typed by the cashier) do show.
- **Stockless variants show.** A "Lagi" modifier is kitchen information, so show it like any modifier.

**Unlike the POS, the KDS is online-only.** In a power cut the tablet can't reach the server. The POS phones keep selling offline and print **paper kitchen slips** instead. When the power returns, those orders reach the server **already bumped** (the kitchen cooked from the slips), so they never flood the KDS. The KDS just has to show clearly that it's disconnected (§8).

---

## 2. Suggested stack

| Need | Use |
|---|---|
| App | Expo; landscape, kept awake (`expo-keep-awake`), large fonts and touch targets |
| Token | `expo-secure-store` |
| Realtime | `pusher-js` (React Native build) with `laravel-echo`, talking to **Laravel Reverb** (Pusher protocol) |
| Network status | `@react-native-community/netinfo` plus the WebSocket connection state |
| State | A map `ticket_id → card`, kept sorted by `created_at` (Zustand or a reducer) |

### Allow plain `http://` and `ws://` (or the installed app can't reach the NUC)

The NUC serves `http://` (API) and `ws://` (Reverb) on the local network. Tablets block plain traffic by default in **installed** builds, even when development works:

- **Android:** the `expo-build-properties` plugin with `{ "android": { "usesCleartextTraffic": true } }`.
- **iOS:** in `ios.infoPlist`, set `NSAppTransportSecurity` → `NSAllowsLocalNetworking: true` (use `NSAllowsArbitraryLoads: true` if requests to the NUC's IP still fail), plus an `NSLocalNetworkUsageDescription`.

Build an installed release early and test it against the NUC.

### Testing on a real tablet while developing

The tablet and the development PC must be on the same Wi-Fi:

1. `php artisan serve --host=0.0.0.0 --port=8000` (MySQL running) and `php artisan reverb:start` (port 8080).
2. Allow ports 8000 and 8080 through Windows Firewall: `New-NetFirewallRule -DisplayName "Epos dev" -Direction Inbound -Protocol TCP -LocalPort 8000,8080 -Action Allow` (admin PowerShell).
3. Use the PC's IPv4 address from `ipconfig` for both the API and Reverb host, never `localhost`.
4. To see live updates, place orders from a POS phone (or the API) while the KDS is open. The back office's Kitchen Orders page shows the same cards, so you can compare the two.

---

## 3. Sign in with a kitchen-only token

`POST /api/v1/auth/login`

```json
{ "username": "kitchen", "password": "…", "device_name": "KDS-01", "scope": "kds" }
```

- **`scope: "kds"`** gives a token that can **only** read the kitchen feed and bump items. If the tablet is lost or stolen it can't touch payments, tickets or refunds; everything else answers `403`. Always use it.
- **The account:** use a dedicated staff account (e.g. a cashier-role user named "kitchen"), so the back office's Devices page shows the tablet clearly.
- **The token never expires.** Store it in SecureStore. A manager can revoke it from the back office (Employees → the account → Devices). On a `401`, show the login screen.
- **Headers on every request:** `Accept: application/json` and `Authorization: Bearer <token>`.
- **Responses:**
  - Success: `{ "success": true, "data": … }`
  - Error: `{ "success": false, "message": "…" }`

---

## 4. The feed

`GET /api/v1/kds/orders` → `data`:

```json
[
  {
    "ticket_id": 10,
    "order_number": "#001",
    "customer_name": "john",
    "order_type": "dine_in",
    "created_at": "2026-10-01T04:00:00.000000Z",
    "items": [
      {
        "ticket_item_id": 55,
        "item_name": "Sisig Itik",
        "quantity": 2,
        "notes": "Extra crispy",
        "modifiers": [{ "name": "Lagi" }]
      }
    ]
  }
]
```

- **Already filtered** by the server: open orders only, items not yet bumped, no voided items, no fees. Don't filter again.
- **Order:** `created_at` ascending (oldest first). Keep that order whenever you update the list.
- **`created_at` is UTC.** Show the waiting time, not the clock time.
- **`order_type`:** `dine_in` or `takeout`. Make takeout stand out.

---

## 5. Live updates (Reverb / WebSocket)

**Connection** (ask whoever runs the NUC for the values in its `.env`):

| Setting | Value |
|---|---|
| Key | `REVERB_APP_KEY` |
| Host | the NUC's address (`REVERB_HOST`, e.g. `192.168.68.10`) |
| Port | `REVERB_PORT` (8080) |
| TLS | off on the local network (`REVERB_SCHEME=http`) → `forceTLS: false`, transports `ws` only |
| Auth endpoint | `http://<nuc-ip>:8000/api/v1/broadcasting/auth` with the header `Authorization: Bearer <token>`. This is **not** the default `/broadcasting/auth`, which is for the browser back office |
| Channel | private channel **`kds.orders`** (wire name `private-kds.orders`) |

```js
const echo = new Echo({
  broadcaster: 'reverb',            // or 'pusher' with the same options
  key: REVERB_APP_KEY,
  wsHost: NUC_IP, wsPort: 8080, forceTLS: false, enabledTransports: ['ws'],
  authEndpoint: `http://${NUC_IP}:8000/api/v1/broadcasting/auth`,
  auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  client: new Pusher(REVERB_APP_KEY, { /* same options */ }),
});

echo.private('kds.orders')
  .listen('.ticket.created', onCard)      // leading dot: custom event names
  .listen('.ticket.updated', onCard)
  .listen('.ticket.paid', onGone)
  .listen('.ticket.cancelled', onGone)
  .listen('.ticket.merged', onMerged)
  .listen('.item.completed', onItemCompleted)
  .listen('.item.uncompleted', () => refetch());
```

**What each event means:**

| Event | Payload | Do |
|---|---|---|
| `ticket.created` | a full card (same shape as a feed row) | Add or replace it by `ticket_id` |
| `ticket.updated` | a full card: items added, removed or changed, or a merge | **Replace** the card. If `items` is **empty**, remove the card (everything's done) |
| `ticket.paid` | `{ ticket_id, order_number }` | Remove the card |
| `ticket.cancelled` | `{ ticket_id, order_number }` | Remove the card |
| `ticket.merged` | `{ ticket_id, order_number, removed_ticket_ids, removed_order_numbers }` | Remove every `removed_ticket_ids` card (the target's own card comes as `ticket.updated`) |
| `item.completed` | `{ ticket_id, ticket_item_id }` | Remove that item (another screen bumped it); remove the card if it's now empty |
| `item.uncompleted` | `{ ticket_id, ticket_item_id }` | An item was un-bumped. The payload has no item details, so **refetch the feed** |

**Rules:**

- **Replace, don't merge.** A `ticket.*` card is the complete current state of that order; overwrite the old card.
- **The server is the truth.** Refetch `GET /kds/orders` and replace everything:
  - when the WebSocket connects or **reconnects**;
  - when the app returns to the foreground;
  - every 60 s as a safety net.

  Events can be missed during a reconnect, and the refetch heals that.
- **No WebSocket?** If Reverb can't be reached but the API can, **poll** `GET /kds/orders` every 5 s (what the back office's Kitchen Orders page does) until the socket is back.
- **Discounts don't broadcast.** The KDS shows no money, so there's nothing to update.

---

## 6. Bumping

| Gesture | Call | Result |
|---|---|---|
| Tap an item | `PATCH /api/v1/kds/orders/items/{ticket_item_id}/complete` with `{ "completed": true }` | That item disappears |
| Tap the customer name (bump the whole order) | `PATCH /api/v1/kds/orders/{ticket_id}/complete` (no body) | Every remaining item is done; the card disappears |
| Undo | `PATCH /api/v1/kds/orders/items/{ticket_item_id}/complete` with `{ "completed": false }` | The item comes back (refetch) |

- **Hide it right away** (optimistic), then make the call.
- **On `409`** (the order was paid or cancelled meanwhile, or the item was removed) → nothing to undo; just refetch.
- **On a network error** → keep the bump in a small local retry list and resend when connected. Keep the item hidden. Bumping twice is harmless.
- **Undo tray:** a bumped item leaves the feed, so keep the last ~10 bumps in a "Recently bumped" strip for ~2 minutes, each with **Undo**. Undo is per item; for a whole-order bump, list its items.
- **Several screens:** every KDS gets the same `item.completed` / `ticket.updated` events, so a bump on one screen disappears on all of them.
- **No passcode:** bumping isn't removing an item from the bill.

---

## 7. Card layout and timers

```
┌─────────────────────────────────────┐
│ #001 · john        DINE-IN   12 min │   ← tap the name = bump the whole order
├─────────────────────────────────────┤
│ 2× Sisig Itik                       │   ← tap a line = bump it
│    Lagi                             │
│    Note: Extra crispy               │
│ 1× Fried Itik                       │
└─────────────────────────────────────┘
```

- **Waiting time** since `created_at`, updated every ~15 s. Use the server clock: take the HTTP `Date` header of the feed response, keep `offset = serverDate − Date.now()`, and use it in the timer, so a tablet with a wrong clock still shows the right wait.
- **Colours** (the same as the back office Kitchen Orders page and the dashboard's "late" rule):
  - under 10 min: normal;
  - **10 min+: amber**;
  - **20 min+: red**.
- **Notes stand out** (italic or a "Note:" prefix). They're instructions for the cook and never on the customer's receipt.
- **Modifiers** go under the item, **including Lagi**.
- Big text, readable from a distance; landscape grid of cards in columns, oldest top-left.

---

## 8. When the connection drops

- **Show a full-width red banner,** e.g. **"Disconnected — new orders won't appear. Use the paper slips from the POS."**, as soon as both the WebSocket and the API stop answering. Keep showing the last known cards, dimmed. Don't clear them.
- **Bumps made while disconnected** go into the retry list (§6) and are sent on reconnect.
- **On reconnect:** refetch the feed and replace everything, resubscribe, send any pending bumps, then hide the banner.
- **After a power cut:** orders the phones took offline arrive at the server already bumped, so they **don't** appear. That's intended: the kitchen already cooked them from paper.

---

## 9. Good to know

- **Nightly reset at 03:00:** the server clears every "bumped" mark overnight (`kds:clear-completed`). An order still **open** past 03:00 (unusual) shows up again in full the next morning.
- **Merged orders:** when the cashier merges two orders, the absorbed order's card disappears and the target card reappears with all the remaining items.
- **Add-ons:** a new item on an already-served order brings the card back with **only** that item. Its `created_at` is still the order's original time, so it sits near the top (oldest). That's intended: an add-on to an old order is urgent.

---

## 10. Test before trusting it

1. Order on a POS phone, and the card appears within a second; bump an item and it disappears on every KDS screen.
2. Bump the whole order by tapping the name; undo one item from the tray and it comes back.
3. Pay or cancel an order on the POS, and the card disappears.
4. Stop Reverb only: the KDS switches to 5 s polling and keeps working.
5. Unplug the NUC: the red banner shows and the last cards stay; plug it back in and it refetches cleanly. Orders taken offline on phones in the meantime don't appear.
6. Set the tablet's clock 10 minutes wrong: the waiting time is still correct.
7. Log in with a non-KDS token by mistake: the app works, but prefer `scope: "kds"`. Check that a KDS token gets `403` on `GET /api/v1/items`.
