<?php

/** public/submit_review.php: posts a product review for the signed-in user, then redirects (always a final result). */
final class SubmitReviewAction
{
    public function __construct(private ReviewService $reviews)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        if ($request->method == 'POST' && isset($session['user_id'])) {
            $user_id = $session['user_id'];
            $product_id = $request->post['product_id'];
            $rating = $request->post['rating'];
            $comment = trim($request->post['comment']);

            if (!empty($comment) && $rating >= 1 && $rating <= 5) {
                try {
                    $this->reviews->addFromReviewForm($product_id, $user_id, $rating, $comment);

                    return PageResult::redirect("product.php?id=" . $product_id . "&msg=success");
                } catch (PDOException $e) {
                    return PageResult::halt("Lỗi hệ thống: " . $e->getMessage());
                }
            }
        }

        // Not signed in, wrong method or invalid input: back to the home page
        return PageResult::redirect('index.php');
    }
}
