-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 21, 2025 at 06:16 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `shop_db`;
USE `shop_db`;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `shop_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `size` varchar(10) DEFAULT 'S',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`) VALUES
(1, 'Áo'),
(2, 'Quần'),
(3, 'Váy & Đầm'),
(4, 'Phụ kiện');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `total_price`, `status`, `created_at`) VALUES
(1, 3, 485000.00, 'Đã hoàn thành', '2025-12-20 18:33:36'),
(2, 3, 180000.00, 'Đã hoàn thành', '2025-12-20 19:06:28'),
(3, 3, 550000.00, 'Đang giao', '2025-12-21 13:20:51'),
(4, 3, 630000.00, 'Đang giao', '2025-12-21 17:11:55');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `price`) VALUES
(1, 1, 64, 1, 485000.00),
(2, 2, 50, 1, 180000.00),
(3, 3, 52, 1, 550000.00),
(4, 4, 50, 1, 180000.00),
(5, 4, 90, 1, 450000.00);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `name`, `price`, `stock`, `description`, `image`) VALUES
(50, 1, 'Áo Thun Cotton Basic Trắng', 180000.00, 0, 'Chất liệu cotton 100% thoáng mát, thấm hút mồ hôi cực tốt.', 'at_basic_01.jpg'),
(51, 1, 'Áo Sơ Mi Linen Tay Ngắn Xanh', 320000.00, 5, 'Vải linen tự nhiên, phong cách trẻ trung và thanh lịch.', 'asm_linen_02.jpg'),
(52, 1, 'Áo Khoác Bomber Kaki Đen', 550000.00, 12, 'Thiết kế mạnh mẽ, lớp lót trong mềm mại, giữ ấm tốt.', 'ak_bomber_03.jpg'),
(53, 1, 'Áo Len Cổ Tròn Xám', 415000.00, 8, 'Sợi len dệt cao cấp, không xù lông, giữ form lâu dài.', 'al_co_tron_04.jpg'),
(54, 1, 'Áo Polo Thể Thao Cao Cấp Đỏ', 290000.00, 3, 'Form dáng slim-fit, cổ đức chỉn chu, phù hợp mọi hoàn cảnh.', 'ap_the_thao_05.jpg'),
(55, 1, 'Áo Hoodie Dây Rút Màu Kem', 480000.00, 0, 'Phong cách streetstyle năng động với nỉ bông dày dặn.', 'ah_day_rut_06.jpg'),
(56, 1, 'Áo Gile Lông Cừu Nâu', 620000.00, 7, 'Chất liệu lông cừu nhân tạo sang trọng và ấm áp.', 'ag_long_cuu_07.jpg'),
(57, 1, 'Áo Vest Blazer Công Sở Xanh Navy', 890000.00, 2, 'Đường may tinh tế, tôn dáng cho các quý ông công sở.', 'av_blazer_08.jpg'),
(58, 1, 'Áo Thun Graphic Phối Màu', 210000.00, 15, 'Họa tiết in nhiệt sắc nét, bền màu sau nhiều lần giặt.', 'at_graphic_09.jpg'),
(59, 1, 'Áo Khoác Da Lót Lông', 1250000.00, 4, 'Da PU cao cấp chống nổ, lót lông dày siêu ấm.', 'ak_da_10.jpg'),
(60, 1, 'Áo Phông Form Rộng Màu Be', 195000.00, 0, 'Kiểu dáng Oversize cực kỳ thoải mái cho các hoạt động.', 'ap_form_rong_11.jpg'),
(61, 1, 'Áo Dài Tay Cổ Tim', 250000.00, 10, 'Thiết kế cổ tim tinh tế, vải thun co giãn 4 chiều.', 'adt_co_tim_12.jpg'),
(62, 1, 'Áo Khoác Nỉ Có Mũ (Hoodie Zipper)', 510000.00, 6, 'Khóa kéo chắc chắn, túi sâu tiện lợi mang đồ cá nhân.', 'ak_hoodie_13.jpg'),
(63, 1, 'Áo Sơ Mi Caro Đỏ Đen', 350000.00, 1, 'Họa tiết caro Flanel cổ điển, vải mềm và dày dặn.', 'asm_caro_14.jpg'),
(64, 1, 'Áo Len Cao Cổ Màu Đất', 485000.00, 9, 'Cổ cao giữ ấm tuyệt đối cho mùa đông.', 'al_cao_co_15.jpg'),
(65, 2, 'Quần Jeans Slimfit Xanh Đậm', 690000.00, 0, 'Dáng ôm vừa vặn, màu xanh denim truyền thống dễ phối đồ.', 'qj_slim_01.jpg'),
(66, 2, 'Quần Tây Ống Đứng Xám', 540000.00, 11, 'Chất vải tuyết mưa không nhăn, chuẩn phom công sở.', 'qt_dung_02.jpg'),
(67, 2, 'Quần Short Kaki Lưng Chun', 310000.00, 20, 'Lưng thun thoải mái, phù hợp cho đi chơi dạo phố.', 'qs_kaki_03.jpg'),
(68, 2, 'Quần Jogger Thun Co Giãn', 450000.00, 3, 'Phom dáng thể thao năng động cho nam giới.', 'qj_thun_04.jpg'),
(69, 2, 'Quần Baggy Vải Tuyết Mưa', 490000.00, 0, 'Ống rộng vừa phải, che khuyết điểm chân rất tốt.', 'qb_tuyet_mua_05.jpg'),
(70, 2, 'Quần Lót Trong Vải Bò (Jeans)', 750000.00, 2, 'Thiết kế độc đáo, phong cách phá cách.', 'qj_lot_trong_06.jpg'),
(71, 2, 'Quần Bò Rách Gối Phong Cách', 720000.00, 8, 'Bụi bặm với các vết mài rách nghệ thuật.', 'qb_rach_goi_07.jpg'),
(72, 2, 'Quần Short Jean Cạp Cao', 350000.00, 14, 'Tôn dáng, giúp chân trông dài hơn cho các bạn nữ.', 'qs_jean_08.jpg'),
(73, 2, 'Quần Kaki Ống Rộng Màu Xanh Rêu', 560000.00, 5, 'Màu xanh rêu thời thượng, túi hộp tiện dụng.', 'qk_rong_09.jpg'),
(74, 2, 'Quần Ống Suông Thể Thao', 420000.00, 0, 'Vải thun lạnh, thoáng mát khi vận động mạnh.', 'qos_the_thao_10.jpg'),
(75, 3, 'Váy Maxi Voan Tay Phồng Hồng', 850000.00, 6, 'Vải voan tơ mềm mại, bay bổng cho những chuyến đi biển.', 'vm_voan_01.jpg'),
(76, 3, 'Chân Váy Chữ A Da Lộn', 390000.00, 1, 'Chất liệu da lộn bền bỉ, phong cách Vintage.', 'cv_chu_a_02.jpg'),
(77, 3, 'Váy Suông Thun Gân Đen', 450000.00, 13, 'Thiết kế basic, tôn dáng, che bụng cực tốt.', 'vs_thun_03.jpg'),
(78, 3, 'Váy Xòe Dáng Dài Vintage', 790000.00, 4, 'Họa tiết cổ điển, phù hợp cho các buổi tiệc trà.', 'vx_vintage_04.jpg'),
(79, 3, 'Chân Váy Jean Ngắn Tua Rua', 360000.00, 0, 'Phần gấu tua rua cá tính, chất jean dày dặn.', 'cv_jean_05.jpg'),
(80, 3, 'Váy Đầm Công Chúa Dự Tiệc', 1300000.00, 2, 'Nhiều lớp tùng váy xòe rộng, đính đá lấp lánh.', 'vd_cong_chua_06.jpg'),
(81, 3, 'Váy Cổ V Đắp Chéo Họa Tiết', 680000.00, 7, 'Cổ V quyến rũ, họa tiết hoa nhí nữ tính.', 'vc_hoa_tiet_07.jpg'),
(82, 3, 'Chân Váy Pleat xếp ly Midi', 470000.00, 9, 'Dập ly tinh tế, tạo độ xòe nhẹ khi bước đi.', 'cv_pleat_08.jpg'),
(83, 3, 'Váy Hai Dây Lụa Satin', 580000.00, 0, 'Chất lụa satin bóng nhẹ, mềm mịn như làn da.', 'vh_lua_09.jpg'),
(84, 3, 'Váy Đuôi Cá Dài Phối Ren', 920000.00, 3, 'Kiểu dáng đuôi cá sang trọng kết hợp ren cao cấp.', 'vdc_phoi_ren_10.jpg'),
(85, 3, 'Chân Váy Caro Dáng Ngắn', 320000.00, 11, 'Họa tiết caro năng động, trẻ trung cho nữ sinh.', 'cv_caro_11.jpg'),
(86, 3, 'Váy Cổ Trụ Phối Nút Màu Đỏ', 710000.00, 5, 'Thiết kế thanh lịch, màu đỏ nổi bật và may mắn.', 'vct_nut_12.jpg'),
(87, 3, 'Váy Babydoll Tay Bồng', 550000.00, 0, 'Form rộng thoải mái, tay phồng che bắp tay tốt.', 'v_babydoll_13.jpg'),
(88, 3, 'Chân Váy Chữ A Công Sở Vạt Xẻ', 430000.00, 8, 'Chi tiết vạt xẻ tinh tế, tạo điểm nhấn cho chân váy.', 'cv_cong_so_14.jpg'),
(89, 3, 'Váy Đầm Xòe Phối Lưới', 880000.00, 2, 'Lớp lưới bên ngoài tạo vẻ huyền bí và sang trọng.', 'vx_phoi_luoi_15.jpg'),
(90, 4, 'Túi Xách Mini Thời Trang', 450000.00, 15, 'Kích thước nhỏ gọn, chất liệu da nhân tạo cao cấp.', 'tx_mini_01.jpg'),
(91, 4, 'Mũ Lưỡi Trai Thêu Logo', 150000.00, 0, 'Form nón chuẩn, thêu logo sắc nét phía trước.', 'mlt_logo_02.jpg'),
(92, 4, 'Kính Mát Gọng Vuông', 290000.00, 6, 'Mắt kính chống tia UV400, bảo vệ mắt tuyệt đối.', 'km_vuong_03.jpg'),
(93, 4, 'Thắt Lưng Da Bò Nguyên Tấm', 520000.00, 4, 'Da bò thật bền bỉ theo thời gian, khóa kim loại cao cấp.', 'dl_da_bo_04.jpg'),
(94, 4, 'Khăn Choàng Lụa Họa Tiết', 380000.00, 0, 'Mềm mại, mỏng nhẹ, phụ kiện hoàn hảo cho phái nữ.', 'kc_lua_05.jpg'),
(95, 4, 'Vòng Cổ Bạc 925 Tinh Xảo', 650000.00, 2, 'Thiết kế đơn giản nhưng sang trọng, không xỉn màu.', 'vc_bac_06.jpg'),
(96, 4, 'Bông Tai Mạ Vàng Cao Cấp', 280000.00, 9, 'Điểm nhấn lấp lánh cho khuôn mặt thêm thu hút.', 'bt_ma_vang_07.jpg'),
(97, 4, 'Ví Da Cầm Tay Sang Trọng', 720000.00, 3, 'Nhiều ngăn chứa tiền và thẻ, da sần chống trầy.', 'vd_cam_tay_08.jpg'),
(98, 4, 'Đồng Hồ Dây Da Cổ Điển', 1150000.00, 1, 'Mặt kính khoáng chống xước, máy Nhật chạy ổn định.', 'dh_day_da_09.jpg'),
(99, 4, 'Cột Tóc Scrunchies Lụa', 45000.00, 20, 'Phụ kiện tóc xinh xắn, không làm gãy rụng tóc.', 'IMG-694812c8977dc7.69185583.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `product_sizes`
--

CREATE TABLE `product_sizes` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `size_name` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_sizes`
--

INSERT INTO `product_sizes` (`id`, `product_id`, `size_name`) VALUES
(1, 50, 'S'),
(2, 51, 'S'),
(3, 52, 'S'),
(4, 53, 'S'),
(5, 54, 'S'),
(6, 55, 'S'),
(7, 56, 'S'),
(8, 57, 'S'),
(9, 58, 'S'),
(10, 59, 'S'),
(11, 60, 'S'),
(12, 61, 'S'),
(13, 62, 'S'),
(14, 63, 'S'),
(15, 64, 'S'),
(16, 65, 'S'),
(17, 66, 'S'),
(18, 67, 'S'),
(19, 68, 'S'),
(20, 69, 'S'),
(21, 70, 'S'),
(22, 71, 'S'),
(23, 72, 'S'),
(24, 73, 'S'),
(25, 74, 'S'),
(26, 75, 'S'),
(27, 76, 'S'),
(28, 77, 'S'),
(29, 78, 'S'),
(30, 79, 'S'),
(31, 80, 'S'),
(32, 81, 'S'),
(33, 82, 'S'),
(34, 83, 'S'),
(35, 84, 'S'),
(36, 85, 'S'),
(37, 86, 'S'),
(38, 87, 'S'),
(39, 88, 'S'),
(40, 89, 'S'),
(51, 50, 'M'),
(52, 51, 'M'),
(53, 52, 'M'),
(54, 53, 'M'),
(55, 54, 'M'),
(56, 55, 'M'),
(57, 56, 'M'),
(58, 57, 'M'),
(59, 58, 'M'),
(60, 59, 'M'),
(61, 60, 'M'),
(62, 61, 'M'),
(63, 62, 'M'),
(64, 63, 'M'),
(65, 64, 'M'),
(66, 65, 'M'),
(67, 66, 'M'),
(68, 67, 'M'),
(69, 68, 'M'),
(70, 69, 'M'),
(71, 70, 'M'),
(72, 71, 'M'),
(73, 72, 'M'),
(74, 73, 'M'),
(75, 74, 'M'),
(76, 75, 'M'),
(77, 76, 'M'),
(78, 77, 'M'),
(79, 78, 'M'),
(80, 79, 'M'),
(81, 80, 'M'),
(82, 81, 'M'),
(83, 82, 'M'),
(84, 83, 'M'),
(85, 84, 'M'),
(86, 85, 'M'),
(87, 86, 'M'),
(88, 87, 'M'),
(89, 88, 'M'),
(90, 89, 'M'),
(101, 50, 'L'),
(102, 51, 'L'),
(103, 52, 'L'),
(104, 53, 'L'),
(105, 54, 'L'),
(106, 55, 'L'),
(107, 56, 'L'),
(108, 57, 'L'),
(109, 58, 'L'),
(110, 59, 'L'),
(111, 60, 'L'),
(112, 61, 'L'),
(113, 62, 'L'),
(114, 63, 'L'),
(115, 64, 'L'),
(116, 65, 'L'),
(117, 66, 'L'),
(118, 67, 'L'),
(119, 68, 'L'),
(120, 69, 'L'),
(121, 70, 'L'),
(122, 71, 'L'),
(123, 72, 'L'),
(124, 73, 'L'),
(125, 74, 'L'),
(126, 75, 'L'),
(127, 76, 'L'),
(128, 77, 'L'),
(129, 78, 'L'),
(130, 79, 'L'),
(131, 80, 'L'),
(132, 81, 'L'),
(133, 82, 'L'),
(134, 83, 'L'),
(135, 84, 'L'),
(136, 85, 'L'),
(137, 86, 'L'),
(138, 87, 'L'),
(139, 88, 'L'),
(140, 89, 'L'),
(151, 50, 'XL'),
(152, 51, 'XL'),
(153, 52, 'XL'),
(154, 53, 'XL'),
(155, 54, 'XL'),
(156, 55, 'XL'),
(157, 56, 'XL'),
(158, 57, 'XL'),
(159, 58, 'XL'),
(160, 59, 'XL'),
(161, 60, 'XL'),
(162, 61, 'XL'),
(163, 62, 'XL'),
(164, 63, 'XL'),
(165, 64, 'XL'),
(166, 65, 'XL'),
(167, 66, 'XL'),
(168, 67, 'XL'),
(169, 68, 'XL'),
(170, 69, 'XL'),
(171, 70, 'XL'),
(172, 71, 'XL'),
(173, 72, 'XL'),
(174, 73, 'XL'),
(175, 74, 'XL'),
(176, 75, 'XL'),
(177, 76, 'XL'),
(178, 77, 'XL'),
(179, 78, 'XL'),
(180, 79, 'XL'),
(181, 80, 'XL'),
(182, 81, 'XL'),
(183, 82, 'XL'),
(184, 83, 'XL'),
(185, 84, 'XL'),
(186, 85, 'XL'),
(187, 86, 'XL'),
(188, 87, 'XL'),
(189, 88, 'XL'),
(190, 89, 'XL');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `rating` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `api_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` varchar(20) DEFAULT 'user',
  `status` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `created_at`, `role`, `status`) VALUES
(3, 'tongvanhiep', 'hiep@gmail.com', '$2y$10$xSMmpMglcrGRjnuZESdvC.KhdKOIF4B4yRTCrnBXhYNhAXIWDEna6', '2025-12-20 18:33:05', 'user', 1);

-- --------------------------------------------------------

--
-- Table structure for table `faqs`
--

CREATE TABLE `faqs` (
  `id` int(11) NOT NULL,
  `question` text NOT NULL,
  `answer` text NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `priority` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `faqs` (`id`, `question`, `answer`, `category`, `priority`) VALUES
(1, 'Shop có giao hàng toàn quốc không?', 'Có, chúng tôi giao hàng trên toàn quốc. Thời gian giao hàng từ 2-5 ngày tùy khu vực. Nội thành Hà Nội và TP.HCM nhận hàng trong 24h.', 'shipping', 1),
(2, 'Phí ship tính thế nào?', 'Miễn phí giao hàng cho đơn từ 500,000đ. Đơn dưới 500,000đ phí ship là 30,000đ nội thành và 50,000đ ngoại tỉnh.', 'shipping', 2),
(3, 'Có thể đổi trả sản phẩm không?', 'Có, đổi trả trong vòng 7 ngày kể từ ngày nhận hàng. Sản phẩm phải còn nguyên tem mác, chưa qua sử dụng.', 'return', 1),
(4, 'Có bảo hành sản phẩm không?', 'Sản phẩm được bảo hành 30 ngày về lỗi đường may, lỗi vải. Riêng phụ kiện bảo hành 15 ngày.', 'warranty', 1),
(5, 'Thanh toán bằng những hình thức nào?', 'Chúng tôi chấp nhận: Thanh toán khi nhận hàng (COD), Chuyển khoản ngân hàng, Ví MoMo, VNPay qua thẻ ATM/Visa/Mastercard.', 'payment', 1),
(6, 'Làm sao để theo dõi đơn hàng?', 'Sau khi đặt hàng, bạn có thể kiểm tra trạng thái đơn hàng trong mục \"Đơn hàng của tôi\" ở trang cá nhân. Hoặc nhắn tin chatbot để được hỗ trợ.', 'order', 1),
(7, 'Cách chọn size như thế nào?', 'Bạn tham khảo bảng size chi tiết ở cuối trang sản phẩm. Hoặc cung cấp chiều cao, cân nặng để chatbot tư vấn size phù hợp.', 'size', 1),
(8, 'Shop có bán sỉ không?', 'Có, shop bán sỉ cho các đơn từ 10 sản phẩm trở lên. Vui lòng liên hệ hotline hoặc email để được báo giá sỉ.', 'wholesale', 2);

-- --------------------------------------------------------

--
-- Table structure for table `size_guides`
--

CREATE TABLE `size_guides` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `size_name` varchar(10) NOT NULL,
  `height_from` int(11) DEFAULT NULL,
  `height_to` int(11) DEFAULT NULL,
  `weight_from` int(11) DEFAULT NULL,
  `weight_to` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `size_guides` (`id`, `product_id`, `category_id`, `size_name`, `height_from`, `height_to`, `weight_from`, `weight_to`, `description`) VALUES
(1, NULL, 1, 'S', 155, 165, 45, 55, 'Áo size S: Cao 1m55-1m65, Nặng 45-55kg'),
(2, NULL, 1, 'M', 160, 170, 55, 65, 'Áo size M: Cao 1m60-1m70, Nặng 55-65kg'),
(3, NULL, 1, 'L', 165, 175, 60, 75, 'Áo size L: Cao 1m65-1m75, Nặng 60-75kg'),
(4, NULL, 1, 'XL', 170, 185, 70, 85, 'Áo size XL: Cao 1m70-1m85, Nặng 70-85kg'),
(5, NULL, 2, 'S', 155, 165, 45, 55, 'Quần size S: Cao 1m55-1m65, Nặng 45-55kg'),
(6, NULL, 2, 'M', 160, 170, 55, 65, 'Quần size M: Cao 1m60-1m70, Nặng 55-65kg'),
(7, NULL, 2, 'L', 165, 175, 60, 75, 'Quần size L: Cao 1m65-1m75, Nặng 60-75kg'),
(8, NULL, 2, 'XL', 170, 185, 70, 85, 'Quần size XL: Cao 1m70-1m85, Nặng 70-85kg'),
(9, NULL, 3, 'S', 150, 160, 40, 50, 'Váy size S: Cao 1m50-1m60, Nặng 40-50kg'),
(10, NULL, 3, 'M', 155, 165, 48, 58, 'Váy size M: Cao 1m55-1m65, Nặng 48-58kg'),
(11, NULL, 3, 'L', 160, 170, 55, 68, 'Váy size L: Cao 1m60-1m70, Nặng 55-68kg'),
(12, NULL, 3, 'XL', 165, 175, 62, 78, 'Váy size XL: Cao 1m65-1m75, Nặng 62-78kg');

-- --------------------------------------------------------

--
-- Table structure for table `outfit_suggestions`
--

CREATE TABLE `outfit_suggestions` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `paired_product_id` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `outfit_suggestions` (`id`, `product_id`, `paired_product_id`, `note`) VALUES
(1, 50, 65, 'Áo thun trắng + Quần jeans slimfit - Set cơ bản không thể thiếu'),
(2, 63, 65, 'Áo sơ mi caro + Quần jeans slimfit - Phong cách cá tính'),
(3, 54, 67, 'Áo polo đỏ + Quần short kaki - Năng động thể thao'),
(4, 59, 66, 'Áo khoác da + Quần tây ống đứng - Lịch lãm, bụi bặm'),
(5, 55, 73, 'Áo hoodie kem + Quần kaki ống rộng - Streetstyle chuẩn chất');

-- --------------------------------------------------------

--
-- Table structure for table `chat_sessions`
--

CREATE TABLE `chat_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `session_token` varchar(64) NOT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `message` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_session_memory`
--

CREATE TABLE `chat_session_memory` (
  `session_id` int(11) NOT NULL,
  `summary` text DEFAULT NULL,
  `slots` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_long_term_memory`
--

CREATE TABLE `user_long_term_memory` (
  `user_id` int(11) NOT NULL,
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `stable_facts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `important_events` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `feedback` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `purchase_history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `product_sizes`
--
ALTER TABLE `product_sizes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `faqs`
--
ALTER TABLE `faqs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `size_guides`
--
ALTER TABLE `size_guides`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `outfit_suggestions`
--
ALTER TABLE `outfit_suggestions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `paired_product_id` (`paired_product_id`);

--
-- Indexes for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `session_token` (`session_token`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `session_id` (`session_id`);

--
-- Indexes for table `chat_session_memory`
--
ALTER TABLE `chat_session_memory`
  ADD PRIMARY KEY (`session_id`);

--
-- Indexes for table `user_long_term_memory`
--
ALTER TABLE `user_long_term_memory`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=104;

--
-- AUTO_INCREMENT for table `product_sizes`
--
ALTER TABLE `product_sizes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=256;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faqs`
--
ALTER TABLE `faqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `size_guides`
--
ALTER TABLE `size_guides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `outfit_suggestions`
--
ALTER TABLE `outfit_suggestions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `product_sizes`
--
ALTER TABLE `product_sizes`
  ADD CONSTRAINT `product_sizes_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `size_guides`
--
ALTER TABLE `size_guides`
  ADD CONSTRAINT `size_guides_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `size_guides_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `outfit_suggestions`
--
ALTER TABLE `outfit_suggestions`
  ADD CONSTRAINT `outfit_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `outfit_ibfk_2` FOREIGN KEY (`paired_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  ADD CONSTRAINT `chat_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_session_memory`
--
ALTER TABLE `chat_session_memory`
  ADD CONSTRAINT `chat_session_memory_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_long_term_memory`
--
ALTER TABLE `user_long_term_memory`
  ADD CONSTRAINT `user_long_term_memory_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `tool_executions`
--

CREATE TABLE `tool_executions` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `tool_name` varchar(100) NOT NULL,
  `arguments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `success` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `tool_executions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `tool_name` (`tool_name`);

ALTER TABLE `tool_executions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `tool_executions`
  ADD CONSTRAINT `tool_executions_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
