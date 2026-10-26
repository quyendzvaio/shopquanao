<?php

/**
 * public/admin/manage_products.php: the product list, the add form's upload and the GET delete link. The upload
 * (non-strict extension check, server-made name, move_uploaded_file) and the unlink of a deleted product's image
 * stay here; the service only receives the final file name.
 */
final class AdminProductsPage
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

        $categories = $this->categories->idAndNames();
        $subcategories = $this->categories->withSubcategories();

        // 2. Add a product (several image formats allowed)
        if (isset($request->post['add_product'])) {
            $name = $request->post['name'];
            $price = $request->post['price'];
            $stock = (int)($request->post['stock'] ?? 0);
            $categoryId = (int)($request->post['category_id'] ?? 0);
            $subcategoryId = (int)($request->post['subcategory_id'] ?? 0);
            $description = $request->post['description'];

            $image_name = $request->files['image']['name'];
            $tmp_name = $request->files['image']['tmp_name'];
            $error = $request->files['image']['error'];

            if ($error === 0) {
                $img_ex = pathinfo($image_name, PATHINFO_EXTENSION); // the file extension
                $img_ex_lc = strtolower($img_ex); // lower case

                // Allowed formats
                $allowed_exs = array("jpg", "jpeg", "png", "webp", "gif");

                if (in_array($img_ex_lc, $allowed_exs)) {
                    // A new name avoids clashes and odd characters
                    $new_img_name = uniqid("IMG-", true) . '.' . $img_ex_lc;
                    $img_upload_path = $this->imagesDir . $new_img_name;

                    if (move_uploaded_file($tmp_name, $img_upload_path)) {
                        $this->products->create([
                            'name' => $name,
                            'price' => $price,
                            'stock' => $stock,
                            'description' => $description,
                            'category_id' => $categoryId > 0 ? $categoryId : null,
                            'subcategory_id' => $subcategoryId > 0 ? $subcategoryId : null,
                            'image' => $new_img_name,
                            'style_tags' => $request->post['style_tags'] ?? [],
                        ]);
                        return PageResult::redirect('manage_products.php?msg=added');
                    } else {
                        $msg_error = "Không thể di chuyển file vào thư mục images.";
                    }
                } else {
                    $msg_error = "Định dạng file này không được hỗ trợ (Chỉ nhận jpg, png, webp, gif).";
                }
            } else {
                $msg_error = "Lỗi khi tải file lên: " . $error;
            }
        }

        // 3. Delete a product
        if (isset($request->query['delete_id'])) {
            $id = (int)$request->query['delete_id'];
            $img_name = $this->products->imageOf($id);

            if ($img_name && file_exists($this->imagesDir . $img_name)) {
                unlink($this->imagesDir . $img_name);
            }

            $this->products->delete($id);
            return PageResult::redirect('manage_products.php?msg=deleted');
        }

        $data = [
            'categories' => $categories,
            'subcategories' => $subcategories,
            'products' => $this->products->allNewestFirst(),
            'imagesDir' => $this->imagesDir,
        ];
        if (isset($msg_error)) {
            $data['msg_error'] = $msg_error;
        }

        return PageResult::view($data);
    }
}
