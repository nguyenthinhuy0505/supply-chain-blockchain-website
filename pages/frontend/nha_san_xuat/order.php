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

// Xử lý cập nhật trạng thái đơn hàng
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_order_status') {
        $order_id = $_POST['order_id'];
        $new_status = $_POST['status'];
        
        try {
            $update_stmt = $conn->prepare("
                UPDATE don_hang_thu_mua 
                SET trang_thai = :status, 
                    ngay_cap_nhat = NOW() 
                WHERE id = :order_id 
                AND nha_san_xuat_id = :nha_san_xuat_id
            ");
            
            $update_stmt->execute([
                ':status' => $new_status,
                ':order_id' => $order_id,
                ':nha_san_xuat_id' => $user['id']
            ]);
            
            if ($update_stmt->rowCount() > 0) {
                $_SESSION['success_message'] = "Cập nhật trạng thái đơn hàng thành công!";
            } else {
                $_SESSION['error_message'] = "Không tìm thấy đơn hàng hoặc bạn không có quyền cập nhật!";
            }
            
        } catch(PDOException $e) {
            error_log("Error updating order status: " . $e->getMessage());
            $_SESSION['error_message'] = "Lỗi khi cập nhật trạng thái đơn hàng: " . $e->getMessage();
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Lấy đơn hàng chờ duyệt
try {
    $pending_orders_stmt = $conn->prepare("
        SELECT 
            dhtm.*,
            mg.ten_nguoi_dung as ten_moi_gioi,
            mg.email as email_moi_gioi,
            mg.sdt as sdt_moi_gioi,
            COUNT(ctdtm.id) as so_san_pham,
            SUM(ctdtm.thanh_tien) as tong_tien_thuc
        FROM don_hang_thu_mua dhtm
        JOIN nguoi_dung mg ON dhtm.moi_gioi_id = mg.id
        LEFT JOIN chi_tiet_don_thu_mua ctdtm ON dhtm.id = ctdtm.don_thu_mua_id
        WHERE dhtm.nha_san_xuat_id = :nha_san_xuat_id 
        AND dhtm.trang_thai = 'cho_duyet'
        GROUP BY dhtm.id
        ORDER BY dhtm.ngay_dat_hang DESC
    ");
    $pending_orders_stmt->execute([':nha_san_xuat_id' => $user['id']]);
    $pending_orders = $pending_orders_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching pending orders: " . $e->getMessage());
    $pending_orders = [];
}

// Lấy đơn hàng đang gửi
try {
    $shipping_orders_stmt = $conn->prepare("
        SELECT 
            dhtm.*,
            mg.ten_nguoi_dung as ten_moi_gioi,
            mg.email as email_moi_gioi,
            mg.sdt as sdt_moi_gioi,
            COUNT(ctdtm.id) as so_san_pham,
            SUM(ctdtm.thanh_tien) as tong_tien_thuc
        FROM don_hang_thu_mua dhtm
        JOIN nguoi_dung mg ON dhtm.moi_gioi_id = mg.id
        LEFT JOIN chi_tiet_don_thu_mua ctdtm ON dhtm.id = ctdtm.don_thu_mua_id
        WHERE dhtm.nha_san_xuat_id = :nha_san_xuat_id 
        AND dhtm.trang_thai = 'dang_giao_hang'
        GROUP BY dhtm.id
        ORDER BY dhtm.ngay_cap_nhat DESC
    ");
    $shipping_orders_stmt->execute([':nha_san_xuat_id' => $user['id']]);
    $shipping_orders = $shipping_orders_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching shipping orders: " . $e->getMessage());
    $shipping_orders = [];
}

// Lấy chi tiết đơn hàng
function getOrderDetails($conn, $order_id) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                ctdtm.*,
                sp.ten_san_pham,
                sp.ma_san_pham,
                sp.don_vi_tinh,
                sp.hinh_anh
            FROM chi_tiet_don_thu_mua ctdtm
            JOIN san_pham sp ON ctdtm.san_pham_id = sp.id
            WHERE ctdtm.don_thu_mua_id = :order_id
        ");
        $stmt->execute([':order_id' => $order_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("Error fetching order details: " . $e->getMessage());
        return [];
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Đơn Hàng - Nhà Sản Xuất</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        /* Tab Navigation */
        .tabs {
            display: flex;
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .tab {
            flex: 1;
            padding: 16px 24px;
            text-align: center;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .tab:hover {
            background: rgba(33, 150, 243, 0.1);
            color: var(--primary);
        }

        .tab.active {
            color: var(--primary);
            background: rgba(33, 150, 243, 0.15);
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
        }

        .tab-badge {
            background: var(--danger);
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 11px;
            margin-left: 6px;
        }

        /* Orders Container */
        .orders-container {
            display: none;
        }

        .orders-container.active {
            display: block;
        }

        .order-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 16px;
            overflow: hidden;
            transition: var(--transition);
        }

        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .order-header {
            padding: 16px 20px;
            background: rgba(248, 249, 250, 0.8);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-info h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #333;
        }

        .order-meta {
            display: flex;
            gap: 16px;
            font-size: 12px;
            color: #666;
        }

        .order-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(255, 152, 0, 0.15);
            color: var(--warning);
        }

        .status-shipping {
            background: rgba(33, 150, 243, 0.15);
            color: var(--primary);
        }

        .order-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 16px;
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

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-success {
            background: var(--gradient-success);
            color: white;
        }

        .btn-warning {
            background: var(--gradient-warning);
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .order-details {
            padding: 20px;
        }

        .order-customer {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
            padding: 16px;
            background: rgba(248, 249, 250, 0.5);
            border-radius: var(--radius);
        }

        .customer-info h4 {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
        }

        .customer-info p {
            font-size: 12px;
            color: #666;
            margin-bottom: 2px;
        }

        .products-list {
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .product-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
        }

        .product-item:hover {
            background: rgba(33, 150, 243, 0.05);
        }

        .product-item:last-child {
            border-bottom: none;
        }

        .product-image {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            object-fit: cover;
            margin-right: 12px;
        }

        .product-info {
            flex: 1;
        }

        .product-name {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .product-sku {
            font-size: 11px;
            color: #666;
            font-family: monospace;
        }

        .product-quantity {
            font-size: 12px;
            color: #666;
            margin-right: 16px;
        }

        .product-price {
            font-size: 13px;
            font-weight: 600;
            color: var(--success);
        }

        .order-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: rgba(248, 249, 250, 0.8);
            border-top: 1px solid rgba(0,0,0,0.05);
        }

        .total-amount {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: #333;
        }

        .empty-state p {
            font-size: 14px;
            color: #666;
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
            .order-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            .order-actions {
                width: 100%;
                justify-content: flex-end;
            }
            .order-customer {
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
                <a href="nha_san_xuat.php" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tổng quan</span>
                </a>
                <a href="categories.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Danh mục</span>
                </a>
                <a href="register.php" class="menu-item">
                    <i class="fas fa-box"></i>
                    <span>Sản phẩm</span>
                </a>
                <a href="orders.php" class="menu-item active">
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
                <h1>Quản Lý Đơn Hàng</h1>
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

            <!-- Tab Navigation -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('pending')">
                    Đơn Hàng Chờ Duyệt
                    <?php if(count($pending_orders) > 0): ?>
                        <span class="tab-badge"><?php echo count($pending_orders); ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab" onclick="switchTab('shipping')">
                    Đơn Hàng Đang Giao
                    <?php if(count($shipping_orders) > 0): ?>
                        <span class="tab-badge"><?php echo count($shipping_orders); ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Pending Orders Tab -->
            <div id="pending-orders" class="orders-container active">
                <?php if(empty($pending_orders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-check"></i>
                        <h3>Không có đơn hàng chờ duyệt</h3>
                        <p>Tất cả đơn hàng đã được xử lý</p>
                    </div>
                <?php else: ?>
                    <?php foreach($pending_orders as $order): ?>
                    <?php $order_details = getOrderDetails($conn, $order['id']); ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div class="order-info">
                                <h3>Đơn hàng #<?php echo htmlspecialchars($order['ma_don_thu_mua']); ?></h3>
                                <div class="order-meta">
                                    <span><i class="far fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($order['ngay_dat_hang'])); ?></span>
                                    <span><i class="fas fa-cube"></i> <?php echo $order['so_san_pham']; ?> sản phẩm</span>
                                    <span class="order-status status-pending">Chờ duyệt</span>
                                </div>
                            </div>
                            <div class="order-actions">
                                <button class="btn btn-success" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'dang_giao_hang')">
                                    <i class="fas fa-shipping-fast"></i> Duyệt & Giao hàng
                                </button>
                                <button class="btn btn-warning" onclick="showOrderDetails(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-eye"></i> Chi tiết
                                </button>
                            </div>
                        </div>
                        
                        <div class="order-details">
                            <div class="order-customer">
                                <div class="customer-info">
                                    <h4>Thông tin môi giới</h4>
                                    <p><strong><?php echo htmlspecialchars($order['ten_moi_gioi']); ?></strong></p>
                                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($order['email_moi_gioi']); ?></p>
                                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['sdt_moi_gioi']); ?></p>
                                </div>
                                <div class="customer-info">
                                    <h4>Thông tin đơn hàng</h4>
                                    <p><i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($order['ghi_chu'] ?: 'Không có ghi chú'); ?></p>
                                </div>
                            </div>

                            <div class="products-list">
                                <?php foreach($order_details as $detail): ?>
                                <div class="product-item">
                                    <img src="../uploads/products/<?php echo htmlspecialchars($detail['hinh_anh']); ?>" 
                                         alt="<?php echo htmlspecialchars($detail['ten_san_pham']); ?>" 
                                         class="product-image"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjRjBGMEYwIi8+CjxwYXRoIGQ9Ik0xMCAxMEgzMFYzMEgxMFYxMFoiIGZpbGw9IiNEOEQ4RDgiLz4KPHBhdGggZD0iTTE2IDE2SDE4VjI0SDE2VjE2Wk0yMiAxNkgyNFYyNEgyMlYxNloiIGZpbGw9IiM5OTk5OTkiLz4KPC9zdmc+'">
                                    <div class="product-info">
                                        <div class="product-name"><?php echo htmlspecialchars($detail['ten_san_pham']); ?></div>
                                        <div class="product-sku"><?php echo htmlspecialchars($detail['ma_san_pham']); ?></div>
                                    </div>
                                    <div class="product-quantity">
                                        <?php echo number_format($detail['so_luong']); ?> <?php echo htmlspecialchars($detail['don_vi_tinh']); ?>
                                    </div>
                                    <div class="product-price">
                                        <?php echo number_format($detail['thanh_tien'], 0, ',', '.'); ?> đ
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="order-summary">
                                <div>
                                    <strong>Tổng cộng:</strong>
                                </div>
                                <div class="total-amount">
                                    <?php echo number_format($order['tong_tien_thuc'] ?: $order['tong_tien'], 0, ',', '.'); ?> đ
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Shipping Orders Tab -->
            <div id="shipping-orders" class="orders-container">
                <?php if(empty($shipping_orders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-shipping-fast"></i>
                        <h3>Không có đơn hàng đang giao</h3>
                        <p>Tất cả đơn hàng đã được giao thành công</p>
                    </div>
                <?php else: ?>
                    <?php foreach($shipping_orders as $order): ?>
                    <?php $order_details = getOrderDetails($conn, $order['id']); ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div class="order-info">
                                <h3>Đơn hàng #<?php echo htmlspecialchars($order['ma_don_thu_mua']); ?></h3>
                                <div class="order-meta">
                                    <span><i class="far fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($order['ngay_dat_hang'])); ?></span>
                                    <span><i class="fas fa-cube"></i> <?php echo $order['so_san_pham']; ?> sản phẩm</span>
                                    <span class="order-status status-shipping">Đang giao hàng</span>
                                </div>
                            </div>
                            <div class="order-actions">
                                <button class="btn btn-success" onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'hoan_thanh')">
                                    <i class="fas fa-check"></i> Hoàn thành
                                </button>
                                <button class="btn btn-warning" onclick="showOrderDetails(<?php echo $order['id']; ?>)">
                                    <i class="fas fa-eye"></i> Chi tiết
                                </button>
                            </div>
                        </div>
                        
                        <div class="order-details">
                            <div class="order-customer">
                                <div class="customer-info">
                                    <h4>Thông tin môi giới</h4>
                                    <p><strong><?php echo htmlspecialchars($order['ten_moi_gioi']); ?></strong></p>
                                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($order['email_moi_gioi']); ?></p>
                                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['sdt_moi_gioi']); ?></p>
                                </div>
                            </div>

                            <div class="products-list">
                                <?php foreach($order_details as $detail): ?>
                                <div class="product-item">
                                    <img src="../uploads/products/<?php echo htmlspecialchars($detail['hinh_anh']); ?>" 
                                         alt="<?php echo htmlspecialchars($detail['ten_san_pham']); ?>" 
                                         class="product-image"
                                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjRjBGMEYwIi8+CjxwYXRoIGQ9Ik0xMCAxMEgzMFYzMEgxMFYxMFoiIGZpbGw9IiNEOEQ4RDgiLz4KPHBhdGggZD0iTTE2IDE2SDE4VjI0SDE2VjE2Wk0yMiAxNkgyNFYyNEgyMlYxNloiIGZpbGw9IiM5OTk5OTkiLz4KPC9zdmc+'">
                                    <div class="product-info">
                                        <div class="product-name"><?php echo htmlspecialchars($detail['ten_san_pham']); ?></div>
                                        <div class="product-sku"><?php echo htmlspecialchars($detail['ma_san_pham']); ?></div>
                                    </div>
                                    <div class="product-quantity">
                                        <?php echo number_format($detail['so_luong']); ?> <?php echo htmlspecialchars($detail['don_vi_tinh']); ?>
                                    </div>
                                    <div class="product-price">
                                        <?php echo number_format($detail['thanh_tien'], 0, ',', '.'); ?> đ
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="order-summary">
                                <div>
                                    <strong>Tổng cộng:</strong>
                                </div>
                                <div class="total-amount">
                                    <?php echo number_format($order['tong_tien_thuc'] ?: $order['tong_tien'], 0, ',', '.'); ?> đ
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Chi tiết đơn hàng</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalContent">
                    <!-- Nội dung sẽ được load bằng JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal()">Đóng</button>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            // Ẩn tất cả các tab
            document.querySelectorAll('.orders-container').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Xóa active class từ tất cả các nút tab
            document.querySelectorAll('.tab').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Hiển thị tab được chọn
            document.getElementById(tabName + '-orders').classList.add('active');
            
            // Thêm active class cho nút tab được click
            event.target.classList.add('active');
        }

        // Update order status
        function updateOrderStatus(orderId, newStatus) {
            if (confirm('Bạn có chắc chắn muốn cập nhật trạng thái đơn hàng?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'update_order_status';
                form.appendChild(actionInput);
                
                const orderInput = document.createElement('input');
                orderInput.name = 'order_id';
                orderInput.value = orderId;
                form.appendChild(orderInput);
                
                const statusInput = document.createElement('input');
                statusInput.name = 'status';
                statusInput.value = newStatus;
                form.appendChild(statusInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Show order details modal
        function showOrderDetails(orderId) {
            // Ở đây bạn có thể load chi tiết đơn hàng bằng AJAX nếu cần
            // Hiện tại chỉ hiển thị modal cơ bản
            document.getElementById('orderDetailsModal').classList.add('show');
            document.getElementById('modalContent').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class="fas fa-info-circle" style="font-size: 48px; color: #1976d2; margin-bottom: 16px;"></i>
                    <h4 style="margin-bottom: 8px;">Đang tải chi tiết đơn hàng...</h4>
                    <p>Chức năng này đang được phát triển</p>
                </div>
            `;
        }

        // Close modal
        function closeModal() {
            document.getElementById('orderDetailsModal').classList.remove('show');
        }

        // Close modal when clicking outside
        document.getElementById('orderDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Auto-refresh page every 30 seconds to check for new orders
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>