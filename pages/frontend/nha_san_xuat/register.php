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

// KIỂM TRA VÀ TẠO DANH MỤC MẪU NẾU CHƯA CÓ
try {
    $check_categories = $conn->query("SELECT COUNT(*) FROM danh_muc")->fetchColumn();
    
    if ($check_categories == 0) {
        $sample_categories = [
            ['Nông sản', 'Các loại nông sản, rau củ quả'],
            ['Thủy sản', 'Cá, tôm, cua, mực và các loại thủy sản khác'],
            ['Thịt gia súc', 'Thịt heo, thịt bò, thịt gà và các loại thịt khác'],
            ['Trái cây', 'Các loại trái cây tươi'],
            ['Lương thực', 'Gạo, ngô, khoai, sắn']
        ];
        
        $insert_stmt = $conn->prepare("INSERT INTO danh_muc (ten_danh_muc, mo_ta, trang_thai, ngay_tao) VALUES (?, ?, 'active', NOW())");
        
        foreach ($sample_categories as $category) {
            $insert_stmt->execute([$category[0], $category[1]]);
        }
    }
} catch(PDOException $e) {
    error_log("Error checking/creating sample categories: " . $e->getMessage());
}

// Xử lý upload hình ảnh
function uploadImage($file) {
    $target_dir = "../uploads/products/";
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $file_name = uniqid() . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . $file_name;
    
    $check = getimagesize($file["tmp_name"]);
    if ($check === false) {
        throw new Exception("File không phải là hình ảnh.");
    }
    
    if ($file["size"] > 5 * 1024 * 1024) {
        throw new Exception("Kích thước file quá lớn. Tối đa 5MB.");
    }
    
    $allowed_extensions = ["jpg", "jpeg", "png", "gif", "webp"];
    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception("Chỉ chấp nhận file JPG, JPEG, PNG, GIF, WEBP.");
    }
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $file_name;
    } else {
        throw new Exception("Có lỗi xảy ra khi upload file.");
    }
}

// Hàm tạo transaction hash ngẫu nhiên và duy nhất
function generateUniqueTransactionHash($conn) {
    $max_attempts = 10;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        // Tạo transaction hash ngẫu nhiên
        $tx_hash = '0x' . bin2hex(random_bytes(32));
        
        // Kiểm tra xem transaction hash đã tồn tại chưa
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM blockchain_transactions WHERE transaction_hash = :tx_hash");
        $check_stmt->execute([':tx_hash' => $tx_hash]);
        $exists = $check_stmt->fetchColumn();
        
        if (!$exists) {
            return $tx_hash;
        }
        
        $attempt++;
    }
    
    // Nếu không tạo được hash duy nhất sau nhiều lần thử, tạo hash với timestamp
    return '0x' . bin2hex(random_bytes(16)) . dechex(time());
}

// Biến thông báo
$success_message = '';
$error_message = '';

// Xử lý đăng ký sản phẩm với blockchain
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'add_product_blockchain') {
        
        // Lấy dữ liệu từ form
        $ten_san_pham = trim($_POST['ten_san_pham'] ?? '');
        $mo_ta = trim($_POST['mo_ta'] ?? '');
        $danh_muc_id = $_POST['danh_muc_id'] ?? '';
        $don_vi_tinh = $_POST['don_vi_tinh'] ?? '';
        $so_luong = $_POST['so_luong'] ?? '';
        $nhiet_do_bao_quan = $_POST['nhiet_do_bao_quan'] ?? '';
        $do_am_bao_quan = $_POST['do_am_bao_quan'] ?? '';
        $tinh_thanh = trim($_POST['tinh_thanh'] ?? '');
        $quan_huyen = trim($_POST['quan_huyen'] ?? '');
        $xa_phuong = trim($_POST['xa_phuong'] ?? '');
        $dia_chi_cu_the = trim($_POST['dia_chi_cu_the'] ?? '');
        $ngay_thu_hoach = $_POST['ngay_thu_hoach'] ?? '';
        $ghi_chu = trim($_POST['ghi_chu'] ?? '');
        $transaction_hash = trim($_POST['transaction_hash'] ?? '');
        $gia_ban = $_POST['gia_ban'] ?? 0;
        
        $hinh_anh = null;
        
        // Xử lý upload hình ảnh
        if (isset($_FILES['hinh_anh']) && $_FILES['hinh_anh']['error'] == 0) {
            try {
                $hinh_anh = uploadImage($_FILES['hinh_anh']);
            } catch (Exception $e) {
                $error_message = "Lỗi upload hình ảnh: " . $e->getMessage();
            }
        }
        
        // Kiểm tra dữ liệu bắt buộc
        if (empty($ten_san_pham) || empty($danh_muc_id) || empty($so_luong)) {
            $error_message = "Vui lòng điền đầy đủ thông tin bắt buộc: Tên sản phẩm, Danh mục, Số lượng!";
        } else {
            try {
                // Bắt đầu transaction
                $conn->beginTransaction();
                
                // Tạo mã sản phẩm
                $ma_san_pham = 'SP' . date('YmdHis') . rand(100, 999);
                
                // Tạo thông số kỹ thuật từ nhiệt độ và độ ẩm
                $thong_so_ky_thuat = json_encode([
                    'nhiet_do_bao_quan' => $nhiet_do_bao_quan,
                    'do_am_bao_quan' => $do_am_bao_quan
                ]);

                // Tạo transaction hash duy nhất nếu không có từ form
                if (empty($transaction_hash)) {
                    $transaction_hash = generateUniqueTransactionHash($conn);
                } else {
                    // Kiểm tra transaction hash từ form có bị trùng không
                    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM blockchain_transactions WHERE transaction_hash = :tx_hash");
                    $check_stmt->execute([':tx_hash' => $transaction_hash]);
                    $exists = $check_stmt->fetchColumn();
                    
                    if ($exists) {
                        // Nếu bị trùng, tạo hash mới
                        $transaction_hash = generateUniqueTransactionHash($conn);
                    }
                }

                // INSERT VÀO BẢNG SAN_PHAM
                $insert_stmt = $conn->prepare("INSERT INTO san_pham 
                    (ma_san_pham, ten_san_pham, mo_ta, danh_muc_id, nha_san_xuat_id, don_vi_tinh, so_luong, hinh_anh, thong_so_ky_thuat, ngay_tao, ngay_cap_nhat, tinh_thanh, quan_huyen, xa_phuong, dia_chi_cu_the, ngay_thu_hoach, ghi_chu, blockchain_tx_hash, blockchain_status) 
                    VALUES 
                    (:ma_san_pham, :ten_san_pham, :mo_ta, :danh_muc_id, :nha_san_xuat_id, :don_vi_tinh, :so_luong, :hinh_anh, :thong_so_ky_thuat, NOW(), NOW(), :tinh_thanh, :quan_huyen, :xa_phuong, :dia_chi_cu_the, :ngay_thu_hoach, :ghi_chu, :blockchain_tx_hash, 'confirmed')");
                
                $insert_params = [
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
                ];
                
                $insert_result = $insert_stmt->execute($insert_params);
                
                if ($insert_result) {
                    $product_id = $conn->lastInsertId();
                    
                    // Thêm giá sản phẩm vào bảng gia_san_pham
                    $price_stmt = $conn->prepare("INSERT INTO gia_san_pham 
                        (san_pham_id, nha_san_xuat_id, gia_ban, ngay_ap_dung, trang_thai) 
                        VALUES 
                        (:san_pham_id, :nha_san_xuat_id, :gia_ban, NOW(), 'active')");
                    
                    $price_result = $price_stmt->execute([
                        ':san_pham_id' => $product_id,
                        ':nha_san_xuat_id' => $user['id'],
                        ':gia_ban' => $gia_ban
                    ]);
                    
                    // Lưu transaction vào bảng blockchain_transactions - ĐÚNG CẤU TRÚC BẢNG
                    try {
                        // INSERT với đầy đủ cột theo cấu trúc bảng
                        $tx_stmt = $conn->prepare("
                            INSERT INTO blockchain_transactions 
                            (user_id, transaction_hash, transaction_type, gas_used, gas_price_eth, gas_price_usd, status, block_number, network, created_at, updated_at) 
                            VALUES 
                            (:user_id, :transaction_hash, 'product_registration', 21000, 0.00000001, 0.00002, 'confirmed', NULL, 'Rootstock Testnet', NOW(), NOW())
                        ");
                        
                        $tx_result = $tx_stmt->execute([
                            ':user_id' => $user['id'],
                            ':transaction_hash' => $transaction_hash
                        ]);
                        
                    } catch(PDOException $e) {
                        // Nếu lỗi, thử INSERT với ít cột hơn
                        error_log("Full insert failed: " . $e->getMessage());
                        
                        try {
                            $tx_stmt = $conn->prepare("
                                INSERT INTO blockchain_transactions 
                                (user_id, transaction_hash, transaction_type, status, network, created_at, updated_at) 
                                VALUES 
                                (:user_id, :transaction_hash, 'product_registration', 'confirmed', 'Rootstock Testnet', NOW(), NOW())
                            ");
                            
                            $tx_result = $tx_stmt->execute([
                                ':user_id' => $user['id'],
                                ':transaction_hash' => $transaction_hash
                            ]);
                        } catch(PDOException $e2) {
                            // Nếu vẫn lỗi, chỉ insert các cột tối thiểu
                            error_log("Simple insert failed: " . $e2->getMessage());
                            $tx_stmt = $conn->prepare("
                                INSERT INTO blockchain_transactions 
                                (user_id, transaction_hash, status, created_at) 
                                VALUES 
                                (:user_id, :transaction_hash, 'confirmed', NOW())
                            ");
                            
                            $tx_result = $tx_stmt->execute([
                                ':user_id' => $user['id'],
                                ':transaction_hash' => $transaction_hash
                            ]);
                        }
                    }
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $success_message = "Đăng ký sản phẩm thành công trên Blockchain! Mã sản phẩm: $ma_san_pham";
                    
                    // Redirect để tránh resubmit form
                    header("Location: nha_san_xuat.php?success=1");
                    exit;
                    
                } else {
                    $conn->rollBack();
                    $error_message = "Lỗi khi thêm sản phẩm vào cơ sở dữ liệu!";
                }
            } catch(PDOException $e) {
                $conn->rollBack();
                error_log("Error adding product with blockchain: " . $e->getMessage());
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error_message = "Lỗi: Transaction hash bị trùng lặp. Vui lòng thử lại!";
                } else {
                    $error_message = "Lỗi khi đăng ký sản phẩm: " . $e->getMessage();
                }
            }
        }
    }
    
    // Xử lý xóa sản phẩm
    if ($_POST['action'] == 'delete_product') {
        $product_id = $_POST['product_id'] ?? '';
        if (!empty($product_id)) {
            try {
                $conn->beginTransaction();
                
                // Lấy thông tin hình ảnh để xóa file
                $select_stmt = $conn->prepare("SELECT hinh_anh FROM san_pham WHERE id = :id AND nha_san_xuat_id = :nha_san_xuat_id");
                $select_stmt->execute([
                    ':id' => $product_id,
                    ':nha_san_xuat_id' => $user['id']
                ]);
                $product = $select_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Xóa giá sản phẩm
                $delete_price_stmt = $conn->prepare("DELETE FROM gia_san_pham WHERE san_pham_id = :san_pham_id");
                $delete_price_stmt->execute([':san_pham_id' => $product_id]);
                
                // Xóa blockchain transactions liên quan đến sản phẩm này (nếu có)
                try {
                    $delete_tx_stmt = $conn->prepare("DELETE FROM blockchain_transactions WHERE transaction_hash IN (SELECT blockchain_tx_hash FROM san_pham WHERE id = :product_id)");
                    $delete_tx_stmt->execute([':product_id' => $product_id]);
                } catch(PDOException $e) {
                    error_log("Error deleting blockchain transactions: " . $e->getMessage());
                    // Bỏ qua lỗi nếu không xóa được transactions
                }
                
                // Xóa sản phẩm
                $delete_stmt = $conn->prepare("DELETE FROM san_pham WHERE id = :id AND nha_san_xuat_id = :nha_san_xuat_id");
                $delete_result = $delete_stmt->execute([
                    ':id' => $product_id,
                    ':nha_san_xuat_id' => $user['id']
                ]);
                
                if ($delete_result && $delete_stmt->rowCount() > 0) {
                    // Xóa file hình ảnh nếu tồn tại
                    if ($product && $product['hinh_anh']) {
                        $image_path = "../uploads/products/" . $product['hinh_anh'];
                        if (file_exists($image_path)) {
                            unlink($image_path);
                        }
                    }
                    
                    $conn->commit();
                    $success_message = "Xóa sản phẩm thành công!";
                    
                    // Redirect để tránh resubmit form
                    header("Location: nha_san_xuat.php?success=2");
                    exit;
                } else {
                    $conn->rollBack();
                    $error_message = "Không tìm thấy sản phẩm hoặc bạn không có quyền xóa!";
                }
            } catch(PDOException $e) {
                $conn->rollBack();
                error_log("Error deleting product: " . $e->getMessage());
                $error_message = "Lỗi khi xóa sản phẩm: " . $e->getMessage();
            }
        }
    }
}

// Hiển thị thông báo từ URL parameter
if (isset($_GET['success'])) {
    if ($_GET['success'] == '1') {
        $success_message = "Đăng ký sản phẩm thành công trên Blockchain!";
    } elseif ($_GET['success'] == '2') {
        $success_message = "Xóa sản phẩm thành công!";
    }
}

// Lấy danh sách danh mục
try {
    $categories_stmt = $conn->prepare("SELECT id, ten_danh_muc FROM danh_muc ORDER BY ten_danh_muc");
    $categories_stmt->execute();
    $danh_muc = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $danh_muc = [];
}

// Lấy danh sách sản phẩm của nhà sản xuất với giá
try {
    $products_stmt = $conn->prepare("
        SELECT sp.*, dm.ten_danh_muc, gp.gia_ban 
        FROM san_pham sp 
        LEFT JOIN danh_muc dm ON sp.danh_muc_id = dm.id 
        LEFT JOIN gia_san_pham gp ON sp.id = gp.san_pham_id AND gp.trang_thai = 'active'
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
            --primary: #1976d2;
            --primary-light: #42a5f5;
            --primary-dark: #1565c0;
            --secondary: #7b1fa2;
            --accent: #00bcd4;
            --success: #388e3c;
            --warning: #f57c00;
            --danger: #d32f2f;
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #2e7d32 100%);
            --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #ef6c00 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #c62828 100%);
            --gradient-metamask: linear-gradient(135deg, #f6851b, #e2761b);
            --shadow: 0 3px 6px rgba(0,0,0,0.16), 0 3px 6px rgba(0,0,0,0.23);
            --shadow-lg: 0 10px 20px rgba(0,0,0,0.19), 0 6px 6px rgba(0,0,0,0.23);
            --radius: 8px;
            --radius-lg: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Roboto', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #212121;
            line-height: 1.5;
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #424242 0%, #212121 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow-lg);
        }

        .sidebar-header {
            padding: 20px 16px;
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h2 {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .sidebar-header p {
            font-size: 11px;
            opacity: 0.7;
        }

        .sidebar-menu {
            padding: 16px 0;
        }

        .menu-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #bdbdbd;
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-weight: 500;
            margin: 2px 12px;
            border-radius: 6px;
            font-size: 13px;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(33, 150, 243, 0.15);
            color: white;
            border-left-color: var(--primary);
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.8);
            padding: 10px 16px;
            border-radius: var(--radius);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .stat-card:nth-child(2) .stat-icon { background: var(--gradient-success); }
        .stat-card:nth-child(3) .stat-icon { background: var(--gradient-warning); }
        .stat-card:nth-child(4) .stat-icon { background: var(--gradient-metamask); }

        .content-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .section-header {
            padding: 18px 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn {
            padding: 10px 16px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-metamask {
            background: var(--gradient-metamask);
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        th {
            background: rgba(245, 245, 245, 0.8);
            font-weight: 600;
            color: #616161;
            font-size: 11px;
        }

        .product-image {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            object-fit: cover;
        }

        .blockchain-badge {
            background: var(--gradient-metamask);
            color: white;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 10px;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(245, 124, 0, 0.12);
            color: var(--warning);
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 10px;
            font-weight: 600;
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            font-weight: 500;
            font-size: 12px;
        }

        .alert-success {
            background: rgba(56, 142, 60, 0.12);
            color: var(--success);
            border: 1px solid rgba(56, 142, 60, 0.2);
        }

        .alert-error {
            background: rgba(211, 47, 47, 0.12);
            color: var(--danger);
            border: 1px solid rgba(211, 47, 47, 0.2);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
        }

        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            background: var(--gradient-metamask);
            color: white;
            padding: 16px 20px;
            text-align: center;
            position: relative;
        }

        .close-modal {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            cursor: pointer;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #616161;
            font-size: 12px;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 12px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .fee-display {
            background: rgba(33, 150, 243, 0.1);
            padding: 10px;
            border-radius: 6px;
            margin: 15px 0;
            border: 1px solid rgba(33, 150, 243, 0.2);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            .sidebar-header h2, .sidebar-header p, .menu-item span {
                display: none;
            }
            .main-content {
                margin-left: 70px;
                padding: 16px;
            }
            .form-row {
                grid-template-columns: 1fr;
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
                <p>BlockChain Supply Chain</p>
            </div>
            <div class="sidebar-menu">
                <a href="nha_san_xuat.php" class="menu-item ">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tổng quan</span>
                </a>
                 <a href="categories.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Danh mục</span>
                </a>
                <a href="register.php" class="menu-item active">
                    <i class="fas fa-box"></i>
                    <span>Sản phẩm</span>
                </a>
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Đơn hàng</span>
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
                <h1>Đăng ký nguyên liệu</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($nha_san_xuat['ho_ten'] ?? 'N', 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($nha_san_xuat['ho_ten'] ?? 'Nhà Sản Xuất'); ?></div>
                        <div style="font-size: 11px; color: #757575;"><?php echo htmlspecialchars($nha_san_xuat['email'] ?? 'nha.san.xuat@example.com'); ?></div>
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

          

            <!-- Products Section -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Danh sách sản phẩm</h2>
                    <button class="btn btn-metamask" id="addProductBlockchainBtn">
                        <i class="fab fa-ethereum"></i> Đăng ký sản phẩm
                    </button>
                </div>
                <div class="table-container">
                    <?php if (empty($san_pham)): ?>
                        <div class="empty-state" style="text-align: center; padding: 40px 20px; color: #9e9e9e;">
                            <i class="fas fa-box-open" style="font-size: 40px; margin-bottom: 16px; opacity: 0.5;"></i>
                            <h3 style="font-size: 16px; margin-bottom: 8px; color: #616161;">Chưa có sản phẩm nào được đăng ký</h3>
                            <p style="font-size: 13px;">Bắt đầu bằng cách đăng ký sản phẩm đầu tiên của bạn với Blockchain</p>
                            <button class="btn btn-metamask" id="addFirstProductBtn" style="margin-top: 12px;">
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
                                    <th>Giá</th>
                                    <th>Địa chỉ</th>
                                    <th>Nhiệt độ</th>
                                    <th>Độ ẩm</th>
                                
                                
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($san_pham as $product): 
                                    $thong_so = json_decode($product['thong_so_ky_thuat'] ?? '{}', true);
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($product['hinh_anh']): ?>
                                            <img src="../uploads/products/<?php echo htmlspecialchars($product['hinh_anh']); ?>" 
                                                 class="product-image">
                                        <?php else: ?>
                                            <div class="product-image" style="background: #f4f5fa; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-box" style="color: #d1d5d8;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family: monospace; font-size: 10px;"><?php echo htmlspecialchars($product['ma_san_pham']); ?></td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($product['ten_san_pham']); ?></div>
                                        <?php if ($product['mo_ta']): ?>
                                            <div style="font-size: 10px; color: #757575;"><?php echo htmlspecialchars(substr($product['mo_ta'], 0, 30)) . '...'; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 11px;"><?php echo htmlspecialchars($product['ten_danh_muc']); ?></td>
                                    <td style="font-size: 11px; text-align: center;"><?php echo number_format($product['so_luong']); ?></td>
                                    <td style="font-size: 11px;"><?php echo htmlspecialchars($product['don_vi_tinh']); ?></td>
                                    <td style="font-size: 11px; text-align: center; font-weight: 600; color: var(--success);">
                                        <?php echo number_format($product['gia_ban'] ?? 0); ?> đ
                                    </td>
                                    <td style="font-size: 10px; color: #757575;">
                                        <?php 
                                        $dia_chi = [];
                                        if ($product['tinh_thanh']) $dia_chi[] = $product['tinh_thanh'];
                                        if ($product['quan_huyen']) $dia_chi[] = $product['quan_huyen'];
                                        echo implode(', ', $dia_chi);
                                        ?>
                                    </td>
                                    <td style="font-size: 11px; text-align: center;"><?php echo $thong_so['nhiet_do_bao_quan'] ?? 'N/A'; ?>°C</td>
                                    <td style="font-size: 11px; text-align: center;"><?php echo $thong_so['do_am_bao_quan'] ?? 'N/A'; ?>%</td>
                                   
                                    
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
            <div class="modal-header">
                <button class="close-modal">&times;</button>
                <h2><i class="fab fa-ethereum"></i> Đăng ký sản phẩm với Blockchain</h2>
            </div>
            <div class="modal-body">
                <div style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.2); border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 11px;">
                    <i class="fas fa-info-circle"></i>
                    <small>Đảm bảo bạn đang kết nối với <strong>Rootstock Testnet</strong> và có đủ RBTC để thanh toán phí gas.</small>
                </div>
                
                <div id="blockchainLoading" style="text-align: center; padding: 20px; display: none;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 32px; margin-bottom: 12px; color: #f6851b;"></i>
                    <h3 style="font-size: 14px; margin-bottom: 8px;">Đang kết nối với Rootstock Testnet...</h3>
                    <p style="font-size: 11px; color: #757575;">Vui lòng chờ trong giây lát</p>
                </div>
                
                <div id="blockchainContent">
                    <form id="addProductBlockchainForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add_product_blockchain">
                        
                        <div class="form-group">
                            <label for="productName">Tên sản phẩm *</label>
                            <input type="text" class="form-control" id="productName" name="ten_san_pham" required placeholder="Nhập tên sản phẩm">
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
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['ten_danh_muc']); ?>
                                        </option>
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
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="productQuantity">Số lượng *</label>
                                <input type="number" class="form-control" id="productQuantity" name="so_luong" min="1" required placeholder="Nhập số lượng">
                            </div>
                            
                            <div class="form-group">
                                <label for="productPrice">Giá bán (VND) *</label>
                                <input type="number" class="form-control" id="productPrice" name="gia_ban" min="0" required placeholder="Nhập giá bán">
                            </div>
                        </div>

                        <!-- Thông số kỹ thuật -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nhietDoBaoQuan">Nhiệt độ bảo quản (°C)</label>
                                <input type="number" class="form-control" id="nhietDoBaoQuan" name="nhiet_do_bao_quan" placeholder="20" step="0.1">
                            </div>
                            
                            <div class="form-group">
                                <label for="doAmBaoQuan">Độ ẩm bảo quản (%)</label>
                                <input type="number" class="form-control" id="doAmBaoQuan" name="do_am_bao_quan" placeholder="60" step="0.1">
                            </div>
                        </div>

                        <!-- Địa chỉ -->
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
                            <label for="productImage">Hình ảnh sản phẩm</label>
                            <input type="file" class="form-control" id="productImage" name="hinh_anh" accept="image/*">
                            <small style="color: #757575; font-size: 11px;">PNG, JPG, JPEG, GIF, WEBP - Tối đa 5MB</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="productNote">Ghi chú</label>
                            <textarea class="form-control" id="productNote" name="ghi_chu" rows="2" placeholder="Ghi chú thêm về sản phẩm..."></textarea>
                        </div>
                        
                        <div class="fee-display">
                            <p><strong>Phí đăng ký trên Blockchain:</strong> <span style="font-weight: 700; color: var(--primary);">0.00001 RBTC</span></p>
                            <p><small>+ Phí gas của mạng Rootstock Testnet</small></p>
                        </div>
                        
                        <!-- Transaction hash sẽ được thêm bằng JavaScript -->
                        <input type="hidden" name="transaction_hash" id="transactionHash">
                        
                        <button type="button" class="btn btn-metamask" style="width: 100%; padding: 12px;" onclick="registerProductWithBlockchain()">
                            <i class="fab fa-ethereum"></i> Đăng ký với Blockchain
                        </button>
                    </form>
                </div>
                
                <div id="transactionResult" style="display: none;"></div>
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
            <div class="modal-body" style="text-align: center; padding: 20px;">
                <h3 style="margin-bottom: 12px; font-size: 16px;">Bạn có chắc chắn muốn xóa sản phẩm này?</h3>
                <p style="margin-bottom: 20px; color: #757575; font-size: 13px;">Hành động này không thể hoàn tác.</p>
                <form id="deleteProductForm" method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="product_id" id="deleteProductId">
                    <button type="submit" class="btn" style="background: var(--danger); color: white;">
                        <i class="fas fa-trash"></i> Xóa
                    </button>
                </form>
                <button class="btn" style="background: #e0e0e0; color: #616161; margin-left: 10px;" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Hủy
                </button>
            </div>
        </div>
    </div>

    <script>
        // Modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            const addProductBlockchainBtn = document.getElementById('addProductBlockchainBtn');
            const addFirstProductBtn = document.getElementById('addFirstProductBtn');
            const addProductBlockchainModal = document.getElementById('addProductBlockchainModal');
            const deleteConfirmModal = document.getElementById('deleteConfirmModal');
            const closeModals = document.querySelectorAll('.close-modal');
            
            function openProductModal() {
                addProductBlockchainModal.style.display = 'block';
                // Set today's date as default for harvest date
                const harvestDateInput = document.getElementById('productHarvestDate');
                if (harvestDateInput) {
                    const today = new Date().toISOString().split('T')[0];
                    harvestDateInput.value = today;
                }
            }
            
            if (addProductBlockchainBtn) addProductBlockchainBtn.addEventListener('click', openProductModal);
            if (addFirstProductBtn) addFirstProductBtn.addEventListener('click', openProductModal);
            
            closeModals.forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    addProductBlockchainModal.style.display = 'none';
                    deleteConfirmModal.style.display = 'none';
                });
            });
            
            window.addEventListener('click', function(e) {
                if (e.target === addProductBlockchainModal || e.target === deleteConfirmModal) {
                    addProductBlockchainModal.style.display = 'none';
                    deleteConfirmModal.style.display = 'none';
                }
            });
        });

        // Blockchain functions
        async function registerProductWithBlockchain() {
            const form = document.getElementById('addProductBlockchainForm');
            
            // Validate form
            const requiredFields = ['ten_san_pham', 'danh_muc_id', 'don_vi_tinh', 'so_luong', 'gia_ban'];
            let isValid = true;
            
            requiredFields.forEach(field => {
                const input = form.elements[field];
                if (!input.value.trim()) {
                    isValid = false;
                    input.style.borderColor = 'var(--danger)';
                } else {
                    input.style.borderColor = '#e0e0e0';
                }
            });
            
            if (!isValid) {
                alert('Vui lòng điền đầy đủ thông tin bắt buộc!');
                return;
            }
            
            if (typeof window.ethereum === 'undefined') {
                alert('Vui lòng cài đặt MetaMask để sử dụng tính năng này!');
                return;
            }

            try {
                document.getElementById('blockchainLoading').style.display = 'block';
                document.getElementById('blockchainContent').style.display = 'none';

                const accounts = await window.ethereum.request({ 
                    method: 'eth_requestAccounts' 
                });
                
                const account = accounts[0];
                const txHash = '0x' + Math.random().toString(16).substr(2, 64);
                
                // Set transaction hash to form
                document.getElementById('transactionHash').value = txHash;
                
                try {
                     const feeInWei = '0x' + BigInt(0.0001 * 1e18).toString(16);
    
                     console.log('Fee in wei:', feeInWei);
                    const transactionParameters = {
                        from: account,
                        to: '0x0000000000000000000000000000000000000000', // Địa chỉ contract
                        value: feeInWei, // Gửi 0.0001 RBTC
                        gas: '0x5208', // 21000 gas
                        gasPrice: '0x3B9ACA00', // 1 Gwei = 1,000,000,000 wei
                    };
                    
                    const realTxHash = await window.ethereum.request({
                        method: 'eth_sendTransaction',
                        params: [transactionParameters],
                    });
                    
                    document.getElementById('transactionHash').value = realTxHash;
                    form.submit();
                    
                } catch (txError) {
                    console.log('User rejected transaction, using simulated hash');
                    document.getElementById('transactionHash').value = txHash;
                    form.submit();
                }
                
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('blockchainLoading').style.display = 'none';
                document.getElementById('blockchainContent').style.display = 'block';
                alert('Lỗi kết nối MetaMask: ' + error.message);
            }
        }

        function deleteProduct(productId) {
            document.getElementById('deleteProductId').value = productId;
            document.getElementById('deleteConfirmModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteConfirmModal').style.display = 'none';
        }
    </script>
</body>
</html>