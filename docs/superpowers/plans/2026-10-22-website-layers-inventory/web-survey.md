> Survey of commit 51625d6 (develop after PR #15). Line numbers refer to that commit.

# Web layer survey (read-only), repo /DATA/home/quyen/Downloads/shop_quan_ao

Conventions: "bootstrap" = `require_once __DIR__.'/../src/bootstrap.php'` (src/bootstrap.php is a 1-liner requiring src/core/bootstrap.php; provides `$pdo` global).
`header.php` (templates/partials/header.php) itself does `if (session_status()===PHP_SESSION_NONE) session_start();`, `require_once bootstrap`, computes `$total_items` (SELECT SUM(quantity) FROM cart WHERE user_id=?), then prints `<!DOCTYPE html>...<head>...<nav>`. `footer.php` prints `</body></html>` then `include chatbox.php`.

IMPORTANT for "byte-identical": nearly all page files are **CRLF** (public/login.php, process_checkout.php, admin/login.php, admin/manage_products.php are LF; admin/edit_product.php and admin/manage_users.php are mixed CRLF+LF). Moving markup to templates/pages/*.php must copy bytes (use `sed -n 'a,bp' > file`/git mv-style extraction, not an editor that normalises EOL). Also trailing bytes: index/product/cart/checkout/profile/register end with `?>` and NO trailing newline after it (so footer include line `<?php include ... ; ?>` is last bytes, no `\n`). order_details.php ends with `</html>` with no newline. PHP strips a single newline after `?>`, so be careful about the newline directly after any `?>` that ends the logic block (e.g. `?>\r\n\r\n<div` : PHP eats the first `\r\n`? it eats a single "\n" or "\r\n" right after `?>`; the blank line stays). When moving, keep an identical `?>` + blank-line layout or compare outputs with curl diff before/after.

---------------------------------------------------------------------------------
## PART 1 - per page

### Catalog

**public/index.php (91 lines)**
- Bootstrap: `require_once bootstrap` (no session_start itself; header.php starts session).
- Order: bootstrap -> `include header.php` (HTML output starts HERE, line 3) -> logic -> markup -> footer. So logic runs AFTER the header output (no redirect possible later; none exist).
- Guard: none (public).
- Input: `$_GET['search']` (default ''), `$_GET['category']` (default ''). No validation. SQL: `SELECT * FROM categories`; `SELECT * FROM products WHERE name LIKE ?` (`%$search%`) + optional `AND category_id = ?`.
- HTML begins line 27 (`<div class="container" style="max-width: 1200px...`) after `?>` at line 25. Forms post to add_to_cart.php (hidden product_id, quantity=1, buttons name=action value=add|buy_now; both disabled spans "HẾT HÀNG" when stock<=0). `$outOfStock` computed inline in template (line 57).
- No exit/redirect, no inline functions. Output unescaped (`<?= $cat['name'] ?>`, `$p['name']`) - XSS exists but keep verbatim for byte-identical.
- Template vars needed: `$search`(unused in markup), `$cat_id`, `$categories`, `$products`.

**public/product.php (167 lines)**
- Bootstrap: `session_start()` (unconditional, line 2) -> bootstrap -> `include header.php` (line 4; header's session_start is then a no-op).
- Guard: none. Reads `$_SESSION['user_id']` only in template (line 108) to choose review form vs login link.
- Input: `$_GET['id']` (default 0, NOT cast to int; goes to prepared stmt). Queries: products by id; `product_sizes WHERE product_id=?`; reviews `SELECT r.*, u.username FROM reviews r JOIN users u ON r.id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC` (**bug: joins r.id = u.id instead of r.user_id = u.id** - behaviour must be preserved/ flagged separately).
- Not-found branch (line 14-18, runs AFTER header output): `echo "<div class='container'><h3>Sản phẩm không tồn tại!</h3></div>"; include footer.php; exit;` (HTTP 200, no 404 code).
- HTML begins line 31 (`<div class="container" style="max-width: 1200px; margin: 40px auto...`). Contains inline `<style>` block (156-166) after the container, before footer. Forms: add_to_cart.php (product_id, selected_size radio or hidden "Freesize", quantity, action add|buy_now; disabled if out of stock), submit_review.php (product_id, rating, comment) when `$_SESSION['user_id']` set.
- Note: product page posts `selected_size` but add_to_cart.php never reads it.
- Template vars: `$product_id,$product,$sizes,$reviews`, `$outOfStock` (computed in template, line 45).
- Ends with footer include, no trailing newline.

### Cart

**public/add_to_cart.php (57 lines, file ends `}` with no `?>`)**
- Bootstrap: `if (session_status()===PHP_SESSION_NONE) session_start();` -> bootstrap. No header include (pure action page).
- Guard (BEFORE any output, line 7-10): not logged in -> `echo "<script>alert('Vui lòng đăng nhập để mua hàng!'); window.location.href='login.php';</script>"; exit();` (JS alert, not Location header; note admin session has user_id=0 which isset() => passes).
- Only handles `REQUEST_METHOD == 'POST'`; a GET falls off the end and returns empty 200 page.
- Input: `$_POST['product_id']` cast int (undefined index if missing -> warning), `quantity` (int, default 1; no >0 check), `action` default 'add'.
- Logic: `SELECT stock FROM products WHERE id=?`; if `(int)stock <= 0`: `echo "<script>alert('Sản phẩm đã hết hàng!'); window.location.href='product.php?id=$product_id';</script>"; exit();`. Then try: SELECT cart row (user_id, product_id) -> UPDATE cart SET quantity = old+qty (no stock cap, no size) or INSERT INTO cart (user_id, product_id, quantity) (size column left to default 'S'). selected_size ignored.
- Redirect: lines 43-47 `header("Location: checkout.php")` if `$action==='buy_now'` else `header("Location: cart.php")`; then lines 48-52 duplicate identical if/else (dead redundancy, same target; header() replaces), then `exit();`. Catch PDOException -> `die("Lỗi hệ thống: " . $e->getMessage())`.
- No inline functions. No HTML.

**public/cart.php (112 lines)**
- Bootstrap order: bootstrap FIRST, then `if (session_status()===PHP_SESSION_NONE) session_start();` (lines 2-6). header.php is included at line 34 AFTER all logic (so redirects are before output).
- Guard (line 8-13, before output): `$user_id = $_SESSION['user_id'] ?? null; if (!$user_id) { header("Location: login.php?error=vui_long_dang_nhap"); exit(); }` (note `!$user_id` so admin user_id=0 is also bounced).
- Remove (GET): `if (isset($_GET['remove']))` -> `(int)` -> `DELETE FROM cart WHERE id=? AND user_id=?` -> `header("Location: cart.php"); exit();`.
- List: `SELECT c.id as cart_id, p.id as product_id, p.name, p.price, p.image, c.quantity, c.size, (p.price*c.quantity) as subtotal FROM cart c JOIN products p ... WHERE c.user_id=?` (no ORDER BY).
- Then `include header.php` (line 34, trailing space after `;` + `?>`), HTML starts line 37 (`<div class="container" style="max-width: 1100px; margin: 20px auto; ...`). Template computes `$total` inside loop (line 55-58, `$total += $item['subtotal']`) - template logic that must move with it or into handler. Form posts to checkout.php (`selected_items[]` checkboxes - checkout ignores them!). Inline `<script>` function `updateCart(cartId,value,type)` -> `window.location.href = update_cart.php?id=..&value=..&type=..`. Size select hard-codes S/M/L/XL/Freesize (compares `$item['size']=='Freesize'`; CartService::add uppercases to 'FREESIZE', mismatch).
- No exits after HTML. Footer include last.

**public/update_cart.php (19 lines, ends `exit();` no `?>`)**
- `session_start()` unconditional -> bootstrap. No guard redirect; silently ignores if not logged in (just redirects to cart.php).
- Input GET `id`, `value`, `type`; if `$cart_id && $value && $user_id`: type=='qty' -> `UPDATE cart SET quantity=? WHERE id=? AND user_id=?` else `UPDATE cart SET size=? ...` (no int cast, no stock check, no size whitelist, qty not validated >0; GET mutation / CSRF-able).
- Always `header("Location: cart.php"); exit();`.

### Checkout

**public/checkout.php (71 lines)**
- Order: bootstrap -> `include header.php` (line 3; HTML output already started) -> guard -> query.
- Guard (AFTER header output): `if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }` => because header.php already printed `<!DOCTYPE...` the `header()` call raises "headers already sent" warning/ fails unless output_buffering is on. (Check php.ini output_buffering; Docker php image default is 0 -> redirect effectively broken for guests: page shows header + warning.) Keep same order unless fixing; flag to caller. Same pattern: redirect-after-output.
- Query: `SELECT p.name, p.price, c.quantity, (p.price*c.quantity) as subtotal FROM cart c JOIN products p ON ... WHERE c.user_id=?` (ignores selected_items[] from cart form).
- Empty cart branch (line 21-25, after header): `echo "<div class='container' style='margin-top:50px;'>Giỏ hàng của bạn đang trống. <a href='index.php'>Quay lại mua sắm</a></div>"; include footer.php; exit();`.
- `$total_money` loop line 27-28 (`foreach($cart_items as $item) $total_money += $item['subtotal'];`).
- HTML begins line 31 (`<div class="container" style="max-width: 1200px; margin: 40px auto; display: flex; gap: 30px; ...`). Form -> process_checkout.php (full_name, phone, address, note). Footer include last.

**public/process_checkout.php (67 lines, LF, ends `}\n`)**
- `session_start()` unconditional -> bootstrap. No header include.
- Guard (before output): `if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php"); exit(); }`.
- Input: `$_POST['full_name']`, `['phone']`, `['address']` (read raw, NO isset/validation, and never stored anywhere!), `['note'] ?? ''` (unused). See Part 2 for logic.
- Success output: `echo "<script>alert('Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.'); window.location.href='index.php';</script>";` (no exit after; script end of file).
- Failure: `catch (Exception $e) { $pdo->rollBack(); die("Lỗi đặt hàng: " . $e->getMessage()); }` (HTTP 200 plain text, shows raw exception message; PDOException is an Exception so DB errors are shown too).
- No HTML, no inline functions.

**public/order_details.php (59 lines, ends `</html>`)**
- `session_start()` unconditional -> bootstrap. Full standalone HTML doc (does NOT use header/footer partials).
- Guard (before output): `if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) { header("Location: login.php"); exit(); }`.
- `$order_id=(int)$_GET['id']`; `SELECT * FROM orders WHERE id=? AND user_id=?`; not found -> `die("Đơn hàng không tồn tại hoặc bạn không có quyền xem.")`.
- Items: `SELECT od.*, p.name, p.image FROM order_details od JOIN products p ... WHERE od.order_id=?` - **table `order_details` does not exist in database/schema.sql nor any migration** (real table is `order_items`) => PDOException "Base table or view not found" uncaught -> fatal/500 for every valid order. (OrderService::detail uses `order_items`.)
- HTML begins line 31 (`<!DOCTYPE html>` after `?>`+blank line). Status shown raw: `<strong><?= $order['status'] ?></strong>` (line 45), no mapping. Link back to profile.php.

### Account

**public/login.php (99 lines, LF)**
- `if (session_status()===PHP_SESSION_NONE) session_start();` -> bootstrap. header.php included at line 69 AFTER POST handling (so redirects before output). `$error=""`.
- POST (line 9): `$email = trim($_POST['email'])`, `$password = $_POST['password']` (no isset); only if both non-empty.
 1. Admin check: `getenv('ADMIN_EMAIL') ?: 'admin@gmail.com'` and `getenv('ADMIN_PASSWORD') ?: ''` (**env read inside public page**). If ADMIN_PASSWORD set: `$isAdmin = ($email===$adminEmail || $email==='admin') && $password===$adminPass` (plain compare); else DB: `SELECT * FROM users WHERE (email=? OR username=?) AND role='admin' LIMIT 1` + `password_verify`. If admin: session `user_id=0`, `username='Admin Shop'`, `role='admin'`, `admin_logged_in=true`; `header("Location: admin/index.php"); exit();`.
 2. Else customer: `SELECT * FROM users WHERE email=?`; `password_verify`; if `isset($user['status']) && $user['status']==0` -> `$error = "Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin!"`; else session `user_id,username,role='user'`, generates `api_token = bin2hex(random_bytes(32))`, `UPDATE users SET api_token=? WHERE id=?`, `$_SESSION['api_token']`, `header("Location: index.php"); exit();`. Wrong creds: `$error = "Tài khoản hoặc mật khẩu không chính xác!"`.
 - No session_regenerate_id. No CSRF token.
- `include header.php` (line 69), HTML begins line 72 (`<div class="auth-form" ...`). Shows `?register_success` banner "Đăng ký thành công! Mời bạn đăng nhập." and `$error` (unescaped).
- No inline functions.

**public/register.php (64 lines)**
- Order: bootstrap -> `include header.php` (line 3, output starts) -> `$error=""; $success="";` -> POST handling -> markup. So the `header("Location: login.php?register_success=1")` after successful insert happens AFTER header output (same headers-already-sent hazard as checkout; works only if output_buffering enabled).
- POST: `fullname,email,password,confirm_password` raw (no isset/trim/email validation). `password !== confirm` -> `$error = "Mật khẩu xác nhận không khớp!"`; email exists (`SELECT id FROM users WHERE email=?`, `rowCount()` > 0) -> `"Email này đã được đăng ký!"`; else `password_hash(PASSWORD_DEFAULT)`, `api_token=bin2hex(random_bytes(32))`, `INSERT INTO users (username,email,password,api_token)`; success -> `header("Location: login.php?register_success=1"); exit();`; failure -> `"Có lỗi xảy ra, vui lòng thử lại."`.
- HTML begins line 37 (`<div class="auth-form" style="max-width: 400px; ...`). Footer include last. `$success` unused.

**public/logout.php (22 lines, ends `?>`)**
- `session_start()`; `$_SESSION = array()`; if `ini_get("session.use_cookies")` clear cookie via `setcookie(session_name(), '', time()-42000, $params path/domain/secure/httponly)`; `session_destroy()`; `header("Location: index.php"); exit();`. No bootstrap/DB.

**public/profile.php (133 lines)**
- `session_start()` -> bootstrap; guard (before output): `!isset($_SESSION['user_id'])` -> `header("Location: login.php"); exit();`. header.php included at line 38 after logic (redirect ordering OK).
- Input: `$_GET['status']` default 'all'; if !== 'all' appends `AND status = ?` with the raw value; `ORDER BY created_at DESC`. Stats: `SELECT SUM(total_price) total_spent, COUNT(*) completed_count FROM orders WHERE user_id=? AND status='Đã hoàn thành'`.
- HTML begins line 41 (`<div class="container" style="max-width: 1100px; margin: 40px auto; ..."`). Template holds `$tabs` map (all, 'Chờ xử lý', 'Đang giao', 'Đã hoàn thành') and badge-colour mapping by exact status string (lines 59-69, 101-109), uses `$_SESSION['username']` (htmlspecialchars) directly. Row link `order_details.php?id=`.
- Footer last.

**public/submit_review.php (28 lines, ends `exit();`)**
- `session_start()` -> bootstrap. No header include.
- `if (POST && isset($_SESSION['user_id']))`: `product_id=$_POST['product_id']` (raw, not cast), `rating=$_POST['rating']`, `comment=trim($_POST['comment'])`; if `!empty($comment) && $rating>=1 && $rating<=5`: `INSERT INTO reviews (product_id,user_id,rating,comment)` then `header("Location: product.php?id=".$product_id."&msg=success"); exit();`. PDOException -> `die("Lỗi hệ thống: ...")`.
- Everything else (GET, not logged in, invalid): `header("Location: index.php"); exit();`. (product.php ignores `msg=success`.)

### Admin (public/admin/*)

All admin pages are standalone HTML (do NOT use storefront header/footer). Guards run BEFORE bootstrap and before any output. Note the inconsistency in guard + redirect targets:

| file | lines | session | guard condition | redirect on fail |
|---|---|---|---|---|
| admin/index.php | 79 | `session_start()` | `!isset($_SESSION['admin_logged_in']) && (!isset($_SESSION['role']) \|\| $_SESSION['role']!=='admin')` | `login.php` (admin login) |
| admin/manage_orders.php | 107 | `if(session_status()===NONE) session_start()` | same as index.php | `login.php` |
| admin/add_product.php | 64 | same | `!isset($_SESSION['role']) \|\| $_SESSION['role']!=='admin'` | `login.php` |
| admin/edit_product.php | 154 | same | same as add_product, one line | `../login.php` (STOREFRONT login) |
| admin/manage_products.php | 219 | same | same | `../login.php` |
| admin/manage_users.php | 152 | same (brace style multi-line) | same | `../login.php` |
| admin/login.php | 66 | `session_start()` | none | n/a |
| admin/logout.php | 14 | `session_start()` | none | `../index.php` |

(admin/logout.php sidebar link in manage_products/users is `../logout.php` = the STOREFRONT logout; index/orders sidebars use `logout.php` = admin logout.)

**admin/add_product.php (64 lines) - the exit fix**: lines 4-8:
```
// Same admin check as manage_products.php: stop before any upload or insert.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
```
then line 10 bootstrap. i.e. the guard now `exit()`s before bootstrap/upload/insert (introduced in commit 0efdcd4; already in tree, working-tree clean). Redirect target is `login.php` (admin login) - unlike manage_products which uses `../login.php`. POST (`isset($_POST['add_product'])`): reads name, price, `category_id` (int), `$_FILES['image']`; `$error !== UPLOAD_ERR_OK` -> `$msg_error = "Lỗi khi tải file lên: " . $error`; ext not in whitelist (jpg,jpeg,png,webp,gif; strict in_array) -> `"Định dạng file này không được hỗ trợ (Chỉ nhận jpg, png, webp, gif)."`; `uniqid("IMG-", true).'.'.$ext`, `move_uploaded_file(...assets/images/)`; INSERT INTO products (name, price, category_id, image, style_tags) using `StyleTag::toColumn((array)($_POST['style_tags'] ?? []))`; success -> `header("Location: manage_products.php"); exit();`; move failure -> `"Không thể di chuyển file vào thư mục images."`. HTML begins line 41 `<div style="padding:20px;">` (fragment: no <html>). Template uses `StyleTag::MAX_PER_PRODUCT/ALL/label`, `$currentTags = []` assigned inline, `htmlspecialchars($msg_error)`.

**admin/edit_product.php (154)**: guard line 3 (one line). bootstrap line 4. `$id=(int)$_GET['id']`; product select; `if (!$product) die("Sản phẩm không tồn tại!")`; loads categories + product_subcategories. POST `update_product`: name, price, `stock`(int), description, category_id, subcategory_id, `$_FILES['image']['name']` (unchecked index); new image: ext whitelist (non-strict in_array); unique name, move, unlink old image, `$image_to_save`; **if the extension is not allowed or move fails `$image_to_save` is undefined -> NULL written to image column** (existing bug; keep or fix separately). UPDATE products SET name,price,stock,description,category_id,subcategory_id,image,style_tags; success -> `header("Location: manage_products.php?msg=updated"); exit();`. HTML starts line 68 `<!DOCTYPE html>`; has inline `<style>` and `<script>` (syncSubcategories). 

**admin/index.php (79)**: guard, bootstrap, try { COUNT products/orders/users; `SUM(total_price) FROM orders WHERE status='Đã hoàn thành'` ?: 0 } catch PDOException -> `die("Lỗi kết nối dữ liệu: ...")`. HTML starts line 31 `<!DOCTYPE html>` after `?>`. `number_format($total_revenue, 0, ',', '.')`.

**admin/login.php (66, LF)**: `session_start()`; bootstrap; `isset($_POST['login'])`: username trim, password. (1) env `getenv('ADMIN_USER')`/`getenv('ADMIN_PASSWORD')` (**env read in page**), plain compare -> session `admin_logged_in=true, role='admin', username='Admin Shop'` (NO user_id) -> `header('Location: index.php'); exit();`. (2) DB `SELECT * FROM users WHERE (username=? OR email=?) AND role='admin' LIMIT 1` + `password_verify` in try/catch(Exception) -> session admin_logged_in, role, `user_id=(int)id`, username -> `header('Location: index.php'); exit();`. Else `$error = 'Sai tài khoản hoặc mật khẩu!'`. HTML starts line 49 `<!DOCTYPE html>` (error via htmlspecialchars).
- NOTE the storefront login.php admin path sets `user_id = 0` and uses ADMIN_EMAIL, admin/login.php uses ADMIN_USER and no user_id: two different admin auth paths. admin/manage_users.php reads `$_SESSION['user_id']` unguarded (Undefined index notice when env-admin logged in via admin/login.php).

**admin/logout.php (14)**: `session_start(); session_unset(); session_destroy(); header("Location: ../index.php"); exit();`.

**admin/manage_orders.php (107)**: guard (line 5), bootstrap (line 10). POST `update_status`: `order_id` (int), `status` = `$_POST['status']` (**no whitelist**, any string accepted) -> `UPDATE orders SET status=? WHERE id=?` -> `header("Location: manage_orders.php?msg=updated"); exit();`. List: `SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id=u.id ORDER BY o.id DESC` (inner join: orders with user_id NULL/deleted users are hidden). HTML starts line 28 `<!DOCTYPE html>`. `?msg=updated` is never displayed. Status mapping in template (see Part 2).

**admin/manage_products.php (219, LF)**: guard `../login.php`, bootstrap (line 10), categories + subcategories queries (lines 11-16, run before POST handling). Three handlers in order: (2) POST `add_product` (name, price, stock int, category, subcategory, description; whitelist ext non-strict; `$error===0`; messages "Lỗi khi tải file lên: ".$error / "Định dạng file này không được hỗ trợ (Chỉ nhận jpg, png, webp, gif)." / "Không thể di chuyển file vào thư mục images."; INSERT products(name,price,stock,description,category_id,subcategory_id,image,style_tags); `header("Location: manage_products.php?msg=added"); exit();`); (3) GET `delete_id` (GET-mutation, no CSRF): select image, unlink file, `DELETE FROM products WHERE id=?`, `header("Location: manage_products.php?msg=deleted"); exit();`; then `SELECT * FROM products ORDER BY id DESC`. `$msg_error` only defined on failure; template uses `isset($msg_error)`. HTML starts line 84 `<!DOCTYPE html>`; inline CSS + script. Duplicates add_product.php logic.

**admin/manage_users.php (152)**: guard `../login.php`, bootstrap. GET `action=lock|unlock&id=`: if `$id != $_SESSION['user_id']`: UPDATE users SET status=0/1; `$msg = locked|unlocked`; `header("Location: manage_users.php?msg=$msg"); exit();` (unknown action -> `$msg` undefined, redirect to `?msg=`; PDOException -> `die("Lỗi hệ thống: ...")`). GET `toggle_role=admin|user&id=` -> `UPDATE users SET role=?` (NO self-protection) -> `manage_users.php?msg=updated`. List: `SELECT id, username, email, role, status, created_at FROM users ORDER BY id DESC`. HTML starts line ~34 `<!DOCTYPE html>`. Messages (template): locked "Đã khóa tài khoản thành công.", unlocked "Đã mở khóa tài khoản thành công.", updated "Đã cập nhật quyền thành công.". Template references `$_SESSION['user_id']` directly.

### Inline functions
None defined server-side in any page. Only JS functions (`updateCart` in cart.php, `syncSubcategories` in manage_products.php/edit_product.php). Template-level logic that must move with markup: `$outOfStock` (index, product), `$total` (cart), `$total_money` (checkout), `$tabs` + `$bg/$cl` (profile), `$status_class` (manage_orders), `$currentTags` (add_product, manage_products, edit_product).

### Session / flash / CSRF
- No CSRF tokens anywhere on storefront/admin pages; no flash mechanism at all (only `?msg=` query flags, mostly unread) and JS `alert()` scripts in add_to_cart/process_checkout. "Exact alert texts":
  - add_to_cart: `Vui lòng đăng nhập để mua hàng!` (-> login.php), `Sản phẩm đã hết hàng!` (-> product.php?id=$product_id)
  - process_checkout: `Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.` (-> index.php)
- Output-before-redirect hazards (existing): checkout.php guard, register.php success redirect (header.php already echoed); preserve order or fix deliberately.

### Grouping summary (suggested handler classes)
- catalog: index (GET search/category), product (GET id; not-found HTML fragment)
- cart: add_to_cart (POST, alert-script responses + Location), cart (GET remove -> redirect; list), update_cart (GET mutation -> redirect)
- checkout: checkout (render), process_checkout (POST), order_details (render; die on not found)
- account: login (POST; two redirects), register (POST), logout, profile (GET status filter), submit_review (POST)
- admin: index, login, logout, manage_orders (POST update_status), manage_products (POST add, GET delete), add_product (POST, fragment HTML), edit_product (POST update), manage_users (GET lock/unlock/toggle_role)

---------------------------------------------------------------------------------
## PART 2 - checkout bug

### public/process_checkout.php (what customers actually use)
- Stock check: per cart item `SELECT stock FROM products WHERE id=?`; fails only if `stock <= 0` (`throw new Exception("Sản phẩm ID {$item['product_id']} đã hết hàng!")`). It does NOT compare cart quantity against stock => can order qty > stock.
- Stock decrement: **none** (no UPDATE products SET stock). Stock never decreases from storefront orders.
- Locking: none (no `FOR UPDATE`; transaction is opened but cart/product reads are plain).
- Transaction: `$pdo->beginTransaction()` ... `commit()`; catch Exception -> `rollBack()` + die.
- Status written: **`'pending'`** (literal in INSERT, `NOW()` for created_at). NOT 'Chờ xử lý'.
- N+1: cart rows fetched once, then per item: one `SELECT stock`, one `SELECT price` (total loop), one more `SELECT price` + one INSERT order_items (items loop) = 3 SELECT + 1 INSERT per item; `prepare()` re-run inside every loop iteration (no statement reuse).
- Size: **not handled** - cart query selects only `product_id, quantity` (size column ignored), order_items has no size column anyway.
- Cart selection: ignores `selected_items[]` from cart.php; always orders and clears the whole cart.
- Cart clearing: `DELETE FROM cart WHERE user_id=?` inside the transaction.
- Columns: `orders(user_id, total_price, status='pending', created_at=NOW())`; `order_items(order_id, product_id, quantity, price=current product price)`. `$full_name, $phone, $address, $note` are read and **discarded** (no columns exist: orders is `id,user_id,total_price,status varchar(50) DEFAULT 'Pending',created_at`; order_items `id,order_id,product_id,quantity,price`).
- Empty cart: not checked -> creates an order with total 0 and no items.
- Response: success `<script>alert('Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.'); window.location.href='index.php';</script>`; failure `die("Lỗi đặt hàng: " . $e->getMessage())`.
- Guard: not logged in or non-POST -> `Location: index.php`. Admin session (user_id=0) passes isset() and would create orders for user 0.

### public/add_to_cart.php vs CartService::add
- Page: only `stock > 0` check (qty vs stock not compared; repeated adds can exceed stock); no transaction; ignores `selected_size` (always default `'S'` via column default; on duplicate product row just bumps quantity); GET method returns blank; JS-alert error; unknown product -> stock 0 -> "Sản phẩm đã hết hàng!". Cart row key = (user_id, product_id) only.
- Service: see below; validates product exists, `stock >= qty`, merged `existing+qty <= stock`, size stored `strtoupper(trim(size))` default 'S' (so 'Freesize' -> 'FREESIZE'; and on merge it overwrites size), runs in a transaction only if none open.

### src/service/order/OrderService.php (final class; `__construct(private PDO $pdo)`)
Public methods:
- `status(?int $userId, array $arguments): array` - no user: `['requires_login'=>true,'message'=>'Bạn cần đăng nhập để kiểm tra đơn hàng.']`; with `order_id`>0: `['orders'=>[$order]]` or `['orders'=>[], 'message'=>'Không tìm thấy đơn hàng này trong tài khoản của bạn.']` (catches RuntimeException from detail); else list() result with orders sliced to 5.
- `list(int $userId, ?string $status = null): array` - `['orders'=>[{id:int,total_price:float,status,created_at}], 'stats'=>['total_spent'=>float,'completed_count'=>int], 'message'=>'Bạn chưa có đơn hàng nào.'|'Đã tải danh sách đơn hàng.']`; filters `status` unless null/''/'all'; ORDER BY created_at DESC; stats count only `status='Đã hoàn thành'`.
- `detail(int $userId, int $orderId): array` - `['order'=>{id,total_price,status,created_at,items:[{id,product_id,quantity,price,name,image}]}]`; throws `RuntimeException('Order not found')` if not owned. Uses `order_items`.
- `create(int $userId): array` - returns `['message'=>'Order placed successfully','order_id'=>int,'total_price'=>float(round 2)]`; throws `RuntimeException('Cart is empty')`, `RuntimeException('Insufficient stock for product <id>')` (when cart qty > stock), `RuntimeException('Stock changed while creating the order')` (decrement rowCount != 1), or any PDOException (rethrown after rollBack).
  - Transaction: `beginTransaction()` at start; commit at end; catch Throwable -> `rollBack()` if `inTransaction()` -> rethrow. (Note: begins a transaction unconditionally, so it can't be nested.)
  - Locking: ONE query `SELECT c.product_id, c.quantity, p.price, p.stock FROM cart c JOIN products p ... WHERE c.user_id=?` + ` FOR UPDATE` only when driver is `mysql` (SQLite tests skip it). Locks cart AND product rows.
  - Stock check: `quantity > stock` -> exception. Decrement: `UPDATE products SET stock = stock - ? WHERE id=? AND stock >= ?` and requires `rowCount() === 1`.
  - Status written: **`'Chờ xử lý'`**, `created_at = CURRENT_TIMESTAMP`. Total computed in PHP from locked rows (float). order_items: `(order_id, product_id, quantity, price)` with the locked price; statements prepared once, reused. Cart cleared `DELETE FROM cart WHERE user_id=?` before commit. No size, no shipping name/phone/address either.
  - Bug-level notes: if the same product appears twice in cart there is only one row per (user, product) in practice, so fine. The decrement `WHERE stock >= ?` makes oversell impossible even if FOR UPDATE missing.
  - No N+1.
- private `castOrder(array): array`.

### src/service/cart/CartService.php (final class; `__construct(private PDO $pdo)`)
- `list(int $userId): array` -> `['cart'=>[{cart_id:int,product_id:int,name,price:float,image,quantity:int,size,subtotal:float}], 'total'=>float(round 2)]` ordered by c.id.
- `add(int $userId, array $arguments): array` args `product_id, quantity (default 1), size (default 'S')` -> `['message'=>'Added to cart','cart_id'=>int] + list()`. Throws `InvalidArgumentException('product_id and a positive quantity are required')`, `RuntimeException('Product not found')`, `RuntimeException('Insufficient product stock')` (qty > stock or merged qty > stock).
- `update(int $userId, array $arguments): array` args `cart_id` (required), optional `quantity`, optional `size` -> `['message'=>'Cart updated'] + list()`. Throws `InvalidArgumentException('cart_id is required' | 'quantity must be positive' | 'size cannot be empty')`, `RuntimeException('Cart item not found')`, `RuntimeException('Insufficient product stock')`. (Stock read via string-concatenated `(int)` query.)
- `remove(int $userId, int $cartId): array` -> `['message'=>'Removed from cart'] + list()`; `RuntimeException('Cart item not found')` when rowCount 0.

### REST controllers
- `src/app/api/controllers/Orders/create.php`: `authenticate()`; `jsonResponse((new OrderService($pdo))->create($userId), 201)`; `RuntimeException` -> `errorResponse(msg, str_contains(msg,'empty') ? 400 : 409)`. (PDOException not caught -> 500 via router.) No input beyond auth.
- `Orders/list.php`: `?status=` default 'all' -> `OrderService->list`. `Orders/detail.php`: RuntimeException -> 404.
- `Cart/add.php`: `getJsonInput()`; `jsonResponse(CartService->add(...), 201)`; `InvalidArgumentException` -> 400; `RuntimeException` -> 404 if message contains 'not found' else 409. `Cart/update.php` (id from route `$route_params['id']`, 400 'Cart item ID required'), `Cart/remove.php` (RuntimeException -> 404), `Cart/list.php`.
- ToolApplicationService (MCP/in-process) maps `list_cart/add_to_cart/update_cart/remove_from_cart/list_orders/get_order_detail/create_order` to the same services (cart/checkout tools are gated by `ToolDefinitionCatalog`).

### Admin/status consumers - exact strings
- DB: schema default `orders.status varchar(50) DEFAULT 'Pending'`; seed rows use 'Đã hoàn thành' (ids 1,2) and 'Đang giao' (3,4). No migration normalises statuses.
- `admin/manage_orders.php`: displays raw `$order['status']`; badge class mapping `Chờ xử lý -> status-pending`, `Đang giao -> status-shipping`, `Đã hoàn thành -> status-completed` (else empty class; 'Đã hủy' and 'pending' get no colour); `<select name=status>` options: `Chờ xử lý`, `Đang giao`, `Đã hoàn thành`, `Đã hủy`; selected = exact string equality. POST accepts ANY string (no whitelist). No status filter in this page (lists all orders, JOIN users inner).
- `admin/index.php`: revenue = SUM(total_price) WHERE `status='Đã hoàn thành'`; counts all orders regardless of status.
- `controllers/Admin/orders.php`: lists ALL orders (`o.*, u.username` inner join), casts id/user_id/total_price, no status filter. `controllers/Admin/dashboard.php`: same revenue rule 'Đã hoàn thành'.
- `controllers/Admin/order_status.php`: whitelist `['Chờ xử lý','Đang giao','Đã hoàn thành','Đã hủy']` (400 "Invalid status. Allowed: ..."), `UPDATE orders SET status=? WHERE id=?`, `rowCount()===0` -> 404 'Order not found' (note: setting same status also gives rowCount 0 on MySQL -> false 404), success `{'message':'Order status updated'}`.
- `public/profile.php`: tabs `all`, `Chờ xử lý`, `Đang giao`, `Đã hoàn thành` (filter = exact `status = ?`); badges: Chờ xử lý yellow (#fff9db/#f59f00), Đang giao blue, Đã hoàn thành green, else grey (#eee/#777). Stats use 'Đã hoàn thành'. No tab for 'Đã hủy'.
- `public/order_details.php`: raw status text, no mapping; but page is broken anyway (order_details table).

**Does 'Chờ xử lý' display/filter correctly?** Yes for orders created via REST/MCP `OrderService::create` (status 'Chờ xử lý'). For orders created by the storefront `process_checkout.php` (status `'pending'`): profile.php shows the raw text "pending" with the grey badge, and they appear under "Tất cả đơn" only (NOT under the 'Chờ xử lý' tab); admin/manage_orders.php shows the raw "pending" with no badge colour and the `<select>` has no matching option selected (browser defaults to first option 'Chờ xử lý', so saving silently converts it); admin dashboard counts them in total_orders but never in revenue until 'Đã hoàn thành'. Fix target: process_checkout must call `OrderService::create` (status 'Chờ xử lý'); optionally a one-off data migration `UPDATE orders SET status='Chờ xử lý' WHERE status IN ('pending','Pending')` (dated migration file; also schema default 'Pending' is another stale value).

### Summary of divergence (spec §1 already records it)
Page = no qty-vs-stock check, no decrement, 'pending', N+1, no size, whole cart. Service = qty check, locked read, atomic decrement, 'Chờ xử lý', single read, statements reused. Behaviour change to make deliberately: process_checkout -> `OrderService::create($userId)`; map RuntimeException messages ('Cart is empty', 'Insufficient stock for product N', 'Stock changed while creating the order') to the old `die("Lỗi đặt hàng: ...")` or alert style. Also: wrap in try/catch for Throwable (service rethrows PDOException).
Also: add_to_cart -> `CartService::add($userId, ['product_id'=>..,'quantity'=>..,'size'=>$_POST['selected_size'] ?? 'S'])` fixes size ignoring, but note strtoupper('Freesize') = 'FREESIZE' vs cart.php `Freesize` option compare (would no longer be pre-selected) and cart.php uses S/M/L/XL/Freesize options; the service message 'Insufficient product stock' / 'Product not found' would replace the alert text 'Sản phẩm đã hết hàng!' - keep the alert text by mapping.

---------------------------------------------------------------------------------
## PART 3 - tests & smoke

### Tree
tests/bootstrap.php (defines ROOT_DIR, requires vendor/autoload.php and tests/support/SqliteShop.php; `declare(strict_types=1)`)

tests/support/
- SqliteShop.php: `final class SqliteShop` with `static pdo(): PDO` (sqlite::memory:, ERRMODE_EXCEPTION; creates tables `products(id,category_id,name,price,stock,description,image,style_tags)`, `product_sizes`, `size_guides`, `cart(id,user_id,product_id,quantity,size)`, `chat_session_memory`), `static product(PDO,id,categoryId,name,price,stock,tags,array $sizes=['S','M','L','XL']): void`, `static sizeGuides(PDO): void`. **No `orders`, `order_items`, `users`, `categories`, `reviews` tables** - new Order/Cart/Account service tests need extra DDL (add a `SqliteShop::orders($pdo)` or similar).
- llm_stub_router.php (LLM stub for integration)

tests/unit (mirrors src/): agent/{AgentFactoryTest, EnvSandbox, HarnessConfigTest}; app/api/ChatStreamProtocolTest; ArchitectureTest; core/database/{MigrationRunnerTest, ProductStyleTagsMigrationTest}; harness/{evidence/(EvidenceNormalizerImageUrlTest, ToolOutcomeEvidenceTest), intent/(ChatbotGuardrailTest, CheckoutDetectorTest, ConflictGateTest, FinalReviewMinorsTest, FreeShippingIntentTest, InputParserGateTest, OutfitIntentTest, PolicyIntentKeywordTest, TurnTierGateTest), llm/MiddlewareModelTest, middleware/(AnswerTemplateFallbackTest, CheckoutGuardrailTest, FreeShippingNudgeMiddlewareTest, FreeShippingNudgeTest, MiddlewareStackTest, TraceOutboxTest), planning/(ChatbotToolManifestTest, ManifestPlannerTest, OutfitPlanningTest), response/(OutfitResponseTest, RefusalEchoTest, ResponseGeneratorGroundingTest), tools/ToolRegistryDelegationTest}; llm_providers/DeepSeekProviderTest; model/StyleTagTest; repository/OutfitCatalogRepositoryTest; service/{catalog/ColorGuardTest, freeship/(FreeShippingGapAdvisorTest, FreeShippingServiceTest), outfit/(OutfitBuilderTest, OutfitServiceTest, OutfitSlotPaletteTest)}.
- **No tests exist for CartService, OrderService, ProductService, SizeService, any public/ page or src/app/web.**

tests/integration: AgentAssemblyTest, CatalogColorSearchTest, OutfitCatalogIntegrationTest. (Each does `getenv('DB_HOST')` and `markTestSkipped` if unset; connects with PDO mysql to DB_NAME/DB_USER/DB_PASS; NO seeding in the tests - they rely on `shop_test` having schema + migrations + the seed rows that live in `database/schema.sql` INSERTs, e.g. product ids 50/52/53/58.)

### phpunit.xml
bootstrap tests/bootstrap.php; cacheDirectory /tmp/shop_phpunit_cache; suites `Unit` (tests/unit) and `Integration` (tests/integration); env DB_HOST=127.0.0.1, DB_NAME=shop_test, DB_USER=root, DB_PASS='', APP_ENV=test; source include src.

### Integration seeding
- `scripts/dev/test-db.sh`: loads .env; `DROP DATABASE IF EXISTS shop_test; CREATE DATABASE shop_test CHARACTER SET utf8mb4` in the Compose `db` container (root pw MARIADB_ROOT_PASSWORD or root_pass); pipes `database/schema.sql` (fallback sql/shop_db.sql) with `CREATE DATABASE`/`USE` lines stripped via sed into `shop_test` (this file also contains the INSERT seed data: products, orders, order_items, users...); then runs `scripts/run_database_migrations.php` via `scripts/dev/php` on network shop_quan_ao_default (DB_HOST=db). No additional seed script. Migration `2026_10_15_product_style_tags.sql` curates style_tags.
- `scripts/dev/test-integration.sh`: sources .env, runs `phpunit --testsuite=Integration` with APP_ENV=test, CHATBOT_TOOL_TRANSPORT=internal, DB_HOST=db, DB_NAME=shop_test.

### scripts/dev/smoke.sh checks (BASE_URL default http://localhost; each is `check <status> <method> <path> [json]`, curl without cookies/sessions)
1. 200 GET /nginx-health
2. 200 GET /
3. 200 GET /product.php?id=50
4. 200 GET /login.php
5. 200 GET /register.php
6. 200 GET /admin/login.php
7. 200 GET /assets/css/style.css
8. 200 GET /assets/images/at_basic_01.jpg
9. 403 GET /sql/shop_db.sql
10. 404 GET /config/db.php
11. 404 GET /src/bootstrap.php
12. 404 GET /scripts/run_database_migrations.php
13. 404 GET /templates/partials/header.php
14. 200 GET /api/products?limit=1
15. 200 GET /api/products/50
16. 200 GET /api/categories
17. 200 GET /api/faq
18. 200 GET /api/size-guide?height=170&weight=65
19. 200 GET /api/outfit
20. 404 POST /api/internal/mcp {}
21. 200 POST /api/chatbot {"message":"áo thun dưới 300k"}
22. 200 POST /api/chatbot/stream (same body)
23. WebSocket Upgrade at /ws/chatbot must NOT return 101.
**No cookie-jar / login smoke exists.** None of: cart.php, profile.php, checkout.php, process_checkout.php, admin/index.php, admin/manage_*.php, order_details.php, update_cart.php are smoked (guarded pages would just redirect 302). No byte-diff harness. Suggest adding before refactor: `curl -s` snapshots (with/without cookie jar logging in via POST /login.php and /admin/login.php) of / , /?category=1, /?search=áo, /product.php?id=50, /cart.php, /checkout.php, /profile.php, /admin/*.php, and diff after refactor; and checks of 302 `Location` headers for guarded pages (e.g. 302 -> login.php for /cart.php, /profile.php, /admin/index.php -> login.php, /admin/manage_products.php -> ../login.php).

### ArchitectureTest.php (tests/unit/ArchitectureTest.php, 290 lines; `final class ArchitectureTest extends TestCase`)
Constants:
- `MAX_FUNCTION_LINES = 80`
- `DOMAIN = ['service','model','core']` (harness may always use these)
- `ALLOWED` = per-harness-component allowed other components (llm, turn, intent, planning, loop, evidence, tools, response, memory, middleware)
- `LIMITED` = [from][to] => allowed class names (middleware->turn: TurnContext/TurnResult; middleware->intent: CheckoutDetector; llm->middleware: Middleware/MiddlewareStack)
- `OUTSIDE = ['app','agent','llm_providers']`
- `private static ?array $components` cache (class => component).
Mapping dir -> layer: `components()` loads `vendor/composer/autoload_classmap.php`, keeps classes whose realpath is under `src/`, and maps each with `componentOfFile($file)` = first path segment under src/, except `harness/<x>` -> `<x>` (so src/repository/* -> 'repository', src/core/* -> 'core', src/app/* -> 'app', src/agent -> 'agent', src/service -> 'service', src/model -> 'model', src/harness/intent -> 'intent', ...). **It works from the classmap, so run `composer dump-autoload` after adding classes (e.g. src/app/web/*) or they will not be seen (classes missing in the map are silently ignored).**
Helpers (all private unless noted): `harnessFiles(): list<string>` (RecursiveDirectoryIterator over src/harness *.php, sorted, asserts non-empty), `referencedClasses(file): array<class,component>` (uses classNamesIn on stripped code, excludes self and unknown names), `static classNamesIn(string $code): list<string>` (regex `(?<![\w$]|\w\\|->|::)[A-Z]\w*\b`), `components()`, `componentOfFile(file)`, `strippedCode(file)` (token_get_all; **removes T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML**, replacing with same number of newlines), `functionLengths(file)`, `functionName(tokens,i)`, `bodyEndLine(tokens,i)`, `relative(file)` (path relative to ROOT_DIR).
Tests: testComponentMapResolvesHarnessAndOutsideClasses (anti-vacuity guard), testClassReferenceExtractionSeesGlobalNamesOnly, testHarnessNeverReferencesOutside, testHarnessReadsNoEnvironment (patterns `\bgetenv\s*\(`, `\$_ENV\b`, `\$_SERVER\b` on harness files only), testComponentDependenciesFollowTable, testNoHarnessFunctionOver80Lines.
Everything is harness-scoped today (`harnessFiles()` hard-codes src/harness). To extend:
- "no env reads outside agent/core": generalise `harnessFiles()` into `srcFiles(string $subdir = '')`/`allSrcFiles()` and add a test that loops all src/**/*.php, skips files whose `componentOfFile` is `agent` or `core`, scans `strippedCode` with the same 3 patterns. Because strippedCode strips string literals, `getenv('X')` is still caught (the function name is a T_STRING token); `$_SERVER['REQUEST_METHOD']` is also caught by the `\$_SERVER\b` pattern - the pattern would flag request-method reads and `$_SERVER` pass-through in app/api (controllers, ChatStreamProtocol, mcp.php); either exclude `app` (as an I/O boundary) or narrow the `$_SERVER` check to harness/service/repository/model. Also `FreeShippingPolicy::intEnv` (src/model) and `FreeShippingService` fallback need fixing first (see Part 4).
- "no SQL outside src/repository (except core/database/MigrationRunner, core/observability/LangfuseTraceOutbox)": needs a NEW scanner since `strippedCode` removes string literals (SQL lives in strings). Detect on token level instead: any `->prepare(`/`->query(`/`->exec(`/`->beginTransaction(`/`->lastInsertId(` calls (T_OBJECT_OPERATOR + T_STRING in [prepare,query,exec,...]) and/or `PDO` class references, or keep a raw-file regex `/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i` on T_CONSTANT_ENCAPSED_STRING/T_ENCAPSED_AND_WHITESPACE tokens only. Allowlist by relative path (`core/database/MigrationRunner.php`, `core/observability/LangfuseTraceOutbox.php`, and today's other offenders - see below) and layer `repository`. Note `core/observability/LangfuseOtlpTracePublisher.php` also runs SQL (selects/updates langfuse_trace_outbox) and would need to be allowed or moved, as would `core/bootstrap`/database connection (PDO construction).
- Current SQL (`->prepare|query|exec`) outside src/repository and src/app/api/controllers: src/core/database/MigrationRunner.php, src/core/observability/LangfuseTraceOutbox.php, src/core/observability/LangfuseOtlpTracePublisher.php, src/app/api/middleware.php, src/service/{catalog/CatalogVariantBackfill, catalog/ProductService, catalog/CatalogVariantHydrator, order/OrderService, cart/CartService, size/SizeService, knowledge/KnowledgeRetriever, freeship/FreeShippingService (via PDO)}, src/harness/{middleware/FreeShippingNudge, memory/ChatbotMemory, memory/PdoChatbotConversationStore, memory/ChatbotSessionContext}, plus PDO-using files in src/agent (2) and src/harness/{intent,tools}. Also 9+ controllers in src/app/api/controllers/{Admin,Auth,Categories,Chatbot,Faq,Internal,Outfit,Products,SizeGuide}. Only one repository exists today: src/repository/OutfitCatalogRepository.php. So that rule can only be turned on as a ratchet/allowlist, or after the repository layer is built.

---------------------------------------------------------------------------------
## PART 4 - env reads and misc

### `getenv | $_ENV | $_SERVER[` outside src/agent and src/core (src/service, src/repository, src/harness, src/app, plus src/model, public/)
src/harness: NONE in code (only mention in src/harness/README.md:45 text). src/repository: NONE.
src/service:
- src/service/knowledge/KnowledgeRetriever.php:30 `getenv('QDRANT_URL')`, :32 `RAG_ML_URL`, :34 `KNOWLEDGE_COLLECTION`, :36 `EMBEDDING_PROVIDER`, :38 `EMBEDDING_MODEL`, :40 `KNOWLEDGE_HYBRID_CANDIDATES`, :69 `KNOWLEDGE_VERSION` (default 'v1'), :352 `KNOWLEDGE_RERANKER_MODEL` (default 'itdainb/PhoRanker'), :353 `KNOWLEDGE_VERSION`, :446 `getenv($key)` (dynamic helper)
- src/service/catalog/ProductReranker.php:114 `getenv('RERANKER_URL')`
src/model:
- src/model/FreeShippingPolicy.php:23 `getenv($key)`, :25 `$_ENV[$key] ?? $_SERVER[$key]` (inside `fromEnvironment()`/`intEnv()`; keys FREE_SHIPPING_THRESHOLD, FREESHIP_NUDGE_MAX_GAP)
src/app:
- src/app/api/support/ChatStreamProtocol.php:43 `getenv('CHATBOT_ALLOWED_ORIGINS')`, :48 `getenv('CHATBOT_MAX_MESSAGE_CHARS')` (default 2000)
- src/app/api/controllers/Internal/mcp.php:6 `getenv('MCP_SERVICE_TOKEN')`, :7 `$_SERVER['HTTP_X_MCP_SERVICE_TOKEN']`
- src/app/api/controllers/Chatbot/index.php:15 `$_SERVER['REQUEST_METHOD']`; Chatbot/stream.php:13 `$_SERVER['REQUEST_METHOD']`; public/api/index.php:16-17 `REQUEST_METHOD`, `REQUEST_URI` (not src)
(grep restricted to `getenv|$_ENV|$_SERVER[`; `$_SERVER` passed whole as arg in `ChatStreamProtocol::rejectBrowserRequest($message, $_SERVER)` index.php:25/stream.php:18 is not matched by `$_SERVER[`.)
public/ (not in requested dirs, but relevant): public/login.php:16-17 `ADMIN_EMAIL`, `ADMIN_PASSWORD`; public/admin/login.php:16-17 `ADMIN_USER`, `ADMIN_PASSWORD`; REQUEST_METHOD reads in register/login/add_to_cart/process_checkout/submit_review.

### FreeShippingPolicy::fromEnvironment callers
- src/agent/HarnessConfig.php:61 (the correct one: `freeShipping: FreeShippingPolicy::fromEnvironment()`).
- src/service/freeship/FreeShippingService.php:13 (`$policy = $this->policy ?? FreeShippingPolicy::fromEnvironment();`) - service layer reads env through a model default: violation of "no env outside agent/core".
- scripts/run_agent_eval.php:20.
- tests: tests/unit/service/freeship/FreeShippingGapAdvisorTest.php:73, tests/unit/harness/intent/FinalReviewMinorsTest.php:32,47, tests/integration/AgentAssemblyTest.php:133, tests/unit/agent/HarnessConfigTest.php (via HarnessConfig::fromEnvironment).
- Other FreeShippingPolicy users get it injected: FreeShippingNudge (harness/middleware, default `new FreeShippingPolicy()` param), ResponseGenerator (default `new FreeShippingPolicy()`), HarnessConfig default, eval/AgentEvalCaseRunner constructor.

### ToolApplicationService -> FreeShippingService
src/harness/tools/ToolApplicationService.php: `__construct(PDO $pdo, private ?int $userId)`; services created in constructor: ProductService($pdo,$userId), KnowledgeService($pdo), SizeService($pdo), CartService($pdo), OrderService($pdo). Line 29 (in `execute()` match): `'suggest_freeship_items' => (new FreeShippingService($this->pdo))->suggest($this->userId, $arguments)` and line 28 `'build_outfit' => (new OutfitService($this->pdo))->build($arguments)`. FreeShippingService is therefore built per call with NO policy => its `?FreeShippingPolicy $policy = null` falls back to `FreeShippingPolicy::fromEnvironment()` (env read in service). Note: it does not receive `HarnessConfig->freeShipping`, so a config override (AgentFactoryTest uses threshold 750000) won't reach the tool path; to fix, inject a policy via ToolApplicationService constructor (AgentFactory/ToolRegistry/ToolApplicationService callers pass `HarnessConfig->freeShipping`) and make FreeShippingService's `$policy` required (or default `new FreeShippingPolicy()` without env). The internal MCP endpoint (src/app/api/controllers/Internal/mcp.php) also constructs ToolApplicationService (check it for same wiring).

### Chatbot controllers: ChatbotSessionContext / LLMTransportException references
- src/app/api/controllers/Chatbot/index.php:29 `$context = ChatbotSessionContext::resolve($pdo, $sessionToken, getBearerToken());` then `new ChatbotService($pdo, $context->sessionId, $context->userId)`, `$context->touch($pdo)`, uses `$context->sessionToken`, `->sessionId`. No LLMTransportException reference.
- src/app/api/controllers/Chatbot/stream.php:33 `ChatbotSessionContext::resolve(...)` (inside try); stream.php:63 `$error instanceof LLMTransportException ? $error->category : 'runtime_failure'` (error log category in catch Throwable).
- Definitions: src/harness/memory/ChatbotSessionContext.php (final class; does SQL on chat sessions - listed as harness/memory in README), src/harness/llm/LLMTransportException.php (`final class ... extends RuntimeException`, public `$category`).
- So app/ depends directly on two harness-internal classes (not via agent facade): consider moving session resolution into ChatbotService/agent facade (`ChatbotService::resolveSession()`) and having the facade expose `errorCategory(Throwable): string`, per the layered design spec (docs/superpowers/specs/2026-10-22-layered-refactor-design.md, which already describes this refactor and the checkout fix in §8.4).
- Other references: LLMTransportException thrown by src/llm_providers/DeepSeekProvider.php, caught in src/harness/middleware/AnswerTemplateFallback.php:34; tests/unit/... multiple.
