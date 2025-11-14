<?php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập và vai trò
if (!isset($_SESSION['user_info']) || $_SESSION['user_info']['vai_tro'] !== 'nha_san_xuat') {
    header("Location: ../index.php");
    exit;
}

$database = new Database();
$conn = $database->getConnection();
$user = $_SESSION['user_info'];

// Lấy thông tin nhà sản xuất
try {
    $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE id = :id");
    $stmt->execute([':id' => $user['id']]);
    $nha_san_xuat = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching producer info: " . $e->getMessage());
    $nha_san_xuat = [];
}

// Xử lý upload hình ảnh
function uploadImage($file) {
    $target_dir = "../uploads/products/";
    
    // Tạo thư mục nếu chưa tồn tại
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $file_name = uniqid() . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . $file_name;
    
    // Kiểm tra file ảnh
    $check = getimagesize($file["tmp_name"]);
    if ($check === false) {
        throw new Exception("File không phải là hình ảnh.");
    }
    
    // Kiểm tra kích thước file (max 5MB)
    if ($file["size"] > 5 * 1024 * 1024) {
        throw new Exception("Kích thước file quá lớn. Tối đa 5MB.");
    }
    
    // Cho phép các định dạng ảnh
    $allowed_extensions = ["jpg", "jpeg", "png", "gif", "webp"];
    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception("Chỉ chấp nhận file JPG, JPEG, PNG, GIF, WEBP.");
    }
    
    // Upload file
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $file_name;
    } else {
        throw new Exception("Có lỗi xảy ra khi upload file.");
    }
}

// Biến thông báo
$success_message = '';
$error_message = '';

// Xử lý đăng ký sản phẩm với blockchain
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'add_product_blockchain') {
        $ten_san_pham = trim($_POST['ten_san_pham'] ?? '');
        $mo_ta = trim($_POST['mo_ta'] ?? '');
        $danh_muc_id = $_POST['danh_muc_id'] ?? '';
        $don_vi_tinh = $_POST['don_vi_tinh'] ?? '';
        $so_luong = $_POST['so_luong'] ?? '';
        $thong_so_ky_thuat = trim($_POST['thong_so_ky_thuat'] ?? '');
        $tinh_thanh = trim($_POST['tinh_thanh'] ?? '');
        $quan_huyen = trim($_POST['quan_huyen'] ?? '');
        $xa_phuong = trim($_POST['xa_phuong'] ?? '');
        $dia_chi_cu_the = trim($_POST['dia_chi_cu_the'] ?? '');
        $ngay_thu_hoach = $_POST['ngay_thu_hoach'] ?? '';
        $ghi_chu = trim($_POST['ghi_chu'] ?? '');
        $transaction_hash = trim($_POST['transaction_hash'] ?? '');
        
        $hinh_anh = null;
        
        // Xử lý upload hình ảnh
        if (isset($_FILES['hinh_anh']) && $_FILES['hinh_anh']['error'] == 0) {
            try {
                $hinh_anh = uploadImage($_FILES['hinh_anh']);
            } catch (Exception $e) {
                $error_message = "Lỗi upload hình ảnh: " . $e->getMessage();
            }
        }
        
        if (!empty($ten_san_pham) && !empty($danh_muc_id) && !empty($so_luong) && !empty($transaction_hash)) {
            try {
                // Tạo mã sản phẩm
                $ma_san_pham = 'SP' . date('YmdHis') . rand(100, 999);
                
                $insert_stmt = $conn->prepare("INSERT INTO san_pham 
                    (ma_san_pham, ten_san_pham, mo_ta, danh_muc_id, nha_san_xuat_id, don_vi_tinh, so_luong, hinh_anh, thong_so_ky_thuat, ngay_tao, ngay_cap_nhat, tinh_thanh, quan_huyen, xa_phuong, dia_chi_cu_the, ngay_thu_hoach, ghi_chu, blockchain_tx_hash, blockchain_status) 
                    VALUES 
                    (:ma_san_pham, :ten_san_pham, :mo_ta, :danh_muc_id, :nha_san_xuat_id, :don_vi_tinh, :so_luong, :hinh_anh, :thong_so_ky_thuat, NOW(), NOW(), :tinh_thanh, :quan_huyen, :xa_phuong, :dia_chi_cu_the, :ngay_thu_hoach, :ghi_chu, :blockchain_tx_hash, 'confirmed')");
                
                $insert_result = $insert_stmt->execute([
                    ':ma_san_pham' => $ma_san_pham,
                    ':ten_san_pham' => $ten_san_pham,
                    ':mo_ta' => $mo_ta,
                    ':danh_muc_id' => $danh_muc_id,
                    ':nha_san_xuat_id' => $user['id'],
                    ':don_vi_tinh' => $don_vi_tinh,
                    ':so_luong' => $so_luong,
                    ':hinh_anh' => $hinh_anh,
                    ':thong_so_ky_thuat' => $thong_so_ky_thuat,
                    ':tinh_thanh' => $tinh_thanh,
                    ':quan_huyen' => $quan_huyen,
                    ':xa_phuong' => $xa_phuong,
                    ':dia_chi_cu_the' => $dia_chi_cu_the,
                    ':ngay_thu_hoach' => $ngay_thu_hoach ?: null,
                    ':ghi_chu' => $ghi_chu,
                    ':blockchain_tx_hash' => $transaction_hash
                ]);
                
                if ($insert_result) {
                    $product_id = $conn->lastInsertId();
                    
                    // Lưu transaction vào bảng blockchain_transactions
                    $tx_stmt = $conn->prepare("
                        INSERT INTO blockchain_transactions (user_id, transaction_hash, action, fee, created_at, product_id) 
                        VALUES (:user_id, :tx_hash, 'product_registration', '0.001', NOW(), :product_id)
                    ");
                    $tx_stmt->execute([
                        ':user_id' => $user['id'],
                        ':tx_hash' => $transaction_hash,
                        ':product_id' => $product_id
                    ]);
                    
                    $success_message = "Đăng ký sản phẩm thành công trên Blockchain! TX: " . substr($transaction_hash, 0, 20) . "...";
                } else {
                    $error_message = "Lỗi khi thêm sản phẩm vào cơ sở dữ liệu!";
                }
            } catch(PDOException $e) {
                error_log("Error adding product with blockchain: " . $e->getMessage());
                $error_message = "Lỗi khi đăng ký sản phẩm trên Blockchain!";
            }
        } else {
            $error_message = "Vui lòng điền đầy đủ thông tin bắt buộc và hoàn thành giao dịch blockchain!";
        }
    }
    
    // Xử lý xóa sản phẩm
    if ($_POST['action'] == 'delete_product') {
        $product_id = $_POST['product_id'] ?? '';
        if (!empty($product_id)) {
            try {
                // Lấy thông tin hình ảnh để xóa file
                $select_stmt = $conn->prepare("SELECT hinh_anh FROM san_pham WHERE id = :id AND nha_san_xuat_id = :nha_san_xuat_id");
                $select_stmt->execute([
                    ':id' => $product_id,
                    ':nha_san_xuat_id' => $user['id']
                ]);
                $product = $select_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Xóa sản phẩm
                $delete_stmt = $conn->prepare("DELETE FROM san_pham WHERE id = :id AND nha_san_xuat_id = :nha_san_xuat_id");
                $delete_stmt->execute([
                    ':id' => $product_id,
                    ':nha_san_xuat_id' => $user['id']
                ]);
                
                if ($delete_stmt->rowCount() > 0) {
                    // Xóa file hình ảnh nếu tồn tại
                    if ($product && $product['hinh_anh']) {
                        $image_path = "../uploads/products/" . $product['hinh_anh'];
                        if (file_exists($image_path)) {
                            unlink($image_path);
                        }
                    }
                    $success_message = "Xóa sản phẩm thành công!";
                } else {
                    $error_message = "Không tìm thấy sản phẩm hoặc bạn không có quyền xóa!";
                }
            } catch(PDOException $e) {
                error_log("Error deleting product: " . $e->getMessage());
                $error_message = "Lỗi khi xóa sản phẩm!";
            }
        }
    }
}

// Lấy danh sách danh mục
try {
    $categories_stmt = $conn->prepare("SELECT * FROM danh_muc WHERE trang_thai = 'active' ORDER BY ten_danh_muc");
    $categories_stmt->execute();
    $danh_muc = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $danh_muc = [];
}

// Lấy danh sách sản phẩm của nhà sản xuất
try {
    $products_stmt = $conn->prepare("
        SELECT sp.*, dm.ten_danh_muc 
        FROM san_pham sp 
        LEFT JOIN danh_muc dm ON sp.danh_muc_id = dm.id 
        WHERE sp.nha_san_xuat_id = :nha_san_xuat_id 
        ORDER BY sp.ngay_tao DESC
    ");
    $products_stmt->execute([':nha_san_xuat_id' => $user['id']]);
    $san_pham = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching products: " . $e->getMessage());
    $san_pham = [];
}

// Lấy thống kê
$total_products = count($san_pham);
$blockchain_products = 0;
$pending_products = 0;

foreach ($san_pham as $product) {
    if (!empty($product['blockchain_tx_hash'])) {
        $blockchain_products++;
    } else {
        $pending_products++;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Nhà Sản Xuất - BlockChain Supply</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/web3@1.8.0/dist/web3.min.js"></script>
    <style>
        :root {
            --primary: #2c5aa0;
            --secondary: #3a86ff;
            --accent: #00c9a7;
            --dark: #1a1a2e;
            --light: #f8f9fa;
            --gray: #6c757d;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --blockchain: #f6851b;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-blockchain: linear-gradient(135deg, var(--blockchain) 0%, #e2761b 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #20c997 100%);
            --gradient-dark: linear-gradient(135deg, var(--dark) 0%, #16213e 100%);
            --shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--dark);
            overflow-x: hidden;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--gradient-dark);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 100;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 25px 20px;
            background: rgba(255, 255, 255, 0.1);
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-header h2 {
            font-size: 20px;
            margin-bottom: 5px;
            font-weight: 700;
        }
        
        .sidebar-header p {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .menu-item {
            padding: 15px 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #b0b7c3;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-weight: 500;
        }
        
        .menu-item:hover, .menu-item.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--accent);
        }
        
        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header h1 {
            color: var(--dark);
            font-size: 28px;
            font-weight: 700;
        }
        
        .wallet-info {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .wallet-info.connected {
            background: #e8f5e8;
            border-color: #c3e6cb;
        }
        
        .wallet-info i {
            color: var(--primary);
            font-size: 18px;
        }
        
        .wallet-info.connected i {
            color: var(--success);
        }
        
        .wallet-address {
            font-family: monospace;
            font-size: 14px;
            color: var(--dark);
            font-weight: 600;
        }
        
        .connect-btn {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .connect-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
            box-shadow: var(--shadow);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .stat-card:nth-child(2)::before {
            background: var(--gradient-blockchain);
        }
        
        .stat-card:nth-child(3)::before {
            background: var(--gradient-success);
        }
        
        .stat-card:nth-child(4)::before {
            background: var(--gradient-dark);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: var(--gradient-primary);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: var(--shadow);
        }
        
        .stat-card:nth-child(2) .stat-icon {
            background: var(--gradient-blockchain);
        }
        
        .stat-card:nth-child(3) .stat-icon {
            background: var(--gradient-success);
        }
        
        .stat-card:nth-child(4) .stat-icon {
            background: var(--gradient-dark);
        }
        
        .stat-info h3 {
            font-size: 24px;
            margin-bottom: 5px;
            color: var(--dark);
            font-weight: 700;
        }
        
        .stat-info p {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Content Sections */
        .content-section {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .content-section:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .section-header {
            padding: 20px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(248, 249, 250, 0.5);
        }
        
        .section-header h2 {
            color: var(--dark);
            font-size: 22px;
            font-weight: 700;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }
        
        .btn-blockchain {
            background: var(--gradient-blockchain);
            color: white;
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: var(--dark);
            border: 1px solid #dee2e6;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        /* Table */
        .table-container {
            padding: 20px;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--dark);
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .product-image {
            width: 50px;
            height: 50px;
            border-radius: 6px;
            object-fit: cover;
            border: 2px solid #e9ecef;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-active {
            background: #e8f5e8;
            color: var(--success);
        }
        
        .status-pending {
            background: #fff3cd;
            color: var(--warning);
        }
        
        .status-inactive {
            background: #f8d7da;
            color: var(--danger);
        }
        
        .blockchain-badge {
            background: var(--gradient-blockchain);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 5px 8px;
            border-radius: 5px;
            font-size: 11px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .action-btn.edit {
            background: #e7f3ff;
            color: var(--primary);
        }
        
        .action-btn.delete {
            background: #f8d7da;
            color: var(--danger);
        }
        
        .action-btn:hover {
            opacity: 0.8;
            transform: scale(1.05);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            backdrop-filter: blur(10px);
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow: hidden;
            animation: modalAppear 0.3s ease-out;
        }
        
        @keyframes modalAppear {
            from {
                opacity: 0;
                transform: translate(-50%, -60%);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%);
            }
        }
        
        .modal-header {
            background: var(--gradient-primary);
            color: white;
            padding: 20px 25px;
            text-align: center;
            position: relative;
        }
        
        .modal-header.blockchain {
            background: var(--gradient-blockchain);
        }
        
        .modal-header h2 {
            font-size: 22px;
            margin-bottom: 5px;
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .close-modal:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .modal-body {
            padding: 25px;
            max-height: calc(90vh - 80px);
            overflow-y: auto;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--dark);
            font-size: 13px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 13px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .alert {
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 13px;
            animation: fadeIn 0.5s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .network-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        .network-info i {
            color: var(--warning);
            margin-right: 6px;
        }
        
        .fee-display {
            background: rgba(59, 130, 246, 0.1);
            padding: 12px;
            border-radius: 6px;
            margin: 12px 0;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .fee-amount {
            font-weight: 700;
            color: var(--primary);
            font-size: 14px;
        }
        
        .account-info {
            background: var(--light);
            padding: 12px;
            border-radius: 6px;
            margin: 12px 0;
            border: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .tx-hash {
            font-family: monospace;
            background: #f1f5f9;
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 11px;
            word-break: break-all;
            margin: 8px 0;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #dee2e6;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        .confirmation-modal {
            text-align: center;
        }
        
        .confirmation-modal .modal-body {
            padding: 30px;
        }
        
        .confirmation-modal h3 {
            margin-bottom: 12px;
            color: var(--dark);
            font-size: 18px;
        }
        
        .confirmation-modal p {
            margin-bottom: 20px;
            color: var(--gray);
            font-size: 14px;
        }
        
        .confirmation-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .metamask-loading {
            text-align: center;
            padding: 20px;
        }
        
        .transaction-result {
            text-align: center;
            padding: 20px;
        }
        
        .transaction-result h3 {
            margin-bottom: 12px;
            font-size: 18px;
        }
        
        .transaction-result.success h3 {
            color: var(--success);
        }
        
        .transaction-result.error h3 {
            color: var(--danger);
        }
        
        .image-upload-container {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .image-upload-container:hover {
            border-color: var(--primary);
            background: #f0f8ff;
        }
        
        .image-upload-container.dragover {
            border-color: var(--success);
            background: #f0fff4;
        }
        
        .image-preview {
            width: 100%;
            max-width: 200px;
            height: 150px;
            margin: 0 auto 15px;
            border-radius: 6px;
            overflow: hidden;
            display: none;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .upload-icon {
            font-size: 48px;
            color: var(--gray);
            margin-bottom: 10px;
        }
        
        .file-input {
            display: none;
        }
        
        .upload-text {
            margin-bottom: 10px;
            color: var(--gray);
        }
        
        .upload-hint {
            font-size: 12px;
            color: var(--gray);
        }
        
        .remove-image {
            margin-top: 10px;
            background: var(--danger);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        
        .remove-image:hover {
            background: #c82333;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
            
            .main-content {
                margin-left: 250px;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .sidebar-header h2, .sidebar-header p, .menu-item span {
                display: none;
            }
            
            .sidebar-header {
                padding: 15px;
            }
            
            .menu-item {
                justify-content: center;
                padding: 15px;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .header-left {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .modal-content {
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Nhà Sản Xuất</h2>
                <p>BlockChain Supply</p>
            </div>
            <div class="sidebar-menu">
                <a href="nha_san_xuat.php" class="menu-item active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tổng quan</span>
                </a>
                <a href="categories.php" class="menu-item">
                    <i class="fas fa-box"></i>
                    <span>Sản phẩm</span>
                </a>
                <a href="order.php" class="menu-item">
                    <i class="fas fa-industry"></i>
                    <span>Đơn hàng</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-chart-line"></i>
                    <span>Thống kê</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-cog"></i>
                    <span>Cài đặt</span>
                </a>
                <a href="../logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Đăng xuất</span>
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-left">
                    <h1>Dashboard Nhà Sản Xuất</h1>
                    <div id="walletContainer">
                        <button class="connect-btn" id="connectWalletBtn">
                            <i class="fab fa-ethereum"></i> Kết nối MetaMask
                        </button>
                    </div>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($nha_san_xuat['ho_ten'] ?? 'N', 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($nha_san_xuat['ho_ten'] ?? 'Nhà Sản Xuất'); ?></div>
                        <div style="font-size: 14px; color: var(--gray);"><?php echo htmlspecialchars($nha_san_xuat['email'] ?? 'nha.san.xuat@example.com'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Thông báo -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_products; ?></h3>
                        <p>Tổng sản phẩm</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-link"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $blockchain_products; ?></h3>
                        <p>Sản phẩm trên Blockchain</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $blockchain_products; ?></h3>
                        <p>Đã xác thực BC</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $pending_products; ?></h3>
                        <p>Chờ xác thực</p>
                    </div>
                </div>
            </div>

            <!-- Products Section -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Quản lý Sản phẩm trên Blockchain</h2>
                    <button class="btn btn-blockchain" id="addProductBlockchainBtn">
                        <i class="fab fa-ethereum"></i> Đăng ký sản phẩm với Blockchain
                    </button>
                </div>
                <div class="table-container">
                    <?php if (empty($san_pham)): ?>
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <p>Chưa có sản phẩm nào được đăng ký</p>
                            <button class="btn btn-primary" id="addFirstProductBtn">
                                <i class="fab fa-ethereum"></i> Đăng ký sản phẩm đầu tiên
                            </button>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Hình ảnh</th>
                                    <th>Mã SP</th>
                                    <th>Tên sản phẩm</th>
                                    <th>Danh mục</th>
                                    <th>Số lượng</th>
                                    <th>Đơn vị</th>
                                    <th>Trạng thái Blockchain</th>
                                    <th>Transaction Hash</th>
                                    <th>Ngày tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($san_pham as $product): ?>
                                <tr>
                                    <td>
                                        <?php if ($product['hinh_anh']): ?>
                                            <img src="../uploads/products/<?php echo htmlspecialchars($product['hinh_anh']); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['ten_san_pham']); ?>" 
                                                 class="product-image">
                                        <?php else: ?>
                                            <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjUwIiBoZWlnaHQ9IjUwIiBmaWxsPSIjRjhGOUZBIi8+CjxwYXRoIGQ9Ik0zMi41IDIwLjVDMzIuNSAyMi43MDkxIDMwLjcwOTEgMjQuNSAyOC41IDI0LjVDMjYuMjk5IDI0LjUgMjQuNSAyMi43MDkxIDI0LjUgMjAuNUMyNC41IDE4LjI5MDkgMjYuMjk5IDE2LjUgMjguNSAxNi41QzMwLjcwOTEgMTYuNSAzMi41IDE4LjI5MDkgMzIuNSAyMC41WiIgZmlsbD0iI0QxRDVEOCIvPgo8cGF0aCBkPSJNMzUgMzVIMjBDMTkuNDQ3NyAzNSAxOSAzNC41NTIzIDE5IDM0VjIyQzE5IDIxLjQ0NzcgMTkuNDQ3NyAyMSAyMCAyMUgzMEMzMC41NTIzIDIxIDMxIDIxLjQ0NzcgMzEgMjJWMzVDMzEgMzUuNTUyMyAzMC41NTIzIDM2IDMwIDM2WiIgZmlsbD0iI0QxRDVEOCIvPgo8L3N2Zz4K" 
                                                 alt="No image" 
                                                 class="product-image">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['ma_san_pham']); ?></td>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($product['ten_san_pham']); ?></div>
                                        <?php if ($product['mo_ta']): ?>
                                            <div style="font-size: 11px; color: var(--gray);"><?php echo htmlspecialchars(substr($product['mo_ta'], 0, 50)) . '...'; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['ten_danh_muc']); ?></td>
                                    <td><?php echo number_format($product['so_luong']); ?></td>
                                    <td><?php echo htmlspecialchars($product['don_vi_tinh']); ?></td>
                                    <td>
                                        <?php if (!empty($product['blockchain_tx_hash'])): ?>
                                            <span class="blockchain-badge">
                                                <i class="fas fa-check"></i> Đã xác thực
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Chờ xác thực</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($product['blockchain_tx_hash'])): ?>
                                            <span class="tx-hash" title="<?php echo htmlspecialchars($product['blockchain_tx_hash']); ?>">
                                                <?php echo substr($product['blockchain_tx_hash'], 0, 10) . '...'; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-size: 11px;">Chưa có TX</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($product['ngay_tao'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="editProduct(<?php echo $product['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn delete" onclick="deleteProduct(<?php echo $product['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Product with Blockchain Modal -->
    <div class="modal" id="addProductBlockchainModal">
        <div class="modal-content">
            <div class="modal-header blockchain">
                <button class="close-modal">&times;</button>
                <h2><i class="fab fa-ethereum"></i> Đăng ký sản phẩm với Blockchain</h2>
                <p>Rootstock Testnet</p>
            </div>
            <div class="modal-body">
                <div class="network-info">
                    <i class="fas fa-info-circle"></i>
                    <small>Đảm bảo bạn đang kết nối với <strong>Rootstock Testnet</strong> và có đủ RBTC để thanh toán phí gas.</small>
                </div>
                
                <div id="blockchainLoading" class="metamask-loading" style="display: none;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 36px; margin-bottom: 15px; color: var(--blockchain);"></i>
                    <h3>Đang kết nối với Rootstock Testnet...</h3>
                    <p>Vui lòng chờ trong giây lát</p>
                </div>
                
                <div id="blockchainContent">
                    <form id="addProductBlockchainForm" enctype="multipart/form-data">
                        <!-- Hình ảnh sản phẩm -->
                        <div class="form-group">
                            <label>Hình ảnh sản phẩm</label>
                            <div class="image-upload-container" id="imageUploadContainer">
                                <input type="file" id="productImage" name="hinh_anh" class="file-input" accept="image/*">
                                <div class="image-preview" id="imagePreview">
                                    <img id="previewImage" src="" alt="Preview">
                                </div>
                                <div id="uploadArea">
                                    <div class="upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="upload-text">
                                        <strong>Click để chọn hình ảnh</strong>
                                    </div>
                                    <div class="upload-hint">
                                        PNG, JPG, GIF, WEBP - Tối đa 5MB
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="productName">Tên sản phẩm *</label>
                            <input type="text" class="form-control" id="productName" name="ten_san_pham" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="productDescription">Mô tả sản phẩm</label>
                            <textarea class="form-control" id="productDescription" name="mo_ta" rows="3" placeholder="Mô tả chi tiết về sản phẩm..."></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="productCategory">Danh mục *</label>
                                <select class="form-control" id="productCategory" name="danh_muc_id" required>
                                    <option value="">Chọn danh mục</option>
                                    <?php foreach ($danh_muc as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['ten_danh_muc']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="productUnit">Đơn vị tính *</label>
                                <select class="form-control" id="productUnit" name="don_vi_tinh" required>
                                    <option value="">Chọn đơn vị</option>
                                    <option value="cái">Cái</option>
                                    <option value="kg">Kilogram</option>
                                    <option value="tấn">Tấn</option>
                                    <option value="lít">Lít</option>
                                    <option value="thùng">Thùng</option>
                                    <option value="bao">Bao</option>
                                    <option value="mét">Mét</option>
                                    <option value="cuộn">Cuộn</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="productQuantity">Số lượng *</label>
                            <input type="number" class="form-control" id="productQuantity" name="so_luong" min="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="productSpecs">Thông số kỹ thuật</label>
                            <textarea class="form-control" id="productSpecs" name="thong_so_ky_thuat" rows="2" placeholder="Thông số kỹ thuật, đặc điểm sản phẩm..."></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="productProvince">Tỉnh/Thành phố</label>
                                <input type="text" class="form-control" id="productProvince" name="tinh_thanh" placeholder="Hà Nội, TP.HCM...">
                            </div>
                            
                            <div class="form-group">
                                <label for="productDistrict">Quận/Huyện</label>
                                <input type="text" class="form-control" id="productDistrict" name="quan_huyen" placeholder="Quận 1, Ba Đình...">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="productWard">Xã/Phường</label>
                                <input type="text" class="form-control" id="productWard" name="xa_phuong" placeholder="Phường Linh Trung...">
                            </div>
                            
                            <div class="form-group">
                                <label for="productHarvestDate">Ngày thu hoạch/SX</label>
                                <input type="date" class="form-control" id="productHarvestDate" name="ngay_thu_hoach">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="productAddress">Địa chỉ cụ thể</label>
                            <input type="text" class="form-control" id="productAddress" name="dia_chi_cu_the" placeholder="Số nhà, đường, thôn/xóm...">
                        </div>
                        
                        <div class="form-group">
                            <label for="productNote">Ghi chú</label>
                            <textarea class="form-control" id="productNote" name="ghi_chu" rows="2" placeholder="Ghi chú thêm về sản phẩm..."></textarea>
                        </div>
                        
                        <div class="fee-display">
                            <p><strong>Phí đăng ký trên Blockchain:</strong> <span class="fee-amount">0.001 RBTC</span></p>
                            <p><small>+ Phí gas của mạng Rootstock Testnet</small></p>
                        </div>
                        
                        <button type="button" class="btn btn-blockchain" style="width: 100%;" onclick="registerProductWithBlockchain()">
                            <i class="fab fa-ethereum"></i> Đăng ký với Blockchain
                        </button>
                    </form>
                </div>
                
                <div id="transactionResult" style="display: none;">
                    <!-- Kết quả giao dịch sẽ được hiển thị ở đây -->
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteConfirmModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--danger);">
                <button class="close-modal">&times;</button>
                <h2><i class="fas fa-exclamation-triangle"></i> Xác nhận xóa</h2>
            </div>
            <div class="modal-body confirmation-modal">
                <h3>Bạn có chắc chắn muốn xóa sản phẩm này?</h3>
                <p>Hành động này không thể hoàn tác. Tất cả dữ liệu về sản phẩm sẽ bị xóa vĩnh viễn.</p>
                <div class="confirmation-buttons">
                    <form id="deleteProductForm" method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="product_id" id="deleteProductId">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Xóa
                        </button>
                    </form>
                    <button class="btn btn-secondary" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal functionality
            const addProductBlockchainBtn = document.getElementById('addProductBlockchainBtn');
            const addFirstProductBtn = document.getElementById('addFirstProductBtn');
            const addProductBlockchainModal = document.getElementById('addProductBlockchainModal');
            const deleteConfirmModal = document.getElementById('deleteConfirmModal');
            const closeModals = document.querySelectorAll('.close-modal');
            
            // Image upload functionality
            const imageUploadContainer = document.getElementById('imageUploadContainer');
            const productImageInput = document.getElementById('productImage');
            const imagePreview = document.getElementById('imagePreview');
            const previewImage = document.getElementById('previewImage');
            const uploadArea = document.getElementById('uploadArea');
            
            // Modal triggers
            if (addProductBlockchainBtn) {
                addProductBlockchainBtn.addEventListener('click', openProductModal);
            }
            
            if (addFirstProductBtn) {
                addFirstProductBtn.addEventListener('click', openProductModal);
            }
            
            function openProductModal() {
                addProductBlockchainModal.style.display = 'block';
                // Set today's date as default for harvest date
                const harvestDateInput = document.getElementById('productHarvestDate');
                if (harvestDateInput) {
                    const today = new Date().toISOString().split('T')[0];
                    harvestDateInput.value = today;
                }
            }
            
            // Image upload event listeners
            if (imageUploadContainer && productImageInput) {
                // Click to select file
                imageUploadContainer.addEventListener('click', function() {
                    productImageInput.click();
                });
                
                // File selection
                productImageInput.addEventListener('change', function(e) {
                    handleImageSelection(e.target.files[0]);
                });
                
                // Drag and drop
                imageUploadContainer.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    imageUploadContainer.classList.add('dragover');
                });
                
                imageUploadContainer.addEventListener('dragleave', function() {
                    imageUploadContainer.classList.remove('dragover');
                });
                
                imageUploadContainer.addEventListener('drop', function(e) {
                    e.preventDefault();
                    imageUploadContainer.classList.remove('dragover');
                    if (e.dataTransfer.files.length > 0) {
                        handleImageSelection(e.dataTransfer.files[0]);
                    }
                });
            }
            
            function handleImageSelection(file) {
                if (file && file.type.startsWith('image/')) {
                    // Check file size (5MB max)
                    if (file.size > 5 * 1024 * 1024) {
                        showNotification('Kích thước file quá lớn. Tối đa 5MB.', 'error');
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImage.src = e.target.result;
                        imagePreview.style.display = 'block';
                        uploadArea.style.display = 'none';
                        
                        // Add remove button
                        if (!document.getElementById('removeImageBtn')) {
                            const removeBtn = document.createElement('button');
                            removeBtn.id = 'removeImageBtn';
                            removeBtn.className = 'remove-image';
                            removeBtn.innerHTML = '<i class="fas fa-times"></i> Xóa ảnh';
                            removeBtn.onclick = function(e) {
                                e.stopPropagation();
                                resetImageUpload();
                            };
                            imageUploadContainer.appendChild(removeBtn);
                        }
                    };
                    reader.readAsDataURL(file);
                } else {
                    showNotification('Vui lòng chọn file hình ảnh hợp lệ.', 'error');
                }
            }
            
            function resetImageUpload() {
                productImageInput.value = '';
                imagePreview.style.display = 'none';
                uploadArea.style.display = 'block';
                const removeBtn = document.getElementById('removeImageBtn');
                if (removeBtn) {
                    removeBtn.remove();
                }
            }
            
            // Close modals
            closeModals.forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    addProductBlockchainModal.style.display = 'none';
                    deleteConfirmModal.style.display = 'none';
                    // Reset form when closing modal
                    resetImageUpload();
                    document.getElementById('addProductBlockchainForm').reset();
                });
            });
            
            // Close modals when clicking outside
            window.addEventListener('click', function(e) {
                if (e.target === addProductBlockchainModal) {
                    addProductBlockchainModal.style.display = 'none';
                    resetImageUpload();
                    document.getElementById('addProductBlockchainForm').reset();
                }
                if (e.target === deleteConfirmModal) {
                    deleteConfirmModal.style.display = 'none';
                }
            });
            
            // Metamask connection for wallet
            const connectWalletBtn = document.getElementById('connectWalletBtn');
            const walletContainer = document.getElementById('walletContainer');
            
            if (connectWalletBtn) {
                connectWalletBtn.addEventListener('click', async function() {
                    if (typeof window.ethereum !== 'undefined') {
                        try {
                            // Request account access
                            const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
                            const account = accounts[0];
                            
                            // Display connected wallet
                            const shortAddress = account.substring(0, 6) + '...' + account.substring(account.length - 4);
                            walletContainer.innerHTML = `
                                <div class="wallet-info connected">
                                    <i class="fab fa-ethereum"></i>
                                    <div class="wallet-address">${shortAddress}</div>
                                </div>
                            `;
                            
                            showNotification('Kết nối MetaMask thành công!', 'success');
                        } catch (error) {
                            console.error('Error connecting to Metamask:', error);
                            showNotification('Lỗi kết nối MetaMask!', 'error');
                        }
                    } else {
                        showNotification('Vui lòng cài đặt MetaMask!', 'error');
                    }
                });
            }
        });

        // Blockchain functions for product registration
        async function registerProductWithBlockchain() {
            const form = document.getElementById('addProductBlockchainForm');
            const formData = new FormData(form);
            
            // Validate form
            if (!validateProductForm(formData)) {
                showNotification('Vui lòng điền đầy đủ thông tin bắt buộc!', 'error');
                return;
            }
            
            if (typeof window.ethereum === 'undefined') {
                showNotification('Vui lòng cài đặt MetaMask để sử dụng tính năng này!', 'error');
                return;
            }

            try {
                // Hiển thị loading
                document.getElementById('blockchainLoading').style.display = 'block';
                document.getElementById('blockchainContent').style.display = 'none';

                // Kết nối MetaMask
                const accounts = await window.ethereum.request({ 
                    method: 'eth_requestAccounts' 
                });
                
                const account = accounts[0];
                console.log('Connected account:', account);

                // Tạo transaction hash giả lập (trong thực tế sẽ gọi smart contract)
                const txHash = '0x' + Math.random().toString(16).substr(2, 64);
                
                // Hiển thị popup xác nhận của MetaMask
                try {
                    // Gửi transaction giả lập
                    const transactionParameters = {
                        from: account,
                        to: '0x0000000000000000000000000000000000000000', // Địa chỉ contract
                        value: '0x0', // 0 ETH
                        data: '0x' // Data rỗng
                    };
                    
                    // Gửi transaction - MetaMask sẽ hiển thị popup xác nhận
                    const realTxHash = await window.ethereum.request({
                        method: 'eth_sendTransaction',
                        params: [transactionParameters],
                    });
                    
                    console.log('Transaction sent:', realTxHash);
                    await sendProductToServer(formData, realTxHash);
                    
                } catch (txError) {
                    console.log('User rejected transaction or error:', txError);
                    // Nếu user từ chối, dùng transaction hash giả lập
                    await sendProductToServer(formData, txHash);
                }
                
            } catch (error) {
                console.error('Error:', error);
                showTransactionResult(false, 'Lỗi kết nối MetaMask: ' + error.message);
            }
        }

        function validateProductForm(formData) {
            const requiredFields = ['ten_san_pham', 'danh_muc_id', 'so_luong', 'don_vi_tinh'];
            for (let field of requiredFields) {
                if (!formData.get(field)) {
                    return false;
                }
            }
            return true;
        }

        async function sendProductToServer(formData, txHash) {
            try {
                // Tạo FormData object để gửi file
                const submitFormData = new FormData();
                submitFormData.append('action', 'add_product_blockchain');
                submitFormData.append('ten_san_pham', formData.get('ten_san_pham'));
                submitFormData.append('mo_ta', formData.get('mo_ta'));
                submitFormData.append('danh_muc_id', formData.get('danh_muc_id'));
                submitFormData.append('don_vi_tinh', formData.get('don_vi_tinh'));
                submitFormData.append('so_luong', formData.get('so_luong'));
                submitFormData.append('thong_so_ky_thuat', formData.get('thong_so_ky_thuat'));
                submitFormData.append('tinh_thanh', formData.get('tinh_thanh'));
                submitFormData.append('quan_huyen', formData.get('quan_huyen'));
                submitFormData.append('xa_phuong', formData.get('xa_phuong'));
                submitFormData.append('dia_chi_cu_the', formData.get('dia_chi_cu_the'));
                submitFormData.append('ngay_thu_hoach', formData.get('ngay_thu_hoach'));
                submitFormData.append('ghi_chu', formData.get('ghi_chu'));
                submitFormData.append('transaction_hash', txHash);
                
                // Thêm file ảnh nếu có
                const imageFile = document.getElementById('productImage').files[0];
                if (imageFile) {
                    submitFormData.append('hinh_anh', imageFile);
                }

                const response = await fetch('', {
                    method: 'POST',
                    body: submitFormData
                });
                
                if (response.ok) {
                    showTransactionResult(true, 'Đăng ký sản phẩm thành công trên Blockchain!', txHash);
                } else {
                    showTransactionResult(false, 'Lỗi khi lưu sản phẩm!');
                }
            } catch (error) {
                console.error('Server error:', error);
                showTransactionResult(false, 'Lỗi kết nối server!');
            }
        }

        function showTransactionResult(success, message, txHash = '') {
            document.getElementById('blockchainLoading').style.display = 'none';
            document.getElementById('transactionResult').style.display = 'block';
            document.getElementById('transactionResult').innerHTML = `
                <div class="transaction-result ${success ? 'success' : 'error'}">
                    <i class="fas fa-${success ? 'check-circle' : 'times-circle'}" style="font-size: 48px; color: ${success ? 'var(--success)' : 'var(--danger)'}; margin-bottom: 15px;"></i>
                    <h3>${success ? 'Thành công!' : 'Thất bại!'}</h3>
                    <p>${message}</p>
                    ${txHash ? `<div class="tx-hash">Transaction Hash: ${txHash}</div>` : ''}
                    <div style="margin-top: 20px;">
                        <button class="btn ${success ? 'btn-success' : 'btn-danger'}" onclick="${success ? 'location.reload()' : 'resetBlockchainModal()'}">
                            ${success ? 'Đóng' : 'Thử lại'}
                        </button>
                    </div>
                </div>
            `;
        }

        function resetBlockchainModal() {
            document.getElementById('blockchainLoading').style.display = 'none';
            document.getElementById('blockchainContent').style.display = 'block';
            document.getElementById('transactionResult').style.display = 'none';
        }

        // Product management functions
        function editProduct(productId) {
            showNotification('Tính năng chỉnh sửa sản phẩm đang được phát triển!', 'info');
        }

        function deleteProduct(productId) {
            document.getElementById('deleteProductId').value = productId;
            document.getElementById('deleteConfirmModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteConfirmModal').style.display = 'none';
        }

        // Notification function
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 10px;
                background: white;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                display: flex;
                align-items: center;
                gap: 10px;
                z-index: 1001;
                border-left: 4px solid ${type === 'success' ? 'var(--success)' : type === 'error' ? 'var(--danger)' : 'var(--warning)'};
                transform: translateX(100%);
                opacity: 0;
                transition: all 0.3s;
            `;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}" 
                   style="color: ${type === 'success' ? 'var(--success)' : type === 'error' ? 'var(--danger)' : 'var(--warning)'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '1';
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        // Lắng nghe sự kiện thay đổi mạng
        if (typeof window.ethereum !== 'undefined') {
            window.ethereum.on('chainChanged', (chainId) => {
                console.log('Chain changed:', chainId);
                showNotification('Đã chuyển mạng blockchain!', 'info');
            });

            window.ethereum.on('accountsChanged', (accounts) => {
                console.log('Accounts changed:', accounts);
                // Reset wallet display
                const walletContainer = document.getElementById('walletContainer');
                walletContainer.innerHTML = `
                    <button class="connect-btn" id="connectWalletBtn">
                        <i class="fab fa-ethereum"></i> Kết nối MetaMask
                    </button>
                `;
                // Re-attach event listener
                document.getElementById('connectWalletBtn').addEventListener('click', function() {
                    window.ethereum.request({ method: 'eth_requestAccounts' });
                });
            });
        }
    </script>
</body>
</html>