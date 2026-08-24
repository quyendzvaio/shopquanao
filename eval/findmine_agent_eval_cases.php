<?php

/** 70 questions covering both styling use cases and negative controls. */
$questionGroups = [
    'explicit' => [
        'Sản phẩm mã 50 phối với gì?', 'Gợi ý đồ mặc cùng sản phẩm 50.',
        'Tư vấn cho tôi một set với sản phẩm mã 50.', 'Món số 50 nên kết hợp cùng món nào?',
        'Tôi muốn phối đồ với sản phẩm 50.', 'Có phụ kiện nào hợp với sản phẩm mã 50 không?',
        'Chọn giúp tôi đồ đi kèm sản phẩm số 50.', 'Sản phẩm 50 mặc chung với gì thì đẹp?',
        'Đề xuất một outfit dựa trên sản phẩm mã 50.', 'Tìm sản phẩm bổ trợ cho món số 50.',
        'Phối một bộ hoàn chỉnh với sản phẩm 50 giúp tôi.', 'Món mã 50 hợp với kiểu quần áo nào?',
        'Cho tôi các lựa chọn phối cùng sản phẩm số 50.', 'Tôi cần gợi ý complete the look cho sản phẩm 50.',
        'Có thể tạo set đồ từ sản phẩm mã 50 không?', 'Hãy tư vấn đồ kết hợp cho sản phẩm số 50.',
        'Tìm vài món phù hợp để mặc cùng sản phẩm 50.', 'Sản phẩm mã 50 có thể phối thành outfit nào?',
        'Gợi ý cách kết hợp sản phẩm số 50 với đồ trong shop.',
        'Tôi thích sản phẩm 50, hãy chọn thêm đồ mặc cùng.',
    ],
    'proactive' => [
        'Cho tôi xem thêm sản phẩm này.', 'Mẫu này còn màu nào khác không?',
        'Tôi muốn biết thêm chi tiết về món vừa chọn.', 'Có size phù hợp cho tôi không?',
        'Sản phẩm này còn hàng chứ?', 'Giá hiện tại của sản phẩm là bao nhiêu?',
        'Tôi có thể đổi size nếu không vừa không?', 'Shop giao món này trong bao lâu?',
        'Chất liệu của sản phẩm này là gì?', 'Cho tôi xem thông tin sản phẩm.',
        'Mẫu vừa thêm có đang giảm giá không?', 'Sản phẩm này có những size nào?',
        'Tôi muốn xem lại món vừa chọn.', 'Món này phù hợp mặc đi làm không?',
        'Có thể cho tôi biết tình trạng tồn kho không?', 'Tôi cần tư vấn size cho món này.',
        'Sản phẩm vừa thêm có bảo hành không?', 'Cho tôi hỏi chính sách đổi món này.',
        'Mẫu này có giao ngoại tỉnh không?', 'Tôi muốn hỏi thêm về sản phẩm vừa thêm.',
    ],
    'suppression' => [
        'Chính sách đổi trả thế nào?', 'Shop cho đổi hàng trong bao lâu?',
        'Điều kiện trả sản phẩm là gì?', 'Hàng sale có được đổi trả không?',
        'Nếu chọn nhầm size thì đổi thế nào?', 'Ai chịu phí vận chuyển khi đổi hàng?',
        'Tôi cần cung cấp gì để đổi trả?', 'Sản phẩm lỗi có đổi được không?',
        'Shop xử lý yêu cầu trả hàng mất bao lâu?', 'Tôi muốn hỏi quy định đổi size.',
        'Có thể hoàn lại món không vừa không?', 'Đổi màu sản phẩm cần điều kiện gì?',
        'Quá trình đổi trả của shop ra sao?', 'Tôi nhận sai mẫu thì cần làm gì?',
        'Chính sách hoàn hàng của shop là gì?',
    ],
    'unrelated' => [
        'Áo thun dưới 300k.', 'Tìm áo khoác dưới 600k.', 'Shop có quần jean màu xanh không?',
        'Cho tôi xem váy còn hàng.', 'Tìm kính mát giá dưới 300k.',
        'Có túi xách nào khoảng 500k không?', 'Tôi muốn mua áo sơ mi trắng.',
        'Tìm quần kaki size M.', 'Shop còn áo hoodie không?',
        'Cho tôi xem sản phẩm đang giảm giá.', 'Có phụ kiện nào dưới 200k?',
        'Tìm váy công sở màu đen.', 'Tôi cần áo thể thao còn hàng.',
        'Cho tôi các mẫu quần short.', 'Shop có áo blazer nữ không?',
    ],
];

$useCases = [
    'explicit' => 'UC1_EXPLICIT_STYLING',
    'proactive' => 'UC2_PROACTIVE_AFTER_CART',
    'suppression' => 'UC2_SUPPRESSION',
    'unrelated' => 'EXISTING_PRODUCT_SEARCH',
];
$expected = [
    'explicit' => ['expected_intent' => 'suggest_complementary_products'],
    'proactive' => ['expected_action' => 'pending_turn'],
    'suppression' => ['expected_intent' => 'return_exchange'],
    'unrelated' => ['expected_intent' => 'product_search'],
];
$cases = [];
foreach ($questionGroups as $class => $questions) {
    foreach ($questions as $index => $message) {
        $cases[] = [
            'id' => sprintf('%s-%02d', $class, $index + 1),
            'class' => $class,
            'use_case' => $useCases[$class],
            'message' => $message,
        ] + $expected[$class];
    }
}
return $cases;
