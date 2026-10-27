<?php

/**
 * public/admin/add_product.php: the add-product form fragment and its upload (POST with a CSRF token). The guard runs first, so a guest
 * is redirected before any upload or insert. The upload (strict extension check, server-made name,
 * move_uploaded_file) stays here; the service only receives the final file name.
 */
final class AdminAddProductPage
{
    public function __construct(private ProductAdminService $products, private string $imagesDir)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session);
        if ($denied !== null) {
            return $denied;
        }

        $msg_error = '';
        $trusted = $request->method === 'POST' && Csrf::valid($session, $request->post[Csrf::FIELD] ?? null);
        if ($trusted && isset($request->post['add_product'])) {
            $name = $request->post['name'];
            $price = $request->post['price'];
            $category_id = (int)($request->post['category_id'] ?? 0);
            $image_name = (string)($request->files['image']['name'] ?? '');
            $error = $request->files['image']['error'] ?? UPLOAD_ERR_NO_FILE;

            // Same whitelist + server-generated name as manage_products.php: the
            // client-supplied file name never reaches the web root.
            $img_ex_lc = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
            $allowed_exs = array("jpg", "jpeg", "png", "webp", "gif");
            if ($error !== UPLOAD_ERR_OK) {
                $msg_error = "Lỗi khi tải file lên: " . $error;
            } elseif (!in_array($img_ex_lc, $allowed_exs, true)) {
                $msg_error = "Định dạng file này không được hỗ trợ (Chỉ nhận jpg, png, webp, gif).";
            } else {
                $new_img_name = uniqid("IMG-", true) . '.' . $img_ex_lc;
                // Move the image into assets/images/
                if (move_uploaded_file($request->files['image']['tmp_name'], $this->imagesDir . $new_img_name)) {
                    $this->products->createFromAddProductPage(
                        $name,
                        $price,
                        $category_id > 0 ? $category_id : null,
                        $new_img_name,
                        $request->post['style_tags'] ?? []
                    );
                    return PageResult::redirect('manage_products.php');
                }
                $msg_error = "Không thể di chuyển file vào thư mục images.";
            }
        }

        return PageResult::view(['msg_error' => $msg_error]);
    }
}
