<?php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập và vai trò admin
if (!isset($_SESSION['user_info']) || $_SESSION['user_info']['vai_tro'] !== 'moi_gioi') {
    header("Location: ../index.php");
    exit;
}

$database = new Database();
$conn = $database->getConnection();
$user = $_SESSION['user_info'];

// Lấy thông tin admin
try {
    $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE id = :id");
    $stmt->execute([':id' => $user['id']]);
    $admin_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching admin info: " . $e->getMessage());
}

// Xử lý kích hoạt người dùng với MetaMask
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Xử lý kích hoạt với MetaMask
    if (isset($_POST['activate_user_metamask']) && isset($_POST['user_id']) && isset($_POST['transaction_hash'])) {
        $user_id = $_POST['user_id'];
        $transaction_hash = $_POST['transaction_hash'];
        
        $response = ['success' => false, 'message' => ''];
        
        try {
            // Kiểm tra transaction hash đã tồn tại chưa
            $check_stmt = $conn->prepare("SELECT COUNT(*) FROM blockchain_transactions WHERE transaction_hash = :tx_hash");
            $check_stmt->execute([':tx_hash' => $transaction_hash]);
            $tx_exists = $check_stmt->fetchColumn();
            
            if ($tx_exists > 0) {
                $response['message'] = "Transaction hash đã tồn tại trong hệ thống!";
            } else {
                // Cập nhật database - CHỈ CẬP NHẬT trạng_thai = 'active'
                $update_stmt = $conn->prepare("UPDATE nguoi_dung SET trang_thai = 'active' WHERE id = :id");
                $result = $update_stmt->execute([':id' => $user_id]);
                
                if ($result) {
                    // Lưu transaction vào bảng blockchain_transactions
                    $tx_stmt = $conn->prepare("
                        INSERT INTO blockchain_transactions 
                        (user_id, transaction_hash, transaction_type, gas_used, gas_price_eth, status, network, created_at, updated_at) 
                        VALUES 
                        (:user_id, :tx_hash, 'user_activation', '21000', '0.00000005', 'confirmed', 'RSK Testnet', NOW(), NOW())
                    ");
                    $tx_stmt->execute([
                        ':user_id' => $user_id,
                        ':tx_hash' => $transaction_hash
                    ]);
                    
                    $response['success'] = true;
                    $response['message'] = "Kích hoạt người dùng thành công trên Blockchain!";
                    $_SESSION['success_message'] = $response['message'];
                    
                    // Log for debugging
                    error_log("User $user_id activated successfully with TX: $transaction_hash");
                } else {
                    $response['message'] = "Lỗi khi cập nhật database!";
                    error_log("Failed to update user status for user: $user_id");
                }
            }
        } catch(PDOException $e) {
            error_log("Error activating user with MetaMask: " . $e->getMessage());
            $response['message'] = "Lỗi khi kích hoạt người dùng trên Blockchain: " . $e->getMessage();
        }
        
        // Trả về JSON response
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Xử lý kích hoạt thông thường (không blockchain)
    if (isset($_POST['activate_user']) && isset($_POST['user_id'])) {
        $user_id = $_POST['user_id'];
        
        try {
            $update_stmt = $conn->prepare("UPDATE nguoi_dung SET trang_thai = 'active' WHERE id = :id");
            $update_stmt->execute([':id' => $user_id]);
            
            $_SESSION['success_message'] = "Kích hoạt người dùng thành công!";
        } catch(PDOException $e) {
            error_log("Error activating user: " . $e->getMessage());
            $_SESSION['error_message'] = "Lỗi khi kích hoạt người dùng!";
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?section=users");
        exit;
    }
    
    // Xử lý khóa người dùng thông thường
    if (isset($_POST['deactivate_user']) && isset($_POST['user_id'])) {
        $user_id = $_POST['user_id'];
        
        try {
            $update_stmt = $conn->prepare("UPDATE nguoi_dung SET trang_thai = 'inactive' WHERE id = :id");
            $update_stmt->execute([':id' => $user_id]);
            
            $_SESSION['success_message'] = "Khóa người dùng thành công!";
        } catch(PDOException $e) {
            error_log("Error deactivating user: " . $e->getMessage());
            $_SESSION['error_message'] = "Lỗi khi khóa người dùng!";
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?section=users");
        exit;
    }
}

// Lấy section hiện tại
$current_section = $_GET['section'] ?? 'dashboard';

// Thống kê tổng quan
try {
    $users_count = $conn->query("SELECT COUNT(*) FROM nguoi_dung")->fetchColumn();
    $products_count = $conn->query("SELECT COUNT(*) FROM san_pham")->fetchColumn();
    $producers_count = $conn->query("SELECT COUNT(*) FROM nguoi_dung WHERE vai_tro = 'nha_san_xuat'")->fetchColumn();
    $distributors_count = $conn->query("SELECT COUNT(*) FROM nguoi_dung WHERE vai_tro = 'nha_phan_phoi'")->fetchColumn();
    $brokers_count = $conn->query("SELECT COUNT(*) FROM nguoi_dung WHERE vai_tro = 'moi_gioi'")->fetchColumn();
    
    // Thống kê blockchain
    $blockchain_users_count = $conn->query("SELECT COUNT(DISTINCT user_id) FROM blockchain_transactions")->fetchColumn();
    
    // Thống kê người dùng theo tháng (cho biểu đồ)
    $monthly_users_stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(ngay_tao, '%Y-%m') as month,
            COUNT(*) as count
        FROM nguoi_dung 
        WHERE ngay_tao >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(ngay_tao, '%Y-%m')
        ORDER BY month ASC
    ");
    $monthly_users_stmt->execute();
    $monthly_users = $monthly_users_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Thống kê giao dịch blockchain theo tháng
    $monthly_tx_stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM blockchain_transactions 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $monthly_tx_stmt->execute();
    $monthly_tx = $monthly_tx_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Thống kê người dùng theo vai trò
    $users_by_role_stmt = $conn->prepare("
        SELECT vai_tro, COUNT(*) as count 
        FROM nguoi_dung 
        GROUP BY vai_tro
    ");
    $users_by_role_stmt->execute();
    $users_by_role = $users_by_role_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

// Lấy danh sách người dùng chưa kích hoạt
try {
    $inactive_users_stmt = $conn->prepare("
        SELECT * FROM nguoi_dung 
        WHERE trang_thai = 'inactive' 
        ORDER BY ngay_tao DESC
    ");
    $inactive_users_stmt->execute();
    $inactive_users = $inactive_users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching inactive users: " . $e->getMessage());
    $inactive_users = [];
}

// Lấy tất cả người dùng với thông tin blockchain
try {
    $all_users_stmt = $conn->prepare("
        SELECT u.*, 
               COUNT(bt.id) as blockchain_tx_count,
               MAX(bt.created_at) as last_blockchain_action,
               MAX(bt.transaction_hash) as last_tx_hash
        FROM nguoi_dung u 
        LEFT JOIN blockchain_transactions bt ON u.id = bt.user_id
        GROUP BY u.id 
        ORDER BY u.ngay_tao DESC
    ");
    $all_users_stmt->execute();
    $all_users = $all_users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching all users: " . $e->getMessage());
    $all_users = [];
}

// Lấy danh sách môi giới
try {
    $brokers_stmt = $conn->prepare("
        SELECT u.*, 
               COUNT(bt.id) as blockchain_tx_count,
               MAX(bt.created_at) as last_blockchain_action,
               MAX(bt.transaction_hash) as last_tx_hash
        FROM nguoi_dung u 
        LEFT JOIN blockchain_transactions bt ON u.id = bt.user_id
        WHERE u.vai_tro = 'moi_gioi'
        GROUP BY u.id 
        ORDER BY u.ngay_tao DESC
    ");
    $brokers_stmt->execute();
    $brokers = $brokers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching brokers: " . $e->getMessage());
    $brokers = [];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - BlockChain Supply</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/web3@1.8.0/dist/web3.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* CSS cải tiến với Material Design */
        :root {
            --primary: #1976d2;
            --primary-light: #42a5f5;
            --primary-dark: #1565c0;
            --secondary: #7b1fa2;
            --accent: #00bcd4;
            --success: #388e3c;
            --warning: #f57c00;
            --danger: #d32f2f;
            --broker: #9c27b0;
            --dark: #121212;
            --light: #fafafa;
            --surface: #ffffff;
            --on-surface: #212121;
            --on-primary: #ffffff;
            
            /* Material Design Colors */
            --md-blue: #2196f3;
            --md-indigo: #3f51b5;
            --md-purple: #9c27b0;
            --md-teal: #009688;
            --md-green: #4caf50;
            --md-orange: #ff9800;
            --md-red: #f44336;
            --md-gray-100: #f5f5f5;
            --md-gray-200: #eeeeee;
            --md-gray-300: #e0e0e0;
            --md-gray-400: #bdbdbd;
            --md-gray-500: #9e9e9e;
            --md-gray-600: #757575;
            --md-gray-700: #616161;
            --md-gray-800: #424242;
            --md-gray-900: #212121;
            
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-primary-light: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #2e7d32 100%);
            --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #ef6c00 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #c62828 100%);
            --gradient-broker: linear-gradient(135deg, var(--broker) 0%, #7b1fa2 100%);
            --gradient-purple: linear-gradient(135deg, var(--md-purple) 0%, #7b1fa2 100%);
            --gradient-dark: linear-gradient(135deg, var(--md-gray-800) 0%, var(--dark) 100%);
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            --shadow: 0 3px 6px rgba(0,0,0,0.16), 0 3px 6px rgba(0,0,0,0.23);
            --shadow-lg: 0 10px 20px rgba(0,0,0,0.19), 0 6px 6px rgba(0,0,0,0.23);
            --shadow-xl: 0 14px 28px rgba(0,0,0,0.25), 0 10px 10px rgba(0,0,0,0.22);
            
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
            color: var(--on-surface);
            line-height: 1.5;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR MATERIAL DESIGN IMPROVED */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--md-gray-900) 0%, var(--dark) 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: var(--transition);
            z-index: 1000;
            box-shadow: var(--shadow-lg);
            border-right: none;
        }

        .sidebar-header {
            padding: 20px 16px;
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
            color: white;
            letter-spacing: 0.5px;
        }

        .sidebar-header p {
            font-size: 11px;
            opacity: 0.7;
            color: var(--md-gray-300);
            font-weight: 400;
        }

        .sidebar-menu {
            padding: 16px 0;
        }

        .menu-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--md-gray-300);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-weight: 500;
            position: relative;
            overflow: hidden;
            margin: 2px 12px;
            border-radius: 6px;
            font-size: 13px;
            letter-spacing: 0.3px;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
            transition: var(--transition);
        }

        .menu-item:hover::before {
            left: 100%;
        }

        .menu-item:hover {
            background: rgba(33, 150, 243, 0.08);
            color: white;
            border-left-color: var(--primary-light);
            transform: translateX(2px);
        }

        .menu-item.active {
            background: linear-gradient(135deg, rgba(33, 150, 243, 0.15) 0%, rgba(63, 81, 181, 0.15) 100%);
            color: white;
            border-left-color: var(--primary);
            box-shadow: 0 2px 8px rgba(33, 150, 243, 0.3);
        }

        .menu-item.active::after {
            content: '';
            position: absolute;
            right: 12px;
            width: 6px;
            height: 6px;
            background: var(--primary);
            border-radius: 50%;
            box-shadow: 0 0 8px var(--primary);
        }

        .menu-item i {
            width: 18px;
            text-align: center;
            font-size: 14px;
            opacity: 0.9;
        }

        .menu-item.active i {
            color: var(--primary-light);
            opacity: 1;
        }

        .menu-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 12px 16px;
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            transition: var(--transition);
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
            color: var(--md-gray-900);
            font-size: 20px;
            font-weight: 600;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 0.5px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.8);
            padding: 10px 16px;
            border-radius: var(--radius);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: var(--shadow-sm);
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
            cursor: pointer;
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
        .stat-card:nth-child(4)::before { background: var(--gradient-broker); }
        .stat-card:nth-child(5)::before { background: var(--gradient-danger); }
        .stat-card:nth-child(6)::before { background: var(--gradient-purple); }

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
            transition: var(--transition);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.05);
        }

        .stat-card:nth-child(2) .stat-icon { background: var(--gradient-success); }
        .stat-card:nth-child(3) .stat-icon { background: var(--gradient-warning); }
        .stat-card:nth-child(4) .stat-icon { background: var(--gradient-broker); }
        .stat-card:nth-child(5) .stat-icon { background: var(--gradient-danger); }
        .stat-card:nth-child(6) .stat-icon { background: var(--gradient-purple); }

        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--md-gray-900);
        }

        .stat-info p {
            color: var(--md-gray-600);
            font-size: 13px;
            font-weight: 500;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: var(--transition);
        }

        .chart-container:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .chart-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 16px;
        }

        .chart-header h3 {
            color: var(--md-gray-900);
            font-size: 16px;
            font-weight: 600;
        }

        /* Rest of the CSS remains similar but with adjusted colors */
        .content-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: var(--transition);
        }

        .content-section:hover {
            box-shadow: var(--shadow-lg);
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
            color: var(--md-gray-900);
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
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: var(--transition);
        }

        .btn:hover::before {
            left: 100%;
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
            font-size: 11px;
            border-radius: 5px;
        }

        .btn-success { background: var(--gradient-success); color: white; }
        .btn-warning { background: var(--gradient-warning); color: white; }
        .btn-danger { background: var(--gradient-danger); color: white; }
        .btn-broker { background: var(--gradient-broker); color: white; }
        .btn-metamask { 
            background: linear-gradient(135deg, #f6851b, #e2761b);
            color: white; 
        }

        /* Loading spinner */
        .btn-loading {
            position: relative;
            color: transparent !important;
        }
        
        .btn-loading::after {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            top: 50%;
            left: 50%;
            margin-left: -7px;
            margin-top: -7px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-right-color: transparent;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Toast notification */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 16px;
            border-radius: var(--radius);
            color: white;
            font-weight: 500;
            z-index: 10000;
            box-shadow: var(--shadow-lg);
            transform: translateX(400px);
            transition: transform 0.3s ease;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 13px;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .toast-success {
            background: rgba(56, 142, 60, 0.9);
        }
        
        .toast-error {
            background: rgba(211, 47, 47, 0.9);
        }
        
        .toast-info {
            background: rgba(33, 150, 243, 0.9);
        }

        /* Table styles */
        .table-container {
            padding: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: transparent;
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
            color: var(--md-gray-700);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr {
            transition: var(--transition);
        }

        tr:hover {
            background: rgba(33, 150, 243, 0.04);
        }

        /* Status badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: rgba(56, 142, 60, 0.12);
            color: var(--success);
            border: 1px solid rgba(56, 142, 60, 0.2);
        }

        .status-pending {
            background: rgba(245, 124, 0, 0.12);
            color: var(--warning);
            border: 1px solid rgba(245, 124, 0, 0.2);
        }

        .status-inactive {
            background: rgba(158, 158, 158, 0.12);
            color: var(--md-gray-600);
            border: 1px solid rgba(158, 158, 158, 0.2);
        }

        .status-broker {
            background: rgba(156, 39, 176, 0.12);
            color: var(--broker);
            border: 1px solid rgba(156, 39, 176, 0.2);
        }

        .blockchain-badge {
            background: linear-gradient(135deg, var(--warning), var(--md-orange));
            color: white;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 10px;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            border: 1px solid transparent;
            font-weight: 500;
            backdrop-filter: blur(10px);
            font-size: 12px;
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

        /* Tabs */
        .tab-navigation {
            display: flex;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            background: rgba(255, 255, 255, 0.5);
        }

        .tab-btn {
            padding: 12px 20px;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
            color: var(--md-gray-600);
            border-bottom: 2px solid transparent;
            font-size: 12px;
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(33, 150, 243, 0.06);
        }

        .tab-btn:hover {
            background: rgba(33, 150, 243, 0.1);
        }

        .tab-content {
            display: none;
            padding: 0;
        }

        .tab-content.active {
            display: block;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--md-gray-500);
        }

        .empty-state i {
            font-size: 40px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 16px;
            margin-bottom: 8px;
            color: var(--md-gray-600);
        }

        .empty-state p {
            font-size: 13px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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
            
            .header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>BlockChain Supply Chain</h2>
                <p>Quản trị hệ thống</p>
            </div>
            <div class="sidebar-menu">
                <a href="?section=dashboard" class="menu-item <?php echo $current_section == 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tổng quan</span>
                </a>
                <a href="?section=users" class="menu-item <?php echo $current_section == 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Quản lý Người dùng</span>
                </a>
                <a href="?section=products" class="menu-item <?php echo $current_section == 'products' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i>
                    <span>Quản lý Sản phẩm</span>
                </a>
                <a href="?section=producers" class="menu-item <?php echo $current_section == 'producers' ? 'active' : ''; ?>">
                    <i class="fas fa-industry"></i>
                    <span>Nhà sản xuất</span>
                </a>
                <a href="?section=distributors" class="menu-item <?php echo $current_section == 'distributors' ? 'active' : ''; ?>">
                    <i class="fas fa-truck"></i>
                    <span>Nhà phân phối</span>
                </a>
                <a href="?section=brokers" class="menu-item <?php echo $current_section == 'brokers' ? 'active' : ''; ?>">
                    <i class="fas fa-handshake"></i>
                    <span>Môi giới</span>
                </a>
                <div class="menu-divider"></div>
                <a href="?section=blockchain" class="menu-item <?php echo $current_section == 'blockchain' ? 'active' : ''; ?>">
                    <i class="fas fa-link"></i>
                    <span>Blockchain</span>
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
                        case 'users': echo 'Quản lý Người dùng'; break;
                        case 'products': echo 'Quản lý Sản phẩm'; break;
                        case 'producers': echo 'Quản lý Nhà sản xuất'; break;
                        case 'distributors': echo 'Quản lý Nhà phân phối'; break;
                        case 'brokers': echo 'Quản lý Môi giới'; break;
                        case 'blockchain': echo 'Quản lý Blockchain'; break;
                        default: echo 'Dashboard Quản trị viên';
                    }
                    ?>
                </h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($admin_info['ten_nguoi_dung'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($admin_info['ten_nguoi_dung']); ?></div>
                        <div style="font-size: 11px; color: var(--md-gray-600);"><?php echo $admin_info['email']; ?> (Admin)</div>
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

            <!-- Dashboard Section với Biểu đồ -->
            <?php if($current_section == 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $users_count ?? 0; ?></h3>
                        <p>Tổng người dùng</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-industry"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $producers_count ?? 0; ?></h3>
                        <p>Nhà sản xuất</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $distributors_count ?? 0; ?></h3>
                        <p>Nhà phân phối</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $brokers_count ?? 0; ?></h3>
                        <p>Môi giới</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-link"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $blockchain_users_count ?? 0; ?></h3>
                        <p>Đã kích hoạt BC</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $products_count ?? 0; ?></h3>
                        <p>Sản phẩm</p>
                    </div>
                </div>
            </div>

            <!-- Biểu đồ thống kê -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3>Thống kê người dùng theo tháng</h3>
                    </div>
                    <canvas id="usersChart" height="220"></canvas>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h3>Phân bố người dùng theo vai trò</h3>
                    </div>
                    <canvas id="rolesChart" height="220"></canvas>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3>Giao dịch Blockchain theo tháng</h3>
                    </div>
                    <canvas id="blockchainChart" height="220"></canvas>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h3>Hoạt động hệ thống</h3>
                    </div>
                    <div style="padding: 16px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div style="text-align: center; padding: 12px; background: rgba(33, 150, 243, 0.08); border-radius: 8px;">
                                <div style="font-size: 20px; font-weight: 700; color: var(--primary);"><?php echo count($inactive_users); ?></div>
                                <div style="font-size: 11px; color: var(--md-gray-600);">Chờ kích hoạt</div>
                            </div>
                            <div style="text-align: center; padding: 12px; background: rgba(56, 142, 60, 0.08); border-radius: 8px;">
                                <div style="font-size: 20px; font-weight: 700; color: var(--success);"><?php echo $blockchain_users_count ?? 0; ?></div>
                                <div style="font-size: 11px; color: var(--md-gray-600);">Đã kích hoạt BC</div>
                            </div>
                        </div>
                        <div style="margin-top: 16px; padding: 12px; background: rgba(245, 124, 0, 0.08); border-radius: 8px;">
                            <div style="font-size: 13px; font-weight: 600; color: var(--warning); margin-bottom: 6px;">
                                <i class="fas fa-info-circle"></i> Thông tin hệ thống
                            </div>
                            <div style="font-size: 11px; color: var(--md-gray-600);">
                                Hệ thống đang hoạt động ổn định. Có <?php echo count($inactive_users); ?> tài khoản đang chờ kích hoạt.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Users Management Section -->
            <?php if($current_section == 'users'): ?>
            <div class="content-section">
                <div class="tab-navigation">
                    <button class="tab-btn active" onclick="switchTab('pending')">Chờ kích hoạt</button>
                    <button class="tab-btn" onclick="switchTab('all')">Tất cả người dùng</button>
                    <button class="tab-btn" onclick="switchTab('blockchain')">Đã kích hoạt BC</button>
                </div>

                <!-- Tab: Pending Users -->
                <div id="pending-tab" class="tab-content active">
                    <div class="section-header">
                        <h2>Người dùng chờ kích hoạt</h2>
                        <span class="status-badge status-warning"><?php echo count($inactive_users); ?> tài khoản</span>
                    </div>
                    <div class="table-container">
                        <?php if(empty($inactive_users)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <h3>Không có người dùng nào chờ kích hoạt</h3>
                                <p>Tất cả người dùng đã được kích hoạt</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tên người dùng</th>
                                        <th>Email</th>
                                        <th>Vai trò</th>
                                        <th>Ngày đăng ký</th>
                                        <th>Trạng thái</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($inactive_users as $user): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($user['ten_nguoi_dung']); ?></div>
                                        </td>
                                        <td style="font-size: 11px;"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <?php
                                            $role_names = [
                                                'admin' => 'Quản trị',
                                                'nha_san_xuat' => 'Nhà sản xuất',
                                                'nha_phan_phoi' => 'Nhà phân phối',
                                                'moi_gioi' => 'Môi giới'
                                            ];
                                            $role_name = $role_names[$user['vai_tro']] ?? $user['vai_tro'];
                                            ?>
                                            <span class="status-badge 
                                                <?php 
                                                switch($user['vai_tro']) {
                                                    case 'admin': echo 'status-active'; break;
                                                    case 'nha_san_xuat': echo 'status-pending'; break;
                                                    case 'nha_phan_phoi': echo 'status-inactive'; break;
                                                    case 'moi_gioi': echo 'status-broker'; break;
                                                    default: echo 'status-inactive';
                                                }
                                                ?>">
                                                <?php echo $role_name; ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 11px;"><?php echo date('d/m/Y H:i', strtotime($user['ngay_tao'])); ?></td>
                                        <td>
                                            <span class="status-badge status-inactive">Chưa kích hoạt</span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-metamask btn-sm" 
                                                        onclick="activateWithMetaMask(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['ten_nguoi_dung']); ?>', this)">
                                                    <i class="fab fa-ethereum"></i> Kích hoạt 
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

                <!-- Tab: All Users -->
                <div id="all-tab" class="tab-content">
                    <div class="section-header">
                        <h2>Tất cả người dùng</h2>
                        <span class="status-badge status-active"><?php echo count($all_users); ?> tài khoản</span>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tên người dùng</th>
                                    <th>Email</th>
                                    <th>Vai trò</th>
                                    <th>Trạng thái</th>
                                    <th>Blockchain TX</th>
                                    <th>Ngày đăng ký</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($all_users as $user): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($user['ten_nguoi_dung']); ?></div>
                                    </td>
                                    <td style="font-size: 11px;"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php
                                        $role_names = [
                                            'admin' => 'Quản trị',
                                            'nha_san_xuat' => 'Nhà sản xuất',
                                            'nha_phan_phoi' => 'Nhà phân phối',
                                            'moi_gioi' => 'Môi giới'
                                        ];
                                        $role_name = $role_names[$user['vai_tro']] ?? $user['vai_tro'];
                                        ?>
                                        <span class="status-badge 
                                            <?php 
                                            switch($user['vai_tro']) {
                                                case 'admin': echo 'status-active'; break;
                                                case 'nha_san_xuat': echo 'status-pending'; break;
                                                case 'nha_phan_phoi': echo 'status-inactive'; break;
                                                case 'moi_gioi': echo 'status-broker'; break;
                                                default: echo 'status-inactive';
                                            }
                                            ?>">
                                            <?php echo $role_name; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $user['trang_thai'] == 'active' ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $user['trang_thai'] == 'active' ? 'Đã kích hoạt' : 'Chưa kích hoạt'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if(!empty($user['last_tx_hash'])): ?>
                                            <span class="blockchain-badge">
                                                <i class="fas fa-link"></i> Đã kích hoạt BC
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--md-gray-400); font-size: 11px;">Chưa có TX</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 11px;"><?php echo date('d/m/Y H:i', strtotime($user['ngay_tao'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if($user['trang_thai'] == 'inactive'): ?>
                                                <button type="button" class="btn btn-metamask btn-sm" 
                                                        onclick="activateWithMetaMask(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['ten_nguoi_dung']); ?>', this)">
                                                    <i class="fab fa-ethereum"></i> Kích hoạt
                                                </button>
                                               
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="deactivate_user" value="1">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-ban"></i> Khóa
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: Blockchain Users -->
                <div id="blockchain-tab" class="tab-content">
                    <div class="section-header">
                        <h2>Người dùng đã kích hoạt Blockchain</h2>
                        <span class="blockchain-badge"><?php echo $blockchain_users_count ?? 0; ?> tài khoản</span>
                    </div>
                    <div class="table-container">
                        <?php 
                        $blockchain_users = array_filter($all_users, function($user) {
                            return !empty($user['last_tx_hash']);
                        });
                        ?>
                        <?php if(empty($blockchain_users)): ?>
                            <div class="empty-state">
                                <i class="fas fa-link"></i>
                                <h3>Chưa có người dùng nào kích hoạt bằng Blockchain</h3>
                                <p>Sử dụng nút "Kích hoạt MetaMask" để kích hoạt người dùng trên blockchain</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tên người dùng</th>
                                        <th>Email</th>
                                        <th>Vai trò</th>
                                        <th>Transaction Hash</th>
                                        <th>Ngày kích hoạt</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($blockchain_users as $user): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($user['ten_nguoi_dung']); ?></div>
                                        </td>
                                        <td style="font-size: 11px;"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <?php
                                            $role_names = [
                                                'admin' => 'Quản trị',
                                                'nha_san_xuat' => 'Nhà sản xuất',
                                                'nha_phan_phoi' => 'Nhà phân phối',
                                                'moi_gioi' => 'Môi giới'
                                            ];
                                            $role_name = $role_names[$user['vai_tro']] ?? $user['vai_tro'];
                                            ?>
                                            <span class="status-badge 
                                                <?php 
                                                switch($user['vai_tro']) {
                                                    case 'admin': echo 'status-active'; break;
                                                    case 'nha_san_xuat': echo 'status-pending'; break;
                                                    case 'nha_phan_phoi': echo 'status-inactive'; break;
                                                    case 'moi_gioi': echo 'status-broker'; break;
                                                    default: echo 'status-inactive';
                                                }
                                                ?>">
                                                <?php echo $role_name; ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 11px; font-family: monospace;">
                                            <?php if(!empty($user['last_tx_hash'])): ?>
                                                <span title="<?php echo $user['last_tx_hash']; ?>">
                                                    <?php echo substr($user['last_tx_hash'], 0, 18); ?>...
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 11px;">
                                            <?php 
                                            $lastTx = $user['last_blockchain_action'] ?? $user['ngay_tao'];
                                            echo date('d/m/Y H:i', strtotime($lastTx));
                                            ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="deactivate_user" value="1">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-ban"></i> Khóa
                                                    </button>
                                                </form>
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
            <?php endif; ?>

            <!-- Brokers Management Section -->
            <?php if($current_section == 'brokers'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2>Quản lý Môi giới</h2>
                    <span class="status-badge status-broker"><?php echo count($brokers); ?> tài khoản</span>
                </div>
                <div class="table-container">
                    <?php if(empty($brokers)): ?>
                        <div class="empty-state">
                            <i class="fas fa-handshake"></i>
                            <h3>Chưa có môi giới nào</h3>
                            <p>Chưa có người dùng nào đăng ký với vai trò môi giới</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Tên người dùng</th>
                                    <th>Email</th>
                                    <th>Trạng thái</th>
                                    <th>Blockchain TX</th>
                                    <th>Ngày đăng ký</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($brokers as $broker): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($broker['ten_nguoi_dung']); ?></div>
                                    </td>
                                    <td style="font-size: 11px;"><?php echo htmlspecialchars($broker['email']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $broker['trang_thai'] == 'active' ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $broker['trang_thai'] == 'active' ? 'Đã kích hoạt' : 'Chưa kích hoạt'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if(!empty($broker['last_tx_hash'])): ?>
                                            <span class="blockchain-badge">
                                                <i class="fas fa-link"></i> Đã kích hoạt BC
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--md-gray-400); font-size: 11px;">Chưa có TX</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 11px;"><?php echo date('d/m/Y H:i', strtotime($broker['ngay_tao'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if($broker['trang_thai'] == 'inactive'): ?>
                                                <button type="button" class="btn btn-metamask btn-sm" 
                                                        onclick="activateWithMetaMask(<?php echo $broker['id']; ?>, '<?php echo htmlspecialchars($broker['ten_nguoi_dung']); ?>', this)">
                                                    <i class="fab fa-ethereum"></i> Kích hoạt
                                                </button>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="deactivate_user" value="1">
                                                    <input type="hidden" name="user_id" value="<?php echo $broker['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-ban"></i> Khóa
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Other sections can be added here -->
            <?php if($current_section != 'dashboard' && $current_section != 'users' && $current_section != 'brokers'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2>Chức năng đang phát triển</h2>
                </div>
                <div class="empty-state">
                    <i class="fas fa-tools"></i>
                    <h3>Chức năng đang được phát triển</h3>
                    <p>Phần này sẽ sớm có mặt trong phiên bản tiếp theo</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <script>
        // Khởi tạo biểu đồ khi trang được tải
        document.addEventListener('DOMContentLoaded', function() {
            <?php if($current_section == 'dashboard'): ?>
            initializeCharts();
            <?php endif; ?>
        });

        // Hàm khởi tạo biểu đồ
        function initializeCharts() {
            // Biểu đồ người dùng theo tháng
            const usersCtx = document.getElementById('usersChart').getContext('2d');
            const usersChart = new Chart(usersCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_users, 'month')); ?>,
                    datasets: [{
                        label: 'Người dùng mới',
                        data: <?php echo json_encode(array_column($monthly_users, 'count')); ?>,
                        borderColor: '#1976d2',
                        backgroundColor: 'rgba(25, 118, 210, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });

            // Biểu đồ phân bố vai trò
            const rolesCtx = document.getElementById('rolesChart').getContext('2d');
            const rolesChart = new Chart(rolesCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php 
                    $role_labels = [];
                    $role_data = [];
                    foreach($users_by_role as $role) {
                        $role_names = [
                            'admin' => 'Quản trị',
                            'nha_san_xuat' => 'Nhà sản xuất',
                            'nha_phan_phoi' => 'Nhà phân phối',
                            'moi_gioi' => 'Môi giới'
                        ];
                        $role_labels[] = $role_names[$role['vai_tro']] ?? $role['vai_tro'];
                        $role_data[] = $role['count'];
                    }
                    echo json_encode($role_labels); 
                    ?>,
                    datasets: [{
                        data: <?php echo json_encode($role_data); ?>,
                        backgroundColor: [
                            '#1976d2',
                            '#388e3c',
                            '#f57c00',
                            '#9c27b0'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Biểu đồ giao dịch blockchain
            const blockchainCtx = document.getElementById('blockchainChart').getContext('2d');
            const blockchainChart = new Chart(blockchainCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_tx, 'month')); ?>,
                    datasets: [{
                        label: 'Giao dịch BC',
                        data: <?php echo json_encode(array_column($monthly_tx, 'count')); ?>,
                        backgroundColor: 'rgba(245, 124, 0, 0.8)',
                        borderColor: '#f57c00',
                        borderWidth: 2,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // Các hàm xử lý MetaMask và tab
        let currentUserId = null;
        let currentUserName = '';
        let currentButton = null;

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast toast-${type} show`;
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }

        async function checkAndConnectRSKTestnet() {
            if (typeof window.ethereum === 'undefined') {
                throw new Error('MetaMask không được cài đặt');
            }

            try {
                const chainId = await window.ethereum.request({ method: 'eth_chainId' });
                if (chainId === '0x1f') {
                    return true;
                } else {
                    return await switchToRSKTestnet();
                }
            } catch (error) {
                console.error('Error checking network:', error);
                return false;
            }
        }

        async function switchToRSKTestnet() {
            try {
                await window.ethereum.request({
                    method: 'wallet_switchEthereumChain',
                    params: [{ chainId: '0x1f' }],
                });
                return true;
            } catch (switchError) {
                if (switchError.code === 4902) {
                    try {
                        await window.ethereum.request({
                            method: 'wallet_addEthereumChain',
                            params: [{
                                chainId: '0x1f',
                                chainName: 'Rootstock Testnet',
                                nativeCurrency: {
                                    name: 'Rootstock Bitcoin',
                                    symbol: 'RBTC',
                                    decimals: 18
                                },
                                rpcUrls: ['https://public-node.testnet.rsk.co'],
                                blockExplorerUrls: ['https://explorer.testnet.rsk.co/'],
                                iconUrls: ['https://developers.rsk.co/assets/img/favicon.ico']
                            }],
                        });
                        return true;
                    } catch (addError) {
                        console.error('Error adding RSK Testnet:', addError);
                        throw new Error('Không thể thêm RSK Testnet vào MetaMask');
                    }
                }
                console.error('Error switching to RSK Testnet:', switchError);
                throw new Error('Không thể chuyển sang RSK Testnet');
            }
        }

        async function activateWithMetaMask(userId, userName, button) {
            currentUserId = userId;
            currentUserName = userName;
            currentButton = button;
            
            if (button) {
                button.classList.add('btn-loading');
                button.disabled = true;
            }
            
            try {
                showToast('Đang kết nối Rootstock Testnet...', 'info');
                const connected = await checkAndConnectRSKTestnet();
                if (!connected) {
                    throw new Error('Không thể kết nối với RSK Testnet');
                }
                
                const accounts = await window.ethereum.request({
                    method: 'eth_requestAccounts'
                });
                
                if (accounts.length === 0) {
                    throw new Error('Không tìm thấy tài khoản MetaMask');
                }
                
                const account = accounts[0];
                const web3 = new Web3(window.ethereum);
                
                const balance = await web3.eth.getBalance(account);
                const balanceInEther = web3.utils.fromWei(balance, 'ether');
                
                const activationFee = '0.000001';
                const gasLimit = 21000;
                const gasPrice = await web3.eth.getGasPrice();
                const gasCostWei = web3.utils.toBN(gasPrice).mul(web3.utils.toBN(gasLimit));
                const gasCost = web3.utils.fromWei(gasCostWei, 'ether');
                const totalCost = parseFloat(activationFee) + parseFloat(gasCost);
                
                if (parseFloat(balanceInEther) < totalCost) {
                    throw new Error(`Số dư không đủ. Cần ${totalCost.toFixed(8)} RBTC`);
                }
                
                showToast('Đang mở MetaMask...', 'info');
                
                const transactionParameters = {
                    from: account,
                    to: account,
                    value: web3.utils.toWei(activationFee, 'ether'),
                    gas: web3.utils.toHex(gasLimit),
                    gasPrice: gasPrice,
                    data: '0x'
                };

                const txHash = await window.ethereum.request({
                    method: 'eth_sendTransaction',
                    params: [transactionParameters],
                });
                
                showToast('Đang chờ xác nhận transaction...', 'info');
                const receipt = await waitForTransactionConfirmation(txHash);
                
                if (receipt && receipt.status) {
                    await sendTransactionToServer(txHash);
                } else {
                    throw new Error('Transaction thất bại trên blockchain');
                }
                
            } catch (error) {
                console.error('Error in activateWithMetaMask:', error);
                
                let errorMessage = 'Lỗi: ';
                if (error.code === 4001) {
                    errorMessage = 'Bạn đã hủy transaction';
                } else if (error.message.includes('insufficient funds')) {
                    errorMessage = 'Số dư không đủ để thực hiện giao dịch';
                } else {
                    errorMessage += error.message;
                }
                
                showToast(errorMessage, 'error');
                
                if (currentButton) {
                    currentButton.classList.remove('btn-loading');
                    currentButton.disabled = false;
                }
            }
        }

        async function waitForTransactionConfirmation(txHash) {
            const web3 = new Web3(window.ethereum);
            let transactionReceipt = null;
            let attempts = 0;
            const maxAttempts = 30;
            
            while (transactionReceipt === null && attempts < maxAttempts) {
                try {
                    transactionReceipt = await web3.eth.getTransactionReceipt(txHash);
                    
                    if (transactionReceipt === null) {
                        attempts++;
                        showToast(`Đang chờ xác nhận... (${attempts}/${maxAttempts})`, 'info');
                        await new Promise(resolve => setTimeout(resolve, 6000));
                    }
                } catch (error) {
                    console.error('Error checking transaction receipt:', error);
                    attempts++;
                    await new Promise(resolve => setTimeout(resolve, 6000));
                }
            }
            
            return transactionReceipt;
        }

        async function sendTransactionToServer(txHash) {
            try {
                const formData = new FormData();
                formData.append('activate_user_metamask', '1');
                formData.append('user_id', currentUserId);
                formData.append('transaction_hash', txHash);

                const response = await fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();

                if (result.success) {
                    showToast(`✅ Kích hoạt thành công! ${currentUserName} đã được kích hoạt.`, 'success');
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                    
                } else {
                    throw new Error(result.message || 'Lỗi không xác định từ server');
                }
            } catch (error) {
                console.error('Error sending to server:', error);
                showToast(`⚠️ Transaction thành công nhưng lỗi server: ${error.message}`, 'error');
            }
        }

        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        if (typeof window.ethereum !== 'undefined') {
            window.ethereum.on('chainChanged', (chainId) => {
                console.log('Chain changed:', chainId);
            });

            window.ethereum.on('accountsChanged', (accounts) => {
                console.log('Accounts changed:', accounts);
            });
        }
    </script>
</body>
</html>