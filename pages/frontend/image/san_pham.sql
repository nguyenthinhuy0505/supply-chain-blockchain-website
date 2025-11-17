-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 12, 2025 at 02:09 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `supply_chain`
--

-- --------------------------------------------------------

--
-- Table structure for table `san_pham`
--

CREATE TABLE `san_pham` (
  `id` int(11) NOT NULL,
  `ma_san_pham` varchar(50) NOT NULL,
  `ten_san_pham` varchar(255) NOT NULL,
  `mo_ta` text DEFAULT NULL,
  `danh_muc_id` int(11) NOT NULL,
  `nha_san_xuat_id` int(11) NOT NULL,
  `don_vi_tinh` varchar(50) NOT NULL,
  `so_luong` int(11) NOT NULL,
  `hinh_anh` varchar(500) DEFAULT NULL,
  `thong_so_ky_thuat` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`thong_so_ky_thuat`)),
  `ngay_tao` timestamp NOT NULL DEFAULT current_timestamp(),
  `ngay_cap_nhat` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tinh_thanh` varchar(100) DEFAULT NULL,
  `quan_huyen` varchar(100) DEFAULT NULL,
  `xa_phuong` varchar(100) DEFAULT NULL,
  `dia_chi_cu_the` text DEFAULT NULL,
  `ngay_thu_hoach` date DEFAULT NULL,
  `ghi_chu` text DEFAULT NULL,
  `blockchain_tx_hash` varchar(66) DEFAULT NULL,
  `blockchain_status` enum('pending','confirmed','failed') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `san_pham`
--

INSERT INTO `san_pham` (`id`, `ma_san_pham`, `ten_san_pham`, `mo_ta`, `danh_muc_id`, `nha_san_xuat_id`, `don_vi_tinh`, `so_luong`, `hinh_anh`, `thong_so_ky_thuat`, `ngay_tao`, `ngay_cap_nhat`, `tinh_thanh`, `quan_huyen`, `xa_phuong`, `dia_chi_cu_the`, `ngay_thu_hoach`, `ghi_chu`, `blockchain_tx_hash`, `blockchain_status`) VALUES
(77, 'SP20251025161525603', 'Dâu tây', 'Màu đỏ tươi, bóng.\n\nThịt chắc, vị ngọt chua nhẹ, hương thơm dịu.\n\nDùng tươi, làm mứt, siro, rượu hoặc sinh tố.', 5, 15, 'kg', 33, 'san_pham_1761401725_68fcdb7daffda.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"5\",\"mau_sac\":\"màu đỏ\"}', '2025-10-25 14:15:25', '2025-11-10 12:58:25', 'Lâm Đồng', 'TP Đà Lạt', 'Hồ Xuân Hương', '35 Hồ Xuân Hương, Phường 9, TP. Đà Lạt, Lâm Đồng', '2025-10-25', 'Trung tâm trồng rau – hoa – quả ôn đới của Việt Nam.', '0xbb15d7b1022dc6680b251562e5eceb1c9ed844cadf178dae626a3b28b70facdb', 'pending'),
(78, 'SP20251025172438150', 'Rau củ', 'Bắp cải, cà rốt, khoai tây, xà lách, súp lơ, atiso', 1, 15, 'kg', 45, 'san_pham_1761405878_68fcebb6e2648.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"10\",\"mau_sac\":\"Màu xanh lá\"}', '2025-10-25 15:24:38', '2025-11-10 13:34:11', 'Lâm Đồng', 'phường 12 – TP. Đà Lạt', 'Xuân Thọ', 'Xã Xuân Thọ, phường 7, phường 8, phường 12 – TP. Đà Lạt, tỉnh Lâm Đồng', '2025-10-25', 'Khí hậu mát quanh năm (18°C)\n\nĐất bazan tơi xốp, giàu mùn\n\nNước tưới từ suối Cam Ly và hồ Tuyền Lâm', '0x8cb3d63559282770a32ffa57bbf78d9b7036f233e77c82f2120f806c76779f07', 'pending'),
(88, 'SP20251026100634447', 'Gạo huyết rồng', 'Thành phần dinh dưỡng cao: chứa nhiều sắt, kẽm, canxi, vitamin B1, B2 và chất chống oxy hóa anthocyanin.\n\nCông dụng: tốt cho tim mạch, giảm cholesterol, hỗ trợ người tiểu đường.', 3, 15, 'kg', 111, 'san_pham_1761469594_68fde49a7dc36.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"4\",\"mau_sac\":\"màu đỏ\"}', '2025-10-26 09:06:34', '2025-11-10 13:33:23', 'Lâm Đồng', 'Tam Nông', 'Phú Hiệp, Phú Đức, Phú Thành A, Phú Thành B', 'Phú Hiệp, Phú Đức, Phú Thành A, Phú Thành B,Tam Nông,Đồng Tháp', '2025-10-26', 'Vùng trũng Đồng Tháp Mười, đất phèn nhẹ', '0x6bd8f270c08f475445a57d85a4f60f66a0c3845f7b46e957ccdea06197412679', 'pending'),
(89, 'SP20251026101750181', 'Táo đỏ', 'Quả to, ngọt, mọng nước', 5, 15, 'kg', 10, 'san_pham_1761470270_68fde73e16f78.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"1\",\"mau_sac\":\"Đỏ\"}', '2025-10-26 09:17:50', '2025-11-10 12:01:33', 'Lâm Đồng', 'huyện Lạc Dương', 'Đơn Dương', 'TP Đà Lạt, huyện Lạc Dương, Đơn Dương', '2025-10-26', 'khí hậu mát quanh năm, thích hợp cho các giống táo ôn đới nhưng năng suất còn thấp.', '0x3ab1ca6b9c23c762b7fe813dab001d864debdf2ef3fe5c9346fab9e5cb7608e3', 'pending'),
(90, 'SP20251026102940398', 'Quýt hồng', 'quả to, vỏ mỏng, ruột đỏ cam, ngọt đậm, hầu như không hạt.', 5, 15, 'kg', 36, 'san_pham_1761470980_68fdea041f877.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"5\",\"mau_sac\":\"màu cam lè\"}', '2025-10-26 09:29:40', '2025-11-11 01:11:53', 'Đồng Tháp', 'Lai Vung', 'Long Hậu, Tân Phước, Tân Thành, Vĩnh Thới', 'Huyện Lai Vung, tỉnh Đồng Tháp  Các xã trồng nhiều: Long Hậu, Tân Phước, Tân Thành, Vĩnh Thới', '2025-10-26', 'Nằm ở đồng bằng sông Cửu Long, đất phù sa ven sông Hậu.\n\nKhí hậu nhiệt đới ẩm, mùa nắng – mưa rõ rệt, thích hợp cho cây có múi.', '0xf8f42bc742b258f4abdbbb9f6bd1e9793a839f6e9f77f10b26d64e7395f3e86b', 'pending'),
(92, 'SP20251026164641355', 'Dưa leo', 'Cây thân thảo leo, quả mọng, thu hoạch sau 40–55 ngày trồng.', 1, 15, 'kg', 57, 'san_pham_1761493601_68fe426102927.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"10\",\"mau_sac\":\"Màu xanh lá\"}', '2025-10-26 15:46:41', '2025-11-10 13:32:46', 'Vĩnh Long', 'Bình Minh', 'Tam Bình, Long Hồ', 'Huyện Bình Minh, Tam Bình, Long Hồ', '2025-10-26', 'Dưa leo trồng xen vụ ngắn, thu hoạch 40–45 ngày, tiêu thụ trong khu vực miền Tây.', '0xf0717ed98e3e71f6b58a3a620260aacf71097cfbb183fc8462a1a225d587cde7', 'pending'),
(93, 'SP20251026164943434', 'Lá trà', 'Cây lâu năm, thu hái lá non để chế biến thành trà xanh, trà đen, trà ô long...', 7, 15, 'kg', 53, 'san_pham_1761493783_68fe43178d98e.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"10\",\"mau_sac\":\"Màu xanh lá\"}', '2025-10-26 15:49:43', '2025-11-09 16:13:30', 'Thái Nguyên', 'TP. Thái Nguyên', 'Xã Tân Cương', 'Xã Tân Cương, TP. Thái Nguyên', '2025-10-26', 'Đất đỏ vàng, độ pH 4,5–5,5.\n\nKhí hậu mát, sương buổi sáng, nhiệt độ trung bình 22–23°C.\n\nCó thương hiệu “Chè Tân Cương Thái Nguyên” – sản phẩm OCOP 5 sao, xuất khẩu sang Nhật, Hàn, Nga.', '0x1a5912a9376c6c4d67b7a7b3f3c07f8641ec39084cad27930e505cd45535ec25', 'pending'),
(94, 'SP20251026165314734', 'Xoài Cát Chu', 'Ngon', 5, 15, 'kg', 10, 'san_pham_1761493994_68fe43ea137b5.jpg', '{\"do_am\":\"100%\",\"kich_thuoc\":\"10\",\"mau_sac\":\"Vàng \"}', '2025-10-26 15:53:14', '2025-11-10 11:49:45', 'Đồng Tháp', 'Cao Lãnh', 'Xã Hòa An, Mỹ Xương, Tân Thuận Tây', 'Xã Hòa An, Mỹ Xương, Tân Thuận Tây Huyện Cao Lãnh Tỉnh Đồng Tháp', '2025-10-26', 'Đất phù sa ven sông Tiền, nguồn nước dồi dào, khí hậu nhiệt đới ẩm.\n\nCó hợp tác xã Xoài Mỹ Xương, đạt chuẩn VietGAP, GlobalGAP, xuất khẩu sang Hàn Quốc, Nhật Bản, Trung Quốc.', '0xd196c8979b2652070dc3664bb57b1e2319351cdfbb24e66574d7b8b60c91751d', 'pending');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `san_pham`
--
ALTER TABLE `san_pham`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ma_san_pham` (`ma_san_pham`),
  ADD KEY `danh_muc_id` (`danh_muc_id`),
  ADD KEY `idx_san_pham_nha_san_xuat` (`nha_san_xuat_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `san_pham`
--
ALTER TABLE `san_pham`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=102;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `san_pham`
--
ALTER TABLE `san_pham`
  ADD CONSTRAINT `san_pham_ibfk_1` FOREIGN KEY (`danh_muc_id`) REFERENCES `danh_muc` (`id`),
  ADD CONSTRAINT `san_pham_ibfk_2` FOREIGN KEY (`nha_san_xuat_id`) REFERENCES `nguoi_dung` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
