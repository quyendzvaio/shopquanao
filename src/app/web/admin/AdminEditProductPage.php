<?php

/**
 * public/admin/edit_product.php: the edit form and its UPDATE. The upload (non-strict extension check,
 * server-made name, move_uploaded_file, unlink of the old image) stays here; the service only receives the final
 * file name. A new file that is rejected or cannot be moved leaves $image_to_save at NULL, so NULL is written to the
 * image column: the page has always done that and it is kept. A form without any `image` part counts as no new
 * image and keeps the old file name.
 */
final class AdminEditProductPage
{
    public function __construct(
        private ProductAdminService $products,
        private CategoryService $categories,
        private string $imagesDir
    ) {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session, false, '../login.php');
        if ($denied !== null) {
            return $denied;
        }

        $id = isset($request->query['id']) ? (int)$request->query['id'] : 0;

        // The product being edited
        $product = $this->products->find($id);

        if (!$product) {
            return PageResult::halt("Sản phẩm không tồn tại!");
        }
        $categories = $this->categories->idAndNames();
        $subcategories = $this->categories->withSubcategories();

        // Update
        if (isset($request->post['update_product'])) {
            $name = $request->post['name'];
            $price = $request->post['price'];
            $stock = (int)($request->post['stock'] ?? 0);
            $description = $request->post['description'];
            $categoryId = (int)($request->post['category_id'] ?? 0);
            $subcategoryId = (int)($request->post['subcategory_id'] ?? 0);
            $new_image = $request->files['image']['name'] ?? null; // no file part: keep the old image
            $image_to_save = null; // stays NULL when a new file is rejected or cannot be moved (the page's old behavior)

            if (!empty($new_image)) {
                // A new image was chosen
                $img_ex = strtolower(pathinfo($new_image, PATHINFO_EXTENSION));
                $allowed_exs = array("jpg", "jpeg", "png", "webp", "gif");

                if (in_array($img_ex, $allowed_exs)) {
                    $unique_name = uniqid("IMG-", true) . '.' . $img_ex;
                    $target = $this->imagesDir . $unique_name;

                    if (move_uploaded_file($request->files['image']['tmp_name'], $target)) {
                        // Remove the old image file
                        if (file_exists($this->imagesDir . $product['image'])) {
                            unlink($this->imagesDir . $product['image']);
                        }
                        $image_to_save = $unique_name;
                    }
                }
            } else {
                // No new image: keep the old file name
                $image_to_save = $product['image'];
            }

            if ($this->products->updateFromEditPage(
                $id,
                $name,
                $price,
                $stock,
                $description,
                $categoryId > 0 ? $categoryId : null,
                $subcategoryId > 0 ? $subcategoryId : null,
                $image_to_save,
                $request->post['style_tags'] ?? []
            )) {
                return PageResult::redirect('manage_products.php?msg=updated');
            }
        }

        return PageResult::view([
            'id' => $id,
            'product' => $product,
            'categories' => $categories,
            'subcategories' => $subcategories,
        ]);
    }
}
