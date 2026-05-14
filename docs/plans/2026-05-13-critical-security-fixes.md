# Critical Security & Correctness Fixes — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix the 5 highest-severity items surfaced in the 2026-05-13 review of apisunatv2: an unauthenticated AJAX endpoint, a missing idempotency lock on CPE emission, plaintext API tokens in the UI/transport, a tax-rate activation bug that double-taxes existing stores, and a missing bulk-action nonce.

**Architecture:** Each fix is local to one or two files. No new abstractions, no DI rewrite, no test harness. We commit to `main` after each task, one task per commit.

**Tech Stack:** PHP 8.1+, WordPress 6.x, WooCommerce 8+ (HPOS), Action Scheduler, jQuery.

**Scope (out):** Items #4, #7, #8 from the review. Token at-rest encryption. Refactors. Tests.

---

## Branch & Verification Setup

- Working directly on `main`. No branch.
- No automated tests in this repo. Each task lists manual smoke-test commands (curl, browser steps).
- Commits use Conventional Commits prefix `fix:` or `fix(security):`. Reference review item numbers in the body.

---

## Task 1: Add nonce verification to bulk-action handler (#6)

**Files:**
- Modify: [src/Admin/OrderActions.php:117-151](../../src/Admin/OrderActions.php#L117-L151)

**Why first:** Smallest change, no behavioral risk for legit users (WP already attaches the bulk nonce). Establishes the commit rhythm.

**Step 1: Add nonce check at top of `bulkHandle`**

After `if ($action !== 'sunat_emit') return $redirect;` and the capability check, add:

```php
$nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
if (!$nonce || !wp_verify_nonce($nonce, 'bulk-orders')) {
    return $redirect;
}
```

**Step 2: Manual verification**

1. Open WC orders list, select 2 orders, choose "Emitir CPE", click Apply. Expect: normal emission attempt (since WP's table emits `_wpnonce` with `bulk-orders`).
2. Construct a stale link manually:
   ```
   /wp-admin/admin.php?page=wc-orders&action=sunat_emit&id[]=123&_wpnonce=invalid
   ```
   Visit it logged in as admin. Expect: no emission, no admin notice with "emitidos" count.
3. Confirm the legitimate bulk action still shows the success/fail counter on redirect.

**Step 3: Commit**

```bash
git add web/app/plugins/apisunatv2/src/Admin/OrderActions.php
git commit -m "fix(security): verify bulk nonce in OrderActions::bulkHandle

Review item #6. Adds wp_verify_nonce('bulk-orders') check before
processing the sunat_emit bulk action, so a stale-nonce CSRF link
that lands on an admin session cannot trigger live CPE emission."
```

---

## Task 2: Scope tax-rate creation to PE and skip if rate exists (#5)

**Files:**
- Modify: [src/Activator.php:62-76](../../src/Activator.php#L62-L76)

**Why:** `_insert_tax_rate` runs on every activation if `apisunat_tax_setup_done` was somehow cleared, and even on first activation it creates a *global* (no country) 18% rate. Stores that already have an IGV rate get a duplicate at priority 1 → WooCommerce sums them → 36% tax.

**Step 1: Replace the rate-creation block**

Replace lines 61-76 (the `if ($std && !get_option('apisunat_tax_setup_done'))` block) with:

```php
if (get_option('apisunat_tax_setup_done')) {
    return;
}

$existing = \WC_Tax::find_rates([
    'country'   => 'PE',
    'tax_class' => '',
]);

$hasIgv = false;
foreach ($existing as $rate) {
    if ((float) ($rate['rate'] ?? 0) >= 17.5 && (float) ($rate['rate'] ?? 0) <= 18.5) {
        $hasIgv = true;
        break;
    }
}

if (!$hasIgv) {
    \WC_Tax::_insert_tax_rate([
        'tax_rate_country'  => 'PE',
        'tax_rate_state'    => '',
        'tax_rate'          => '18',
        'tax_rate_name'     => 'IGV',
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => 1,
        'tax_rate_order'    => 0,
        'tax_rate_class'    => '',
    ]);
}

update_option('apisunat_tax_setup_done', '1');
```

**Step 2: Remove the now-dead `$std = get_option('woocommerce_default_country')` line above it.**

**Step 3: Manual verification**

1. On a clean WP install with WC and no tax rates:
   - Delete `apisunat_tax_setup_done` via `wp option delete apisunat_tax_setup_done`.
   - Re-activate the plugin (`wp plugin deactivate apisunatv2 && wp plugin activate apisunatv2`).
   - Expect: one IGV rate exists, `tax_rate_country = 'PE'`, no duplicates.
2. On a site that already has an IGV 18% PE rate:
   - Delete `apisunat_tax_setup_done`.
   - Re-activate the plugin.
   - Expect: rate count unchanged, `apisunat_tax_setup_done` set to '1'.
3. Verify with: `wp db query "SELECT tax_rate_id, tax_rate, tax_rate_country, tax_rate_name FROM wp_woocommerce_tax_rates;"`

**Step 4: Commit**

```bash
git add web/app/plugins/apisunatv2/src/Activator.php
git commit -m "fix(activation): scope auto-created IGV rate to PE, skip if exists

Review item #5. Previously the activator unconditionally inserted a
global 18% rate at priority 1 with no country restriction, which on
stores that already had an IGV rate caused WooCommerce to sum both
rates (36% effective). Now we look up existing PE standard-class
rates in the 17.5-18.5% band first and only insert if none found,
and the inserted rate is scoped to country=PE."
```

---

## Task 3: Token UI as password + show toggle (#3 part 1)

**Files:**
- Modify: [src/Admin/SettingsPage.php:482](../../src/Admin/SettingsPage.php#L482) (server-rendered branch row)
- Modify: [src/Admin/SettingsPage.php:520](../../src/Admin/SettingsPage.php#L520) (JS-cloned branch row)
- Modify: [assets/js/settings.js](../../assets/js/settings.js) (add toggle handler at end)

**Why:** `persona_token` is currently rendered with `type="text"`, visible to anyone over the shoulder and in browser autofill history. Switching to `type="password"` with a "show" toggle keeps it usable while masking by default.

**Step 1: Edit server-rendered token input**

In `renderBranches`, change the persona_token input at line 482 from:

```php
<input type="text" name="<?= esc_attr($baseName . '][' . $i) ?>][persona_token]" value="<?= esc_attr($branch['persona_token'] ?? '') ?>" placeholder="<?= esc_attr__('Persona Token', 'apisunatv2') ?>" class="regular-text">
```

to:

```php
<input type="password" name="<?= esc_attr($baseName . '][' . $i) ?>][persona_token]" value="<?= esc_attr($branch['persona_token'] ?? '') ?>" placeholder="<?= esc_attr__('Persona Token', 'apisunatv2') ?>" class="regular-text apisunat-token-input" autocomplete="new-password">
<button type="button" class="button apisunat-toggle-token" aria-label="<?= esc_attr__('Mostrar/ocultar token', 'apisunatv2') ?>"><?= esc_html__('👁', 'apisunatv2') ?></button>
```

**Step 2: Edit JS-cloned branch row**

In the same file at the inline `<script>` block (around line 520), change the persona_token line in the `innerHTML` string from `type="text"` to `type="password"` and append the toggle button:

```js
'<input type="password" name="<?= esc_attr($baseName) ?>[' + idx + '][persona_token]" placeholder="<?= esc_attr__('Persona Token', 'apisunatv2') ?>" class="regular-text apisunat-token-input" autocomplete="new-password">' +
'<button type="button" class="button apisunat-toggle-token" aria-label="<?= esc_attr__('Mostrar/ocultar token', 'apisunatv2') ?>">👁</button>' +
```

**Step 3: Add toggle handler in settings.js**

At the bottom of `assets/js/settings.js`, inside the existing `$(function() {...})` IIFE, before its closing `});`, add:

```js
$(document).on('click', '.apisunat-toggle-token', function (e) {
    e.preventDefault();
    var $input = $(this).closest('.branch-row').find('.apisunat-token-input');
    if (!$input.length) return;
    $input.attr('type', $input.attr('type') === 'password' ? 'text' : 'password');
});
```

**Step 4: Manual verification**

1. Open the API tab in plugin settings. Confirm token input is masked by default (dots).
2. Click 👁 button. Confirm token reveals as text.
3. Click 👁 again. Confirm it masks.
4. Click "Agregar sucursal" — the new row's token must also be masked and the toggle must work.
5. Save settings. Confirm token persists (round-trip).

**Step 5: Commit**

```bash
git add web/app/plugins/apisunatv2/src/Admin/SettingsPage.php web/app/plugins/apisunatv2/assets/js/settings.js
git commit -m "fix(security): mask persona_token in settings UI with toggle

Review item #3 (UI). Render the persona_token input as type=password
with autocomplete=new-password and a show/hide toggle button.
Applies to both the server-rendered branch row and the JS clone
template for the 'Add branch' button."
```

---

## Task 4: Move ajaxTestApi creds from query string to POST body (#3 part 2)

**Files:**
- Modify: [src/Admin/SettingsPage.php:145-194](../../src/Admin/SettingsPage.php#L145-L194)

**Why:** `ajaxTestApi` currently builds the apisunat.com URL with `personaId` + `personaToken` as **query-string parameters**, so the secrets land in apisunat.com's HTTP access logs and any intermediary proxy logs. apisunat.com's `getAll` accepts the same creds in the JSON body.

**Step 1: Replace the URL/request in `ajaxTestApi`**

Replace lines 164-173 (the `add_query_arg(...)` block and the `wp_remote_get` call) with:

```php
$response = wp_remote_post(
    ApiSunatService::BASE_URL . '/documents/getAll',
    [
        'timeout' => 15,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'personaId'    => $personaId,
            'personaToken' => $personaToken,
            'limit'        => 1,
        ]),
    ]
);
```

**Step 2: Manual verification**

1. Open browser devtools → Network. Click the "Verificar" button next to a branch.
2. Inspect the `admin-ajax.php` request — confirm the request body (POST) carries the token, and confirm the server-side outbound to `back.apisunat.com/documents/getAll` is a POST with a JSON body (you cannot see this in the browser, but you can verify by tailing the plugin log with `Options.advanced.debug=true` or by running a quick `curl -X POST -d '{"personaId":"...","personaToken":"...","limit":1}' -H "Content-Type: application/json" https://back.apisunat.com/documents/getAll` and confirming a 200).
3. The "Verificar" result text should still show "Conexión exitosa" or the upstream error message.

**If apisunat.com's `/documents/getAll` rejects POST with `Content-Type: application/json`,** revert this task and document — but the SUNAT payload elsewhere already uses POST + JSON, so this is expected to work.

**Step 3: Commit**

```bash
git add web/app/plugins/apisunatv2/src/Admin/SettingsPage.php
git commit -m "fix(security): send test-API credentials in POST body, not URL

Review item #3 (transport). Previously ajaxTestApi placed personaId
and personaToken in the query string of a GET request to apisunat.com,
causing the secrets to be logged by upstream and any intermediary
proxies. Switch to wp_remote_post with the credentials in a JSON
body."
```

---

## Task 5: Rate-limit + referer-gate the AJAX consulta endpoint (#1)

**Files:**
- Modify: [src/Hooks/CheckoutHooks.php:242-260](../../src/Hooks/CheckoutHooks.php#L242-L260)

**Why:** `wp_ajax_nopriv_apisunat_consult_document` is registered for guest checkout. The handler only verifies a nonce — but the nonce is publicly bootstrapped via `wp_localize_script` on the checkout page, so any unauthenticated visitor can scrape RUC/DNI lookups via your apisunat.com Bearer token. We need: (a) referer must be the checkout URL, (b) per-IP rate limit, (c) input bounds.

**Step 1: Rewrite `ajaxConsultDocument`**

Replace the entire method body (lines 242-260) with:

```php
public static function ajaxConsultDocument(): void {
    check_ajax_referer(self::NONCE_ACTION, 'nonce');

    // Gate 1: request must originate from the checkout page
    $referer = wp_get_referer();
    $checkoutUrl = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '';
    if (!$referer || !$checkoutUrl || strpos($referer, untrailingslashit($checkoutUrl)) === false) {
        wp_send_json_error(['message' => __('Contexto inválido', 'apisunatv2')], 403);
    }

    // Gate 2: per-IP rate limit (20 lookups / 10 minutes)
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    if ($ip !== '') {
        $rlKey = 'apisunat_consult_rl_' . md5($ip);
        $count = (int) get_transient($rlKey);
        if ($count >= 20) {
            wp_send_json_error(['message' => __('Demasiadas solicitudes. Intenta más tarde.', 'apisunatv2')], 429);
        }
        set_transient($rlKey, $count + 1, 10 * MINUTE_IN_SECONDS);
    }

    $docType   = isset($_POST['doc_type'])   ? sanitize_text_field(wp_unslash($_POST['doc_type']))   : '';
    $docNumber = isset($_POST['doc_number']) ? sanitize_text_field(wp_unslash($_POST['doc_number'])) : '';

    if ($docType === '' || $docNumber === '') {
        wp_send_json_error(['message' => __('Parámetros inválidos', 'apisunatv2')]);
    }

    // Gate 3: input bounds — DNI is 8 digits, RUC is 11 digits
    if (!ctype_digit($docNumber) || strlen($docNumber) < 8 || strlen($docNumber) > 11) {
        wp_send_json_error(['message' => __('Número de documento inválido', 'apisunatv2')]);
    }
    if (!in_array($docType, ['1', '6'], true)) {
        wp_send_json_error(['message' => __('Tipo de documento no soportado', 'apisunatv2')]);
    }

    $result = $docType === '6'
        ? DocumentConsultaService::consultRuc($docNumber)
        : DocumentConsultaService::consultDni($docNumber);

    if ($result) {
        wp_send_json_success($result);
    }
    wp_send_json_error(['message' => __('No se encontró información', 'apisunatv2')]);
}
```

**Step 2: Manual verification**

1. Guest checkout happy path: Open an incognito window, add a product to cart, go to checkout. Type a real RUC. The auto-fill JS should call `apisunat_consult_document` and return the razón social. Confirm a 200 with success payload.
2. Direct curl with no referer:
   ```bash
   NONCE=$(curl -s -c /tmp/cj 'https://YOURSITE/checkout/' | grep -oP 'apisunatv2_checkout":\{[^}]*"nonce":"\K[^"]+')
   curl -X POST 'https://YOURSITE/wp-admin/admin-ajax.php' \
        -b /tmp/cj \
        -d "action=apisunat_consult_document&nonce=${NONCE}&doc_type=6&doc_number=20131312955"
   ```
   Expect: 403 "Contexto inválido" (no Referer header).
3. Same as above but with `--referer https://YOURSITE/checkout/`. Expect: 200 (or the upstream "no se encontró" if test RUC).
4. Rapid-fire 25 valid requests from one IP. Expect: first ~20 work, then 429 "Demasiadas solicitudes".
5. Malformed input: `doc_number=abc` → 400 "Número de documento inválido". `doc_number=1234567890123` → 400 (length).

**Step 3: Commit**

```bash
git add web/app/plugins/apisunatv2/src/Hooks/CheckoutHooks.php
git commit -m "fix(security): gate AJAX consulta endpoint by referer and rate limit

Review item #1. The wp_ajax_nopriv_apisunat_consult_document handler
was reachable by any guest with the public checkout nonce, turning
the site into a free RUC/DNI lookup proxy against the merchant's
apisunat.com Bearer token. Add:
  - referer must match wc_get_checkout_url()
  - per-IP rate limit (20 req / 10 min via transient)
  - input validation: doc_type in {1,6}, doc_number is 8-11 digits"
```

---

## Task 6: Idempotency lock + transient-vs-permanent error handling (#2)

**Files:**
- Modify: [src/Services/ApiSunatService.php:34-81](../../src/Services/ApiSunatService.php#L34-L81) (lock around `send()`)
- Modify: [src/Jobs/SendOrderJob.php:27-39](../../src/Jobs/SendOrderJob.php#L27-L39) (don't mark transient failures as ERROR)
- Modify: [src/Exceptions/ApiRequestException.php](../../src/Exceptions/ApiRequestException.php) (add `isTransient()` helper)

**Why:** Currently the only guard against double emission is a status meta read at the top of `send()`. Two concurrent triggers (e.g. status-transition + manual click) both pass the guard → SUNAT issues two CPEs. Also, `SendOrderJob` writes `_apisunat_document_status = 'ERROR'` on any throwable including `cURL error 28` (timeout), so transient failures become permanent and block Action Scheduler retries.

**Step 1: Add `isTransient()` to ApiRequestException**

Read the file first to confirm its current shape:

```bash
cat web/app/plugins/apisunatv2/src/Exceptions/ApiRequestException.php
```

If the class extends `ApiException` and has a `protected int $code` from the constructor signature `__construct(string $message, int $code = 0)`, add this method:

```php
public function isTransient(): bool {
    // Network errors (wp_error path) come through with code = 0.
    // 5xx and 408/429 are retryable. Everything else is permanent.
    if ($this->code === 0) {
        return true;
    }
    return $this->code === 408
        || $this->code === 429
        || ($this->code >= 500 && $this->code < 600);
}
```

(If the existing constructor doesn't store `code`, also store it via `parent::__construct($message, $code)`.)

**Step 2: Add atomic lock helpers + use them in `send()`**

In `ApiSunatService`, just above `send()`, add:

```php
private static function acquireLock(int $orderId): bool {
    $key = 'apisunat_send_lock_' . $orderId;
    // add_option is atomic: returns false if the option already exists
    // (wp_options.option_name has a UNIQUE constraint).
    if (add_option($key, time() + 60, '', 'no')) {
        return true;
    }
    // Lock present — check if it has expired (stale lock from a crashed process).
    $expires = (int) get_option($key, 0);
    if ($expires > 0 && $expires < time()) {
        update_option($key, time() + 60, false);
        return true;
    }
    return false;
}

private static function releaseLock(int $orderId): void {
    delete_option('apisunat_send_lock_' . $orderId);
}
```

Then modify the body of `send()` (around line 34-81). After the initial `$order` check and the `isAlreadyProcessed` guard, wrap the rest of the method in a lock acquisition + try/finally. The new shape:

```php
public static function send(int|string $order_id): void {
    $order_id = (int) $order_id;
    $order    = wc_get_order($order_id);
    if (!$order) {
        throw new InvalidOrderException("Order #{$order_id} not found");
    }

    self::logger()->info('Sending order to SUNAT', ['order_id' => $order_id]);

    if (self::isAlreadyProcessed($order)) {
        self::logger()->debug('Order already processed, skipping', ['order_id' => $order_id]);
        return;
    }

    if (!self::acquireLock($order_id)) {
        self::logger()->info('Send already in progress, skipping concurrent call', ['order_id' => $order_id]);
        return;
    }

    try {
        // Re-check status AFTER acquiring the lock — another process may have
        // just finished while we were waiting.
        $order = wc_get_order($order_id); // re-read fresh meta
        if (self::isAlreadyProcessed($order)) {
            self::logger()->debug('Order processed by concurrent send, skipping', ['order_id' => $order_id]);
            return;
        }

        // --- existing body below this line, unchanged ---
        $branch = Options::resolveBranch($order);
        if (empty($branch['persona_id']) || empty($branch['persona_token'])) {
            throw new MissingCredentialsException(__('Credenciales API no configuradas', 'apisunatv2'));
        }
        // ... (everything through the as_enqueue_async_action block) ...
    } finally {
        self::releaseLock($order_id);
    }
}
```

**Step 3: Stop downgrading transient failures to ERROR in SendOrderJob**

Replace the catch block in `SendOrderJob::handle` (lines 31-39) with:

```php
} catch (\Throwable $e) {
    Logger::instance()->error('SendOrderJob failed', [
        'order_id' => $order_id,
        'error'    => $e->getMessage(),
    ]);
    $order->add_order_note('API Sunat: ' . $e->getMessage());

    $isPermanent = !($e instanceof \Atm\Apisunatwp\Exceptions\ApiRequestException && $e->isTransient());
    if ($isPermanent) {
        $order->update_meta_data('_apisunat_document_status', 'ERROR');
        $order->save();
    }

    throw $e; // Re-throw so Action Scheduler can retry transient failures
}
```

Add the `use` statement at top of `SendOrderJob.php`:
```php
use Atm\Apisunatwp\Exceptions\ApiRequestException;
```

(If preferred, drop the FQCN in the conditional and use the imported name.)

**Step 4: Manual verification**

1. Happy path: Trigger an emission on a fresh order via the metabox. Confirm CPE issues and status moves to PENDIENTE/ACEPTADO. Confirm `wp option get apisunat_send_lock_<orderid>` returns nothing after the call completes (lock was released).
2. Concurrent emission simulation: Open the metabox and the orders list in two tabs. Click "Emitir" on both within ~1 second.
   - Expect: only one CPE issued (check `_apisunat_document_id` is set once, no duplicate order notes about "CPE issued").
   - The losing tab returns silently (the AJAX shows the success message but `send()` actually returned early).
3. Transient failure: Temporarily change `API_URL` to a non-routable host (e.g. `https://10.255.255.1/v1.2/woocommerce`) to force a timeout. Trigger emission.
   - Expect: order note added with the timeout error, `_apisunat_document_status` is **NOT** `ERROR`, and a retry via Action Scheduler is possible.
4. Permanent failure: With the real API_URL restored, send an order with missing required fields so SUNAT returns 422.
   - Expect: order note with the SUNAT error code, `_apisunat_document_status` IS `ERROR`.
5. Restore API_URL when done.

**Step 5: Commit**

```bash
git add web/app/plugins/apisunatv2/src/Services/ApiSunatService.php \
        web/app/plugins/apisunatv2/src/Jobs/SendOrderJob.php \
        web/app/plugins/apisunatv2/src/Exceptions/ApiRequestException.php
git commit -m "fix: idempotency lock on send() + transient-aware error handling

Review item #2. Two problems addressed:

1. Two concurrent send() calls could pass the in_array(...) status
   guard simultaneously and both issue a CPE against the same order.
   Now we acquire a 60-second lock via add_option (atomic at the DB
   level via the UNIQUE constraint on option_name) and re-check the
   processed-status after acquiring the lock.

2. SendOrderJob previously stamped _apisunat_document_status=ERROR
   on any throwable, including cURL timeouts and 5xx responses,
   making transient failures permanent. ApiRequestException now
   exposes isTransient() (true for code=0, 408, 429, 5xx), and the
   job only writes ERROR for permanent failures; transient errors
   re-throw so Action Scheduler retries."
```

---

## Final verification (after all 6 tasks)

1. Run a full smoke test on a real checkout: classic form, guest user, RUC consulta works, order completes, CPE is issued.
2. Check `wp option list --search="apisunat_send_lock_*"` — should be empty (no stale locks).
3. Confirm the WP `error_log` / plugin log file has no PHP fatals or warnings from any of these files.
4. Optional: push to a staging server, run `wp plugin deactivate apisunatv2 && wp plugin activate apisunatv2` to exercise the activator with the new tax-rate check.

---

## Out-of-scope reminders (for follow-up planning)

These items from the 2026-05-13 review remain unresolved after this plan and should be tracked separately:

- **#4** Sync emission on `woocommerce_new_order` before payment (CheckoutHooks::syncBlockFields).
- **#7** Tax/items math: `TaxMapper` collapse-to-standard, `ItemsMapper` wrong unit_price.
- **#8** RUC regex over-restricts valid 2X RUCs.
- **High items** (#9–#26 from review): payload PII in order meta, log redaction gaps, HPOS list-table compat, uninstall option keys.
