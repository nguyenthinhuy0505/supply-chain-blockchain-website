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

// Xử lý kết nối giao dịch
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Xử lý tạo kết nối giữa nhà sản xuất và nhà phân phối
    if (isset($_POST['create_connection'])) {
        $producer_id = $_POST['producer_id'];
        $distributor_id = $_POST['distributor_id'];
        $product_id = $_POST['product_id'];
        $quantity = $_POST['quantity'];
        $price = $_POST['price'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            // Tạo hợp đồng kết nối
            $contract_stmt = $conn->prepare("
                INSERT INTO broker_contracts 
                (broker_id, producer_id, distributor_id, product_id, quantity, unit_price, total_price, notes, status, created_at) 
                VALUES 
                (:broker_id, :producer_id, :distributor_id, :product_id, :quantity, :price, :total_price, :notes, 'pending', NOW())
            ");
            
            $total_price = $quantity * $price;
            
            $contract_stmt->execute([
                ':broker_id' => $user['id'],
                ':producer_id' => $producer_id,
                ':distributor_id' => $distributor_id,
                ':product_id' => $product_id,
                ':quantity' => $quantity,
                ':price' => $price,
                ':total_price' => $total_price,
                ':notes' => $notes
            ]);
            
            $_SESSION['success_message'] = "Tạo kết nối thành công! Hợp đồng đang chờ xác nhận.";
        } catch(PDOException $e) {
            error_log("Error creating connection: " . $e->getMessage());
            $_SESSION['error_message'] = "Lỗi khi tạo kết nối!";
        }
        
        header("Location: broker.php");
        exit;
    }
    
    // Xử lý xác nhận hợp đồng với MetaMask
    if (isset($_POST['confirm_contract_metamask']) && isset($_POST['contract_id']) && isset($_POST['transaction_hash'])) {
        $contract_id = $_POST['contract_id'];
        $transaction_hash = $_POST['transaction_hash'];
        
        $response = ['success' => false, 'message' => ''];
        
        try {
            // Kiểm tra transaction hash
            $check_stmt = $conn->prepare("SELECT COUNT(*) FROM blockchain_transactions WHERE transaction_hash = :tx_hash");
            $check_stmt->execute([':tx_hash' => $transaction_hash]);
            $tx_exists = $check_stmt->fetchColumn();
            
            if ($tx_exists > 0) {
                $response['message'] = "Transaction hash đã tồn tại!";
            } else {
                // Cập nhật trạng thái hợp đồng
                $update_stmt = $conn->prepare("UPDATE broker_contracts SET status = 'confirmed', confirmed_at = NOW() WHERE id = :id");
                $result = $update_stmt->execute([':id' => $contract_id]);
                
                if ($result) {
                    // Lưu transaction blockchain
                    $tx_stmt = $conn->prepare("
                        INSERT INTO blockchain_transactions 
                        (user_id, transaction_hash, transaction_type, gas_used, gas_price_eth, status, network, contract_id, created_at) 
                        VALUES 
                        (:user_id, :tx_hash, 'contract_confirmation', '42000', '0.00000005', 'confirmed', 'RSK Testnet', :contract_id, NOW())
                    ");
                    $tx_stmt->execute([
                        ':user_id' => $user['id'],
                        ':tx_hash' => $transaction_hash,
                        ':contract_id' => $contract_id
                    ]);
                    
                    $response['success'] = true;
                    $response['message'] = "Xác nhận hợp đồng thành công trên Blockchain!";
                    $_SESSION['success_message'] = $response['message'];
                }
            }
        } catch(PDOException $e) {
            error_log("Error confirming contract: " . $e->getMessage());
            $response['message'] = "Lỗi khi xác nhận hợp đồng: " . $e->getMessage();
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Lấy section hiện tại
$current_section = $_GET['section'] ?? 'dashboard';

// Thống kê cho môi giới
try {
    // Thống kê hợp đồng
    $total_contracts = $conn->query("SELECT COUNT(*) FROM broker_contracts WHERE broker_id = " . $user['id'])->fetchColumn();
    $pending_contracts = $conn->query("SELECT COUNT(*) FROM broker_contracts WHERE broker_id = " . $user['id'] . " AND status = 'pending'")->fetchColumn();
    $confirmed_contracts = $conn->query("SELECT COUNT(*) FROM broker_contracts WHERE broker_id = " . $user['id'] . " AND status = 'confirmed'")->fetchColumn();
    $total_volume = $conn->query("SELECT COALESCE(SUM(total_price), 0) FROM broker_contracts WHERE broker_id = " . $user['id'] . " AND status = 'confirmed'")->fetchColumn();
    
    // Lấy danh sách nhà sản xuất
    $producers_stmt = $conn->prepare("
        SELECT * FROM nguoi_dung 
        WHERE vai_tro = 'nha_san_xuat' AND trang_thai = 'active'
        ORDER BY ten_nguoi_dung
    ");
    $producers_stmt->execute();
    $producers = $producers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Lấy danh sách nhà phân phối
    $distributors_stmt = $conn->prepare("
        SELECT * FROM nguoi_dung 
        WHERE vai_tro = 'nha_phan_phoi' AND trang_thai = 'active'
        ORDER BY ten_nguoi_dung
    ");
    $distributors_stmt->execute();
    $distributors = $distributors_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Lấy danh sách sản phẩm
    $products_stmt = $conn->prepare("
        SELECT sp.*, nd.ten_nguoi_dung as producer_name
        FROM san_pham sp
        LEFT JOIN nguoi_dung nd ON sp.nha_san_xuat_id = nd.id
        WHERE sp.trang_thai = 'active'
        ORDER BY sp.ten_san_pham
    ");
    $products_stmt->execute();
    $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Lấy hợp đồng của môi giới
    $contracts_stmt = $conn->prepare("
        SELECT bc.*, 
               p.ten_nguoi_dung as producer_name,
               d.ten_nguoi_dung as distributor_name,
               sp.ten_san_pham as product_name,
               bt.transaction_hash
        FROM broker_contracts bc
        LEFT JOIN nguoi_dung p ON bc.producer_id = p.id
        LEFT JOIN nguoi_dung d ON bc.distributor_id = d.id
        LEFT JOIN san_pham sp ON bc.product_id = sp.id
        LEFT JOIN blockchain_transactions bt ON bc.id = bt.contract_id
        WHERE bc.broker_id = :broker_id
        ORDER BY bc.created_at DESC
    ");
    $contracts_stmt->execute([':broker_id' => $user['id']]);
    $contracts = $contracts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Error fetching broker data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Môi Giới - BlockChain Supply Chain</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/web3@1.8.0/dist/web3.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #9c27b0;
            --primary-light: #ba68c8;
            --primary-dark: #7b1fa2;
            --secondary: #1976d2;
            --success: #388e3c;
            --warning: #f57c00;
            --danger: #d32f2f;
            --broker: #9c27b0;
            
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary) 0%, #1565c0 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #2e7d32 100%);
            --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #ef6c00 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #c62828 100%);
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
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
            color: #333;
            line-height: 1.5;
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar với màu chủ đạo môi giới */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, #6a1b9a 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: var(--transition);
            z-index: 1000;
            box-shadow: var(--shadow-lg);
        }

        .sidebar-header {
            padding: 20px 16px;
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
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .sidebar-header p {
            font-size: 12px;
            opacity: 0.8;
        }

        .sidebar-menu {
            padding: 16px 0;
        }

        .menu-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-weight: 500;
            margin: 2px 12px;
            border-radius: 6px;
            font-size: 14px;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--primary-light);
        }

        .menu-item.active {
            background: rgba(255, 255, 255, 0.2);
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 20px 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.3);
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
            color: #333;
            font-size: 24px;
            font-weight: 600;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.8);
            padding: 10px 16px;
            border-radius: var(--radius);
            border: 1px solid rgba(255, 255, 255, 0.4);
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
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 20px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:nth-child(2)::before { background: var(--gradient-success); }
        .stat-card:nth-child(3)::before { background: var(--gradient-warning); }
        .stat-card:nth-child(4)::before { background: var(--gradient-secondary); }

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
            box-shadow: var(--shadow);
        }

        .stat-card:nth-child(2) .stat-icon { background: var(--gradient-success); }
        .stat-card:nth-child(3) .stat-icon { background: var(--gradient-warning); }
        .stat-card:nth-child(4) .stat-icon { background: var(--gradient-secondary); }

        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
            color: #333;
        }

        .stat-info p {
            color: #666;
            font-size: 14px;
        }

        /* Content Sections */
        .content-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .section-header {
            padding: 18px 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.5);
        }

        .section-header h2 {
            color: #333;
            font-size: 18px;
            font-weight: 600;
        }

        /* Buttons */
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-success { background: var(--gradient-success); color: white; }
        .btn-warning { background: var(--gradient-warning); color: white; }
        .btn-metamask { 
            background: linear-gradient(135deg, #f6851b, #e2761b);
            color: white; 
        }

        /* Tables */
        .table-container {
            padding: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        th {
            background: rgba(245, 245, 245, 0.8);
            font-weight: 600;
            color: #555;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background: rgba(245, 124, 0, 0.12);
            color: var(--warning);
        }

        .status-confirmed {
            background: rgba(56, 142, 60, 0.12);
            color: var(--success);
        }

        /* Forms */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius);
            font-size: 14px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
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
            max-width: 600px;
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

        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            border: 1px solid transparent;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(56, 142, 60, 0.12);
            color: var(--success);
            border-color: rgba(56, 142, 60, 0.2);
        }

        .alert-error {
            background: rgba(211, 47, 47, 0.12);
            color: var(--danger);
            border-color: rgba(211, 47, 47, 0.2);
        }

        /* Blockchain badge */
        .blockchain-badge {
            background: linear-gradient(135deg, var(--warning), #ff9800);
            color: white;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Responsive */
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
            
            .stats-grid {
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
                <h2>Môi Giới</h2>
                <p>Kết nối nhà sản xuất & phân phối</p>
            </div>
            <div class="sidebar-menu">
                <a href="?section=dashboard" class="menu-item <?php echo $current_section == 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tổng quan</span>
                </a>
                <a href="?section=connections" class="menu-item <?php echo $current_section == 'connections' ? 'active' : ''; ?>">
                    <i class="fas fa-handshake"></i>
                    <span>Kết nối</span>
                </a>
                <a href="?section=contracts" class="menu-item <?php echo $current_section == 'contracts' ? 'active' : ''; ?>">
                    <i class="fas fa-file-contract"></i>
                    <span>Hợp đồng</span>
                </a>
                <a href="store.php" class="menu-item">
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
                <h1>
                    <?php 
                    switch($current_section) {
                        case 'connections': echo 'Quản lý Kết nối'; break;
                        case 'contracts': echo 'Hợp đồng Môi giới'; break;
                        default: echo 'Dashboard Môi giới';
                    }
                    ?>
                </h1>
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

            <!-- Dashboard Section -->
            <?php if($current_section == 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_contracts ?? 0; ?></h3>
                        <p>Tổng hợp đồng</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $pending_contracts ?? 0; ?></h3>
                        <p>Chờ xác nhận</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $confirmed_contracts ?? 0; ?></h3>
                        <p>Đã xác nhận</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($total_volume ?? 0, 0, ',', '.'); ?> đ</h3>
                        <p>Tổng giá trị</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Thao tác nhanh</h2>
                </div>
                <div style="padding: 20px; display: flex; gap: 16px; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="openConnectionModal()">
                        <i class="fas fa-plus"></i> Tạo kết nối mới
                    </button>
                    <a href="?section=connections" class="btn btn-success">
                        <i class="fas fa-handshake"></i> Quản lý kết nối
                    </a>
                    <a href="?section=contracts" class="btn btn-warning">
                        <i class="fas fa-file-contract"></i> Xem hợp đồng
                    </a>
                </div>
            </div>

            <!-- Recent Contracts -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Hợp đồng gần đây</h2>
                    <a href="?section=contracts" class="btn btn-sm btn-primary">Xem tất cả</a>
                </div>
                <div class="table-container">
                    <?php if(empty($contracts)): ?>
                        <div style="padding: 40px; text-align: center; color: #888;">
                            <i class="fas fa-file-contract" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                            <h3>Chưa có hợp đồng nào</h3>
                            <p>Tạo kết nối đầu tiên để bắt đầu môi giới</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Mã hợp đồng</th>
                                    <th>Nhà SX → Nhà PP</th>
                                    <th>Sản phẩm</th>
                                    <th>Số lượng</th>
                                    <th>Tổng tiền</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach(array_slice($contracts, 0, 5) as $contract): ?>
                                <tr>
                                    <td style="font-family: monospace;">#<?php echo $contract['id']; ?></td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($contract['producer_name']); ?></div>
                                        <div style="font-size: 11px; color: #666;">→ <?php echo htmlspecialchars($contract['distributor_name']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($contract['product_name']); ?></td>
                                    <td><?php echo number_format($contract['quantity']); ?></td>
                                    <td style="font-weight: 600; color: var(--primary);"><?php echo number_format($contract['total_price'], 0, ',', '.'); ?> đ</td>
                                    <td>
                                        <?php if($contract['status'] == 'confirmed'): ?>
                                            <span class="status-badge status-confirmed">
                                                <i class="fas fa-check"></i> Đã xác nhận
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">
                                                <i class="fas fa-clock"></i> Chờ xác nhận
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Connections Section -->
            <?php if($current_section == 'connections'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2>Tạo kết nối mới</h2>
                    <button class="btn btn-primary" onclick="openConnectionModal()">
                        <i class="fas fa-plus"></i> Tạo kết nối
                    </button>
                </div>
                <div style="padding: 20px;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nhà sản xuất</label>
                            <select class="form-control" id="producer_select">
                                <option value="">Chọn nhà sản xuất</option>
                                <?php foreach($producers as $producer): ?>
                                    <option value="<?php echo $producer['id']; ?>"><?php echo htmlspecialchars($producer['ten_nguoi_dung']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Nhà phân phối</label>
                            <select class="form-control" id="distributor_select">
                                <option value="">Chọn nhà phân phối</option>
                                <?php foreach($distributors as $distributor): ?>
                                    <option value="<?php echo $distributor['id']; ?>"><?php echo htmlspecialchars($distributor['ten_nguoi_dung']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Sản phẩm</label>
                        <select class="form-control" id="product_select">
                            <option value="">Chọn sản phẩm</option>
                            <?php foreach($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>" data-producer="<?php echo $product['nha_san_xuat_id']; ?>">
                                    <?php echo htmlspecialchars($product['ten_san_pham']); ?> - <?php echo number_format($product['gia'], 0, ',', '.'); ?> đ
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Contracts Section -->
            <?php if($current_section == 'contracts'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2>Hợp đồng môi giới</h2>
                    <span class="status-badge status-confirmed"><?php echo count($contracts); ?> hợp đồng</span>
                </div>
                <div class="table-container">
                    <?php if(empty($contracts)): ?>
                        <div style="padding: 40px; text-align: center; color: #888;">
                            <i class="fas fa-file-contract" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                            <h3>Chưa có hợp đồng nào</h3>
                            <p>Tạo kết nối đầu tiên để bắt đầu môi giới</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Mã HĐ</th>
                                    <th>Nhà SX → Nhà PP</th>
                                    <th>Sản phẩm</th>
                                    <th>Số lượng</th>
                                    <th>Đơn giá</th>
                                    <th>Tổng tiền</th>
                                    <th>Ngày tạo</th>
                                    <th>Trạng thái</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($contracts as $contract): ?>
                                <tr>
                                    <td style="font-family: monospace; font-size: 12px;">#<?php echo $contract['id']; ?></td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($contract['producer_name']); ?></div>
                                        <div style="font-size: 11px; color: #666;">→ <?php echo htmlspecialchars($contract['distributor_name']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($contract['product_name']); ?></td>
                                    <td><?php echo number_format($contract['quantity']); ?></td>
                                    <td><?php echo number_format($contract['unit_price'], 0, ',', '.'); ?> đ</td>
                                    <td style="font-weight: 600; color: var(--primary);"><?php echo number_format($contract['total_price'], 0, ',', '.'); ?> đ</td>
                                    <td style="font-size: 12px;"><?php echo date('d/m/Y', strtotime($contract['created_at'])); ?></td>
                                    <td>
                                        <?php if($contract['status'] == 'confirmed'): ?>
                                            <span class="status-badge status-confirmed">
                                                <i class="fas fa-check"></i> Đã xác nhận
                                            </span>
                                            <?php if($contract['transaction_hash']): ?>
                                                <div class="blockchain-badge" style="margin-top: 4px;">
                                                    <i class="fas fa-link"></i> Blockchain
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">
                                                <i class="fas fa-clock"></i> Chờ xác nhận
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($contract['status'] == 'pending'): ?>
                                            <button class="btn btn-success btn-sm" onclick="confirmContract(<?php echo $contract['id']; ?>, this)">
                                                <i class="fas fa-check"></i> Xác nhận
                                            </button>
                                        <?php else: ?>
                                            <span style="color: #888; font-size: 12px;">Đã xác nhận</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Connection Modal -->
    <div id="connectionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Tạo kết nối mới</h3>
                <button class="close-btn" onclick="closeConnectionModal()">&times;</button>
            </div>
            <form id="connectionForm" method="POST">
                <input type="hidden" name="create_connection" value="1">
                
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="producer_id">Nhà sản xuất *</label>
                            <select id="producer_id" name="producer_id" class="form-control" required>
                                <option value="">Chọn nhà sản xuất</option>
                                <?php foreach($producers as $producer): ?>
                                    <option value="<?php echo $producer['id']; ?>"><?php echo htmlspecialchars($producer['ten_nguoi_dung']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="distributor_id">Nhà phân phối *</label>
                            <select id="distributor_id" name="distributor_id" class="form-control" required>
                                <option value="">Chọn nhà phân phối</option>
                                <?php foreach($distributors as $distributor): ?>
                                    <option value="<?php echo $distributor['id']; ?>"><?php echo htmlspecialchars($distributor['ten_nguoi_dung']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_id">Sản phẩm *</label>
                        <select id="product_id" name="product_id" class="form-control" required>
                            <option value="">Chọn sản phẩm</option>
                            <?php foreach($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>" data-producer="<?php echo $product['nha_san_xuat_id']; ?>">
                                    <?php echo htmlspecialchars($product['ten_san_pham']); ?> - <?php echo number_format($product['gia'], 0, ',', '.'); ?> đ
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="quantity">Số lượng *</label>
                            <input type="number" id="quantity" name="quantity" class="form-control" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="price">Đơn giá (đ) *</label>
                            <input type="number" id="price" name="price" class="form-control" min="1" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="total_price">Tổng tiền</label>
                        <input type="text" id="total_price" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Ghi chú</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Ghi chú cho hợp đồng..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeConnectionModal()">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tạo kết nối</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Connection Modal Functions
        function openConnectionModal() {
            document.getElementById('connectionModal').classList.add('show');
        }
        
        function closeConnectionModal() {
            document.getElementById('connectionModal').classList.remove('show');
        }
        
        // Calculate total price
        function calculateTotal() {
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const price = parseInt(document.getElementById('price').value) || 0;
            const total = quantity * price;
            document.getElementById('total_price').value = total.toLocaleString('vi-VN') + ' đ';
        }
        
        // Filter products by producer
        document.getElementById('producer_id').addEventListener('change', function() {
            const producerId = this.value;
            const productSelect = document.getElementById('product_id');
            const options = productSelect.querySelectorAll('option');
            
            options.forEach(option => {
                if (option.value === '') return;
                if (option.dataset.producer === producerId) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
            
            // Reset product selection if not matching
            if (productSelect.value && productSelect.querySelector(`option[value="${productSelect.value}"]`).style.display === 'none') {
                productSelect.value = '';
            }
        });
        
        // Event listeners for calculation
        document.getElementById('quantity').addEventListener('input', calculateTotal);
        document.getElementById('price').addEventListener('input', calculateTotal);
        
        // Close modal when clicking outside
        document.getElementById('connectionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeConnectionModal();
            }
        });
        
        // MetaMask contract confirmation
        async function confirmContract(contractId, button) {
            if (!button.classList.contains('btn-loading')) {
                button.classList.add('btn-loading');
                button.disabled = true;
            }
            
            try {
                // MetaMask integration similar to previous implementation
                // ... (giữ nguyên code MetaMask từ file trước)
                
                // After successful transaction
                const txHash = "0x123..."; // Replace with actual transaction hash
                await sendContractConfirmation(contractId, txHash);
                
            } catch (error) {
                console.error('Error confirming contract:', error);
                alert('Lỗi xác nhận hợp đồng: ' + error.message);
                
                if (button) {
                    button.classList.remove('btn-loading');
                    button.disabled = false;
                }
            }
        }
        
        async function sendContractConfirmation(contractId, txHash) {
            try {
                const formData = new FormData();
                formData.append('confirm_contract_metamask', '1');
                formData.append('contract_id', contractId);
                formData.append('transaction_hash', txHash);

                const response = await fetch('broker.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();

                if (result.success) {
                    alert('✅ Xác nhận hợp đồng thành công!');
                    location.reload();
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Error sending confirmation:', error);
                throw error;
            }
        }
    </script>
</body>
</html>