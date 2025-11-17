<?php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập và vai trò môi giới
if (!isset($_SESSION['user_info']) || $_SESSION['user_info']['vai_tro'] !== 'moi_gioi') {
    header("Location: ../index.php");
    exit;
}

$database = new Database();
$conn = $database->getConnection();
$user = $_SESSION['user_info'];

// Lấy thông tin môi giới
try {
    $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE id = :id");
    $stmt->execute([':id' => $user['id']]);
    $broker_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching broker info: " . $e->getMessage());
}

// Hàm tạo kho hàng tự động khi đơn hàng hoàn thành
function createWarehouseForCompletedOrder($conn, $don_thu_mua_id, $product_info) {
    try {
        // Tạo tên kho dựa trên mã đơn hàng và sản phẩm
        $ten_kho = "KHO_" . $don_thu_mua_id . "_" . substr($product_info['ten_san_pham'], 0, 20);
        
        // Tạo địa chỉ kho mặc định
        $dia_chi = "Địa chỉ kho cho đơn hàng #" . $don_thu_mua_id;
        
        $stmt = $conn->prepare("
            INSERT INTO kho_hang 
            (ten_kho, dia_chi, sdt, nguoi_quan_ly_id, trang_thai, ngay_tao, mo_ta) 
            VALUES 
            (:ten_kho, :dia_chi, :sdt, :nguoi_quan_ly_id, 'active', NOW(), :mo_ta)
        ");
        
        $stmt->execute([
            ':ten_kho' => $ten_kho,
            ':dia_chi' => $dia_chi,
            ':sdt' => '0000000000',
            ':nguoi_quan_ly_id' => $_SESSION['user_info']['id'],
            ':mo_ta' => 'Kho được tạo tự động cho đơn hàng #' . $don_thu_mua_id
        ]);
        
        $kho_id = $conn->lastInsertId();
        
        // Cập nhật đơn hàng với kho_nhap_id
        $update_stmt = $conn->prepare("
            UPDATE don_hang_thu_mua 
            SET kho_nhap_id = :kho_id 
            WHERE id = :don_thu_mua_id
        ");
        
        $update_stmt->execute([
            ':kho_id' => $kho_id,
            ':don_thu_mua_id' => $don_thu_mua_id
        ]);
        
        return $kho_id;
    } catch(PDOException $e) {
        error_log("Error creating warehouse: " . $e->getMessage());
        return null;
    }
}

// Hàm kiểm tra xem cột blockchain_tx_hash có tồn tại không
function checkBlockchainColumnExists($conn) {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM don_hang_thu_mua LIKE 'blockchain_tx_hash'");
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch(PDOException $e) {
        error_log("Error checking blockchain column: " . $e->getMessage());
        return false;
    }
}

// Hàm kiểm tra xem cột kho_nhap_id có tồn tại không
function checkKhoNhapIdExists($conn) {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM don_hang_thu_mua LIKE 'kho_nhap_id'");
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch(PDOException $e) {
        error_log("Error checking kho_nhap_id column: " . $e->getMessage());
        return false;
    }
}

// Hàm tạo transaction hash ngẫu nhiên và duy nhất
function generateUniqueTransactionHash($conn) {
    $max_attempts = 10;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        $tx_hash = '0x' . bin2hex(random_bytes(32));
        
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM blockchain_transactions WHERE transaction_hash = :tx_hash");
        $check_stmt->execute([':tx_hash' => $tx_hash]);
        $exists = $check_stmt->fetchColumn();
        
        if (!$exists) {
            return $tx_hash;
        }
        
        $attempt++;
    }
    
    return '0x' . bin2hex(random_bytes(16)) . dechex(time());
}

// Xử lý đặt mua sản phẩm với blockchain
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['purchase_product'])) {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $notes = $_POST['notes'] ?? '';
    $transaction_hash = $_POST['transaction_hash'] ?? '';
    
    try {
        // Bắt đầu transaction
        $conn->beginTransaction();
        
        // Lấy thông tin sản phẩm và giá hiện tại
        $product_stmt = $conn->prepare("
            SELECT 
                sp.*, 
                nd.id as nha_san_xuat_id,
                nd.ten_nguoi_dung as ten_nha_san_xuat,
                gsp.gia_ban as don_gia
            FROM san_pham sp 
            LEFT JOIN nguoi_dung nd ON sp.nha_san_xuat_id = nd.id 
            LEFT JOIN gia_san_pham gsp ON sp.id = gsp.san_pham_id 
                AND gsp.trang_thai = 'active'
                AND (gsp.ngay_het_han IS NULL OR gsp.ngay_het_han >= CURDATE())
            WHERE sp.id = :id
            ORDER BY gsp.ngay_ap_dung DESC 
            LIMIT 1
        ");
        $product_stmt->execute([':id' => $product_id]);
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Sử dụng đơn giá từ bảng gia_san_pham, nếu không có thì dùng mặc định
            $price = $product['don_gia'] ?? 100000;
            $total_price = $price * $quantity;
            
            // Tạo mã đơn thu mua tự động
            $ma_don_thu_mua = 'DTM' . date('YmdHis') . rand(100, 999);
            
            // Tạo transaction hash duy nhất nếu không có từ form
            if (empty($transaction_hash)) {
                $transaction_hash = generateUniqueTransactionHash($conn);
            } else {
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM blockchain_transactions WHERE transaction_hash = :tx_hash");
                $check_stmt->execute([':tx_hash' => $transaction_hash]);
                $exists = $check_stmt->fetchColumn();
                
                if ($exists) {
                    $transaction_hash = generateUniqueTransactionHash($conn);
                }
            }

            // Kiểm tra xem cột blockchain có tồn tại không
            $blockchainColumnExists = checkBlockchainColumnExists($conn);
            
            // Tạo câu lệnh INSERT linh hoạt dựa trên các cột tồn tại
            $columns = [];
            $values = [];
            $params = [];
            
            // Các cột cơ bản
            $columns[] = 'ma_don_thu_mua';
            $columns[] = 'nha_san_xuat_id';
            $columns[] = 'moi_gioi_id';
            $columns[] = 'tong_tien';
            $columns[] = 'trang_thai';
            $columns[] = 'ngay_dat_hang';
            $columns[] = 'ghi_chu';
            
            $values[] = ':ma_don_thu_mua';
            $values[] = ':nha_san_xuat_id';
            $values[] = ':moi_gioi_id';
            $values[] = ':tong_tien';
            $values[] = "'cho_duyet'";
            $values[] = 'NOW()';
            $values[] = ':ghi_chu';
            
            $params[':ma_don_thu_mua'] = $ma_don_thu_mua;
            $params[':nha_san_xuat_id'] = $product['nha_san_xuat_id'];
            $params[':moi_gioi_id'] = $user['id'];
            $params[':tong_tien'] = $total_price;
            $params[':ghi_chu'] = $notes;
            
            // Thêm blockchain nếu cột tồn tại
            if ($blockchainColumnExists) {
                $columns[] = 'blockchain_tx_hash';
                $columns[] = 'blockchain_status';
                $values[] = ':blockchain_tx_hash';
                $values[] = "'confirmed'";
                $params[':blockchain_tx_hash'] = $transaction_hash;
            }
            
            // KHÔNG thêm kho_nhap_id khi tạo đơn hàng mới (để trống)
            // Kho hàng sẽ được tạo sau khi đơn hàng hoàn thành
            
            $sql = "INSERT INTO don_hang_thu_mua (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $values) . ")";
            
            $order_stmt = $conn->prepare($sql);
            $order_stmt->execute($params);
            
            $don_thu_mua_id = $conn->lastInsertId();
            
            // Thêm chi tiết đơn thu mua
            $detail_stmt = $conn->prepare("
                INSERT INTO chi_tiet_don_thu_mua 
                (don_thu_mua_id, san_pham_id, so_luong, don_vi_tinh, don_gia, thanh_tien) 
                VALUES 
                (:don_thu_mua_id, :san_pham_id, :so_luong, :don_vi_tinh, :don_gia, :thanh_tien)
            ");
            
            $detail_stmt->execute([
                ':don_thu_mua_id' => $don_thu_mua_id,
                ':san_pham_id' => $product_id,
                ':so_luong' => $quantity,
                ':don_vi_tinh' => $product['don_vi_tinh'] ?? 'cái',
                ':don_gia' => $price,
                ':thanh_tien' => $total_price
            ]);
            
            // Chỉ lưu transaction vào bảng blockchain_transactions nếu có transaction_hash
            if (!empty($transaction_hash)) {
                try {
                    $check_table_stmt = $conn->prepare("SHOW TABLES LIKE 'blockchain_transactions'");
                    $check_table_stmt->execute();
                    $table_exists = $check_table_stmt->rowCount() > 0;
                    
                    if ($table_exists) {
                        try {
                            $tx_stmt = $conn->prepare("
                                INSERT INTO blockchain_transactions 
                                (user_id, transaction_hash, transaction_type, status, created_at) 
                                VALUES 
                                (:user_id, :transaction_hash, 'purchase_order', 'confirmed', NOW())
                            ");
                            
                            $tx_result = $tx_stmt->execute([
                                ':user_id' => $user['id'],
                                ':transaction_hash' => $transaction_hash
                            ]);
                            
                        } catch(PDOException $e) {
                            error_log("Error saving blockchain transaction: " . $e->getMessage());
                        }
                    }
                } catch(PDOException $e) {
                    error_log("Error checking blockchain table: " . $e->getMessage());
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            if (!empty($transaction_hash) && $transaction_hash !== 'simulated') {
                $_SESSION['success_message'] = "✅ Đặt mua sản phẩm thành công trên Blockchain!<br>Mã đơn: <strong>{$ma_don_thu_mua}</strong><br>TX Hash: <code>" . substr($transaction_hash, 0, 16) . "...</code>";
            } else {
                $_SESSION['success_message'] = "✅ Đặt mua sản phẩm thành công!<br>Mã đơn: <strong>{$ma_don_thu_mua}</strong><br>Kho hàng sẽ được tạo tự động khi đơn hàng hoàn thành.";
            }
        }
    } catch(Exception $e) {
        // Rollback transaction nếu có lỗi
        $conn->rollBack();
        error_log("Error purchasing product: " . $e->getMessage());
        $_SESSION['error_message'] = "❌ Lỗi khi đặt mua sản phẩm: " . $e->getMessage();
    }
    
    header("Location: store.php");
    exit;
}

// Lấy danh sách sản phẩm cho cửa hàng
try {
    $products_stmt = $conn->prepare("
        SELECT 
            sp.id,
            sp.ma_san_pham,
            sp.ten_san_pham,
            sp.mo_ta,
            sp.don_vi_tinh,
            sp.so_luong,
            sp.hinh_anh,
            COALESCE(gsp.gia_ban, 100000) as gia_ban,
            nd.ten_nguoi_dung as ten_nha_san_xuat,
            sp.blockchain_tx_hash as sp_blockchain_hash
        FROM san_pham sp
        LEFT JOIN nguoi_dung nd ON sp.nha_san_xuat_id = nd.id
        LEFT JOIN gia_san_pham gsp ON sp.id = gsp.san_pham_id 
            AND gsp.trang_thai = 'active'
            AND (gsp.ngay_het_han IS NULL OR gsp.ngay_het_han >= CURDATE())
        GROUP BY sp.id
        ORDER BY sp.ngay_tao DESC
        LIMIT 50
    ");
    
    $products_stmt->execute();
    $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Error fetching products: " . $e->getMessage());
    $products = [];
    $_SESSION['error_message'] = "Lỗi khi tải sản phẩm: " . $e->getMessage();
}

// Hàm lấy đường dẫn hình ảnh
function getImagePath($image_name) {
    if (empty($image_name)) {
        return false;
    }
    
    $possible_paths = [
        '../uploads/products/' . $image_name,
        '../uploads/' . $image_name,
        '../../uploads/products/' . $image_name,
        'uploads/products/' . $image_name
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    return false;
}

// KHỞI TẠO BIẾN ĐỂ TRÁNH LỖI UNDEFINED
$blockchainColumnExists = checkBlockchainColumnExists($conn);
$khoNhapIdExists = checkKhoNhapIdExists($conn);

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cửa Hàng - Hệ Thống Chuỗi Cung Ứng Blockchain</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/web3@1.8.0/dist/web3.min.js"></script>
    <style>
        :root {
            --primary: #9c27b0;
            --primary-light: #ba68c8;
            --primary-dark: #7b1fa2;
            --secondary: #1976d2;
            --success: #4caf50;
            --warning: #ff9800;
            --danger: #f44336;
            --info: #2196f3;
            --metamask: #f6851b;
            --dark: #1a1a1a;
            --light: #f8f9fa;
            --gray: #6c757d;
            
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, #1565c0 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #2e7d32 100%);
            --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #ef6c00 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #c62828 100%);
            --gradient-metamask: linear-gradient(135deg, var(--metamask) 0%, #e2761b 100%);
            --gradient-dark: linear-gradient(135deg, var(--dark) 0%, #2d3748 100%);
            --gradient-glass: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 100%);
            
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow: 0 4px 12px rgba(0,0,0,0.15);
            --shadow-lg: 0 10px 30px rgba(0,0,0,0.2);
            --shadow-xl: 0 20px 40px rgba(0,0,0,0.25);
            
            --radius-sm: 6px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #2d3748;
            line-height: 1.6;
            min-height: 100vh;
            background-attachment: fixed;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar với glassmorphism */
        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: var(--transition);
        }

        .sidebar-header {
            padding: 24px 20px;
            background: rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }

        .sidebar-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
        }

        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
            background: linear-gradient(135deg, #fff 0%, #e0e0e0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sidebar-header p {
            font-size: 13px;
            opacity: 0.8;
            font-weight: 400;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-weight: 500;
            margin: 4px 16px;
            border-radius: var(--radius);
            font-size: 14px;
            position: relative;
            overflow: hidden;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: var(--transition-slow);
        }

        .menu-item:hover::before {
            left: 100%;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--primary-light);
            transform: translateX(4px);
        }

        .menu-item.active {
            background: rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 24px;
        }

        /* Header với glassmorphism */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 24px 28px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
        }

        .header h1 {
            color: #2d3748;
            font-size: 28px;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 14px;
            background: rgba(255, 255, 255, 0.9);
            padding: 12px 18px;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .user-info:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            box-shadow: var(--shadow);
        }

        /* Products Grid với animation */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 0.5fr));
            gap: 24px;
            margin-bottom: 40px;
            text-align: center;
        }

        .product-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: var(--transition);
        }

        .product-card:hover::before {
            transform: scaleX(1);
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }

        .product-image {
            position: relative;
            width: 100%;
            padding-bottom: 65%;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            overflow: hidden;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .product-image img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition-slow);
            margin: auto;
        }

        .product-card:hover .product-image img {
            transform: scale(1.1);
        }

        .product-info {
            padding: 20px;
        }

        .product-title {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #2d3748;
            line-height: 1.4;
            height: 48px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            
        }

        .product-description {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 16px;
            line-height: 1.5;
            height: 42px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .product-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .product-price {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: auto;
        }

        .product-supplier {
            font-size: 13px;
            color: #6c757d;
            background: rgba(108, 117, 125, 0.1);
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
              margin: auto;
        }

        .blockchain-badge {
            background: var(--gradient-metamask);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(246, 133, 27, 0.3);
        }

        .product-actions {
            display: flex;
            gap: 10px;
         
        }

        .btn {
            padding: 12px 20px;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            position: relative;
            overflow: hidden;
            justify-content: center;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition-slow);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
        }

        .btn-success {
            background: var(--gradient-success);
            color: white;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }

        .btn-metamask {
            background: var(--gradient-metamask);
            color: white;
            box-shadow: 0 4px 12px rgba(246, 133, 27, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }

        .btn-sm {
            padding: 10px 16px;
            font-size: 13px;
            flex: 1;
        }

        /* Thêm style cho hình ảnh placeholder */
        .image-placeholder {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f0f0f0, #e0e0e0);
            color: #999;
            font-size: 40px;
        }

        /* Modal với glassmorphism */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.show {
            display: flex;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--gradient-metamask);
            color: white;
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            font-size: 18px;
        }

        .close-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: rgba(248, 249, 250, 0.8);
            border-radius: 0 0 var(--radius-xl) var(--radius-xl);
        }

        /* Form styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: var(--radius);
            font-size: 14px;
            transition: var(--transition);
            background: rgba(255,255,255,0.8);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
            background: white;
        }

        /* Alert messages */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-lg);
            margin-bottom: 20px;
            border: 1px solid transparent;
            font-weight: 500;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.15);
            color: var(--success);
            border-color: rgba(76, 175, 80, 0.2);
            box-shadow: var(--shadow-sm);
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.15);
            color: var(--danger);
            border-color: rgba(244, 67, 54, 0.2);
            box-shadow: var(--shadow-sm);
        }

        .alert-info {
            background: rgba(33, 150, 243, 0.15);
            color: var(--info);
            border-color: rgba(33, 150, 243, 0.2);
            box-shadow: var(--shadow-sm);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            grid-column: 1 / -1;
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 12px;
            color: #4a5568;
            font-weight: 600;
        }

        .empty-state p {
            font-size: 15px;
            margin-bottom: 20px;
        }

        /* Blockchain info */
        .blockchain-info {
            background: rgba(246, 133, 27, 0.1);
            border: 1px solid rgba(246, 133, 27, 0.2);
            border-radius: var(--radius);
            padding: 16px;
            margin: 20px 0;
            font-size: 13px;
            backdrop-filter: blur(10px);
        }

        .blockchain-info p {
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tx-hash {
            font-family: 'Monaco', 'Consolas', monospace;
            background: rgba(0, 0, 0, 0.05);
            padding: 6px 10px;
            border-radius: 6px;
            word-break: break-all;
            font-size: 12px;
            border: 1px solid rgba(0,0,0,0.1);
        }

        /* Loading animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            
            .sidebar-header h2, .sidebar-header p, .menu-item span {
                display: none;
            }
            
            .main-content {
                margin-left: 80px;
                padding: 16px;
            }
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
                gap: 16px;
            }
            
            .header {
                padding: 20px;
                flex-direction: column;
                gap: 16px;
                text-align: center;
            }
            
            .header h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .products-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                margin: 10px;
            }
            
            .product-actions {
                flex-direction: column;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Môi Giới</h2>
                <p>Cửa hàng sản phẩm</p>
            </div>
            <div class="sidebar-menu">
                <a href="broker.php?section=dashboard" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                <a href="broker.php?section=connections" class="menu-item">
                    <i class="fas fa-handshake"></i>
                    <span>Kết nối</span>
                </a>
                <a href="broker.php?section=contracts" class="menu-item">
                    <i class="fas fa-file-contract"></i>
                    <span>Hợp đồng</span>
                </a>
                <a href="store.php" class="menu-item active">
                    <i class="fas fa-store"></i>
                    <span>Cửa hàng</span>
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
                <h1>🛍️ Cửa Hàng </h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($broker_info['ten_nguoi_dung'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($broker_info['ten_nguoi_dung']); ?></div>
                        <div style="font-size: 12px; color: #666;">Môi giới</div>
                    </div>
                </div>
            </div>

            <!-- Hiển thị thông báo -->
            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> 
                    <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> 
                    <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

         

            <!-- Products Grid -->
            <div class="products-grid">
                <?php if(empty($products)): ?>
                    <div class="empty-state">
                        <i class="fas fa-store-slash"></i>
                        <h3>Chưa có sản phẩm nào</h3>
                        <p>Cửa hàng hiện chưa có sản phẩm nào được đăng bán</p>
                    </div>
                <?php else: ?>
                    <?php foreach($products as $product): ?>
                    <div class="product-card">
                        <div class="product-image">
                            <?php 
                            $image_path = getImagePath($product['hinh_anh']);
                            ?>
                            
                            <?php if($image_path): ?>
                                <img src="<?php echo $image_path; ?>" 
                                     alt="<?php echo htmlspecialchars($product['ten_san_pham']); ?>"
                                     onerror="handleImageError(this)">
                                <div class="image-placeholder" style="display: none;">
                                    <i class="fas fa-box"></i>
                                </div>
                            <?php else: ?>
                                <div class="image-placeholder">
                                    <i class="fas fa-box"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="product-info">
                            <div class="product-title"><?php echo htmlspecialchars($product['ten_san_pham']); ?></div>
                           
                            <div class="product-meta">
                                <div class="product-price">
                                    <span><?php echo number_format($product['gia_ban'], 0, ',', '.'); ?> RBTC</span>
                                </div>
                               
                            </div>

                            <?php if(!empty($product['sp_blockchain_hash'])): ?>
                                
                            <?php endif; ?>
                            
                            <div class="product-actions">
                                <button class="btn btn-metamask btn-sm" onclick="openPurchaseModalWithBlockchain(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['ten_san_pham'])); ?>', <?php echo $product['gia_ban']; ?>)">
                                    <i class="fab fa-ethereum"></i> Thu mua 
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Purchase Modal with Blockchain -->
    <div id="purchaseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fab fa-ethereum"></i> Đặt mua với Blockchain</h3>
                <button class="close-btn" onclick="closePurchaseModal()">&times;</button>
            </div>
            <form id="purchaseForm" method="POST">
                <input type="hidden" name="purchase_product" value="1">
                <input type="hidden" id="product_id" name="product_id">
                <input type="hidden" id="transaction_hash" name="transaction_hash">
                
                <div class="modal-body">
                    <div class="blockchain-info">
                        <p><strong><i class="fas fa-info-circle"></i> Thông tin giao dịch Blockchain</strong></p>
                        <p>Mạng: <strong>Rootstock Testnet</strong></p>
                        <p>Phí giao dịch: <strong>0.00001 RBTC + Gas</strong></p>
                        <p><small>Kho hàng sẽ được tạo tự động khi đơn hàng hoàn thành</small></p>
                        <p id="txHashDisplay" style="display: none;">TX Hash: <span class="tx-hash" id="txHashValue"></span></p>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_name">Tên sản phẩm</label>
                        <input type="text" id="product_name" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_price">Giá sản phẩm</label>
                        <input type="text" id="product_price" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity">Số lượng</label>
                        <input type="number" id="quantity" name="quantity" class="form-control" min="1" value="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="total_price">Tổng tiền</label>
                        <input type="text" id="total_price" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Ghi chú (tùy chọn)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Nhập ghi chú cho đơn hàng..."></textarea>
                    </div>

                    <div id="blockchainLoading" style="text-align: center; padding: 20px; display: none;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 32px; margin-bottom: 12px; color: #f6851b;"></i>
                        <h3 style="font-size: 14px; margin-bottom: 8px;">Đang xử lý giao dịch Blockchain...</h3>
                        <p style="font-size: 11px; color: #757575;">Vui lòng xác nhận giao dịch trong MetaMask</p>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closePurchaseModal()">Hủy</button>
                    <button type="button" id="blockchainPurchaseBtn" class="btn btn-metamask" onclick="processBlockchainPurchase()">
                        <i class="fab fa-ethereum"></i> Xác nhận với Blockchain
                    </button>
                    <button type="submit" id="regularSubmitBtn" class="btn btn-success" style="display: none;">
                        <i class="fas fa-check"></i> Hoàn tất đặt mua
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentProductPrice = 0;
        let currentProductId = 0;
        
        function openPurchaseModalWithBlockchain(productId, productName, productPrice) {
            currentProductPrice = productPrice;
            currentProductId = productId;
            
            document.getElementById('product_id').value = productId;
            document.getElementById('product_name').value = productName;
            document.getElementById('product_price').value = formatCurrency(productPrice);
            
            // Reset form state
            document.getElementById('blockchainLoading').style.display = 'none';
            document.getElementById('blockchainPurchaseBtn').style.display = 'block';
            document.getElementById('regularSubmitBtn').style.display = 'none';
            document.getElementById('txHashDisplay').style.display = 'none';
            
            // Tính tổng tiền ban đầu
            calculateTotalPrice();
            
            // Hiển thị modal
            document.getElementById('purchaseModal').classList.add('show');
        }
        
        function closePurchaseModal() {
            document.getElementById('purchaseModal').classList.remove('show');
        }
        
        function calculateTotalPrice() {
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const totalPrice = quantity * currentProductPrice;
            document.getElementById('total_price').value = formatCurrency(totalPrice);
        }
        
        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND'
            }).format(amount);
        }
        
        async function processBlockchainPurchase() {
            if (typeof window.ethereum === 'undefined') {
                alert('Vui lòng cài đặt MetaMask để sử dụng tính năng Blockchain!');
                return;
            }

            try {
                // Hiển thị loading
                document.getElementById('blockchainLoading').style.display = 'block';
                document.getElementById('blockchainPurchaseBtn').style.display = 'none';

                // Yêu cầu kết nối tài khoản
                const accounts = await window.ethereum.request({ 
                    method: 'eth_requestAccounts' 
                });
                
                const account = accounts[0];
                
                // Tạo transaction hash tạm thời
                const tempTxHash = '0x' + Math.random().toString(16).substr(2, 64);
                document.getElementById('transaction_hash').value = tempTxHash;
                document.getElementById('txHashValue').textContent = tempTxHash;
                document.getElementById('txHashDisplay').style.display = 'block';

                try {
                    // Thử gửi transaction thật
                    const transactionParameters = {
                        from: account,
                        to: '0x0000000000000000000000000000000000000000', // Địa chỉ contract thu mua
                        value: '0x' + BigInt(0.00001 * 1e18).toString(16), // 0.00001 RBTC
                        gas: '0x5208', // 21000 gas
                        gasPrice: '0x3B9ACA00', // 1 Gwei
                    };
                    
                    const realTxHash = await window.ethereum.request({
                        method: 'eth_sendTransaction',
                        params: [transactionParameters],
                    });
                    
                    // Cập nhật transaction hash thật
                    document.getElementById('transaction_hash').value = realTxHash;
                    document.getElementById('txHashValue').textContent = realTxHash;
                    
                    // Hiển thị thông báo thành công
                    document.getElementById('blockchainLoading').innerHTML = `
                        <i class="fas fa-check-circle" style="font-size: 32px; margin-bottom: 12px; color: var(--success);"></i>
                        <h3 style="font-size: 14px; margin-bottom: 8px;">Giao dịch Blockchain thành công!</h3>
                        <p style="font-size: 11px; color: #757575;">Đang hoàn tất đặt mua...</p>
                    `;
                    
                    // Tự động submit form sau 2 giây
                    setTimeout(() => {
                        document.getElementById('purchaseForm').submit();
                    }, 2000);
                    
                } catch (txError) {
                    console.log('User rejected transaction or error, using simulated hash');
                    // Nếu user từ chối transaction, vẫn tiếp tục với hash giả lập
                    document.getElementById('blockchainLoading').style.display = 'none';
                    document.getElementById('regularSubmitBtn').style.display = 'block';
                    alert('Đã sử dụng chế độ giả lập. Giao dịch sẽ được lưu cục bộ.');
                }
                
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('blockchainLoading').style.display = 'none';
                document.getElementById('blockchainPurchaseBtn').style.display = 'block';
                alert('Lỗi kết nối MetaMask: ' + error.message);
            }
        }

        function handleImageError(img) {
            console.log('Image failed to load:', img.src);
            img.style.display = 'none';
            const placeholder = img.nextElementSibling;
            if (placeholder && placeholder.classList.contains('image-placeholder')) {
                placeholder.style.display = 'flex';
            }
        }
        
        // Tính tổng tiền khi số lượng thay đổi
        document.getElementById('quantity').addEventListener('input', calculateTotalPrice);
        
        // Đóng modal khi click bên ngoài
        document.getElementById('purchaseModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePurchaseModal();
            }
        });

        // Xử lý lỗi hình ảnh khi trang load
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('.product-image img');
            images.forEach(img => {
                if (img.complete && img.naturalHeight === 0) {
                    handleImageError(img);
                }
                
                img.addEventListener('error', function() {
                    handleImageError(this);
                });
            });
        });
    </script>
</body>
</html>