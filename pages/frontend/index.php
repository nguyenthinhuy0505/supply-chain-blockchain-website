<?php
session_start();
require_once 'db.php';

// Khởi tạo kết nối database
$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("Lỗi kết nối database. Vui lòng thử lại sau.");
}

// Hàm lấy URL dashboard theo vai trò
function getDashboardUrl($vai_tro) {
    switch($vai_tro) {
        case 'admin':
            return 'admin/admin.php';
        case 'nha_san_xuat':
            return 'nha_san_xuat/nha_san_xuat.php';
        case 'moi_gioi':
            return 'moi_gioi/moi_gioi.php';
        case 'van_chuyen':
            return 'van_chuyen/van_chuyen.php';
        case 'khach_hang':
            return 'khach_hang/khach_hang.php';
        default:
            return 'index.php';
    }
}

// Xử lý đăng nhập
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $vai_tro = $_POST['vai_tro'] ?? '';
    
    if (empty($email) || empty($password) || empty($vai_tro)) {
        $login_error = "Vui lòng điền đầy đủ thông tin!";
    } else {
        try {
            $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE email = :email AND vai_tro = :vai_tro AND trang_thai = 'active'");
            $stmt->execute([
                ':email' => $email,
                ':vai_tro' => $vai_tro
            ]);
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_info'] = $user;
                    
                    // Cập nhật last_login
                    $update_stmt = $conn->prepare("UPDATE nguoi_dung SET last_login = NOW() WHERE id = :id");
                    $update_stmt->execute([':id' => $user['id']]);
                    
                    // Redirect đến dashboard tương ứng
                    $redirect_url = getDashboardUrl($user['vai_tro']);
                    header("Location: " . $redirect_url);
                    exit;
                } else {
                    $login_error = "Mật khẩu không đúng!";
                }
            } else {
                $login_error = "Email không tồn tại hoặc không đúng vai trò!";
            }
        } catch(PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $login_error = "Lỗi hệ thống! Vui lòng thử lại sau.";
        }
    }
}

// Xử lý đăng ký
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'register') {
    $ten_nguoi_dung = trim($_POST['ten_nguoi_dung'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $so_dien_thoai = trim($_POST['so_dien_thoai'] ?? '');
    $vai_tro = $_POST['vai_tro'] ?? '';
    $dia_chi_vi = trim($_POST['dia_chi_vi'] ?? '');
    
    $register_error = '';
    
    // Validation
    if (empty($ten_nguoi_dung)) {
        $register_error = "Vui lòng nhập họ và tên!";
    } elseif (strlen($ten_nguoi_dung) < 2) {
        $register_error = "Họ và tên phải có ít nhất 2 ký tự!";
    } elseif (empty($email)) {
        $register_error = "Vui lòng nhập email!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = "Email không hợp lệ!";
    } elseif (empty($password)) {
        $register_error = "Vui lòng nhập mật khẩu!";
    } elseif (strlen($password) < 6) {
        $register_error = "Mật khẩu phải có ít nhất 6 ký tự!";
    } elseif ($password !== $confirm_password) {
        $register_error = "Mật khẩu xác nhận không khớp!";
    } elseif (empty($so_dien_thoai)) {
        $register_error = "Vui lòng nhập số điện thoại!";
    } elseif (!preg_match('/^[0-9]{10,11}$/', $so_dien_thoai)) {
        $register_error = "Số điện thoại không hợp lệ!";
    } elseif (empty($vai_tro)) {
        $register_error = "Vui lòng chọn vai trò!";
    } elseif (empty($dia_chi_vi)) {
        $register_error = "Vui lòng kết nối MetaMask để lấy địa chỉ ví!";
    } elseif (!preg_match('/^0x[a-fA-F0-9]{40}$/', $dia_chi_vi)) {
        $register_error = "Địa chỉ ví không hợp lệ!";
    }
    
    if (empty($register_error)) {
        try {
            // Kiểm tra email tồn tại
            $check_stmt = $conn->prepare("SELECT id FROM nguoi_dung WHERE email = :email");
            $check_stmt->execute([':email' => $email]);
            
            if ($check_stmt->rowCount() > 0) {
                $register_error = "Email đã tồn tại trong hệ thống!";
            } else {
                // Kiểm tra ví tồn tại
                $check_wallet_stmt = $conn->prepare("SELECT id FROM nguoi_dung WHERE dia_chi_vi = :dia_chi_vi");
                $check_wallet_stmt->execute([':dia_chi_vi' => $dia_chi_vi]);
                
                if ($check_wallet_stmt->rowCount() > 0) {
                    $register_error = "Địa chỉ ví đã được sử dụng!";
                } else {
                    // Mã hóa mật khẩu
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Thêm người dùng mới
                    $insert_stmt = $conn->prepare("INSERT INTO nguoi_dung (ten_nguoi_dung, email, password, so_dien_thoai, vai_tro, dia_chi_vi, ngay_tao, trang_thai) 
                                                 VALUES (:ten_nguoi_dung, :email, :password, :so_dien_thoai, :vai_tro, :dia_chi_vi, NOW(), 'inactive')");
                    
                    if ($insert_stmt->execute([
                        ':ten_nguoi_dung' => $ten_nguoi_dung,
                        ':email' => $email,
                        ':password' => $hashed_password,
                        ':so_dien_thoai' => $so_dien_thoai,
                        ':vai_tro' => $vai_tro,
                        ':dia_chi_vi' => $dia_chi_vi
                    ])) {
                        $register_success = true;
                        $_POST = array(); // Reset form
                    } else {
                        $register_error = "Đăng ký thất bại! Vui lòng thử lại.";
                    }
                }
            }
        } catch(PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $register_error = "Lỗi hệ thống! Vui lòng thử lại sau.";
        }
    }
}

// Role names mapping
$roleNames = [
    'admin' => 'Quản trị viên',
    'nha_san_xuat' => 'Nhà sản xuất',
    'moi_gioi' => 'Môi giới',
    'van_chuyen' => 'Vận chuyển',
    'khach_hang' => 'Khách hàng'
];

// Lấy thông tin user từ session
$currentUser = isset($_SESSION['user_info']) ? $_SESSION['user_info'] : null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SupplyChain - Hệ thống chuỗi cung ứng thông minh</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-accent: linear-gradient(135deg, var(--accent) 0%, #00d4aa 100%);
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: var(--light);
            color: var(--dark);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header */
        header {
            background-color: rgba(255, 255, 255, 0.95);
            box-shadow: var(--shadow-sm);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        
        .header-scrolled {
            box-shadow: var(--shadow-md);
            background-color: rgba(255, 255, 255, 0.98);
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo i {
            color: var(--primary);
            font-size: 28px;
        }
        
        .logo h1 {
            font-size: 24px;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .logo span {
            color: var(--accent);
        }
        
        nav ul {
            display: flex;
            list-style: none;
            gap: 30px;
        }
        
        nav a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: color 0.3s;
            position: relative;
        }
        
        nav a:hover, nav a.active {
            color: var(--primary);
        }
        
        nav a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary);
            transition: width 0.3s;
        }
        
        nav a:hover::after, nav a.active::after {
            width: 100%;
        }
        
        .auth-buttons {
            display: flex;
            gap: 15px;
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
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-primary:hover {
            background: var(--secondary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        /* Hero Section */
        .hero {
            padding: 150px 0 100px;
            background: linear-gradient(135deg, #f0f7ff 0%, #e6f3ff 100%);
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(44, 90, 160, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 201, 167, 0.1) 0%, transparent 50%);
            z-index: 0;
        }
        
        .hero-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 40px;
            position: relative;
            z-index: 1;
        }
        
        .hero-text {
            flex: 1;
            animation: fadeInUp 1s ease-out;
        }
        
        .hero-text h2 {
            font-size: 48px;
            line-height: 1.2;
            margin-bottom: 20px;
            color: var(--dark);
            font-weight: 800;
        }
        
        .hero-text h2 span {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
        }
        
        .hero-text h2 span::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-accent);
            border-radius: 2px;
            transform: scaleX(0);
            transform-origin: left;
            animation: underline 1.5s ease-in-out 0.5s forwards;
        }
        
        .hero-text p {
            font-size: 18px;
            color: var(--gray);
            margin-bottom: 30px;
        }
        
        .hero-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .hero-image {
            flex: 1;
            display: flex;
            justify-content: center;
            animation: fadeInRight 1s ease-out;
        }
        
        .blockchain-animation {
            position: relative;
            width: 450px;
            height: 350px;
        }
        
        .block {
            width: 90px;
            height: 90px;
            border-radius: 15px;
            position: absolute;
            animation: float 4s ease-in-out infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            box-shadow: var(--shadow-lg);
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .block:nth-child(1) {
            top: 10%;
            left: 5%;
            background: linear-gradient(135deg, var(--primary), #3a6fc5);
            animation-delay: 0s;
        }
        
        .block:nth-child(2) {
            top: 40%;
            left: 35%;
            background: linear-gradient(135deg, var(--accent), #00e6bf);
            animation-delay: 0.7s;
        }
        
        .block:nth-child(3) {
            top: 15%;
            left: 65%;
            background: linear-gradient(135deg, var(--secondary), #5a9cff);
            animation-delay: 1.4s;
        }
        
        .chain {
            position: absolute;
            top: 45%;
            left: 15%;
            width: 70%;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--primary), transparent);
            opacity: 0.6;
            animation: chainFlow 3s linear infinite;
        }

        /* Features Section */
        .features {
            padding: 100px 0;
            background: linear-gradient(135deg, #f8fbff 0%, #f0f7ff 100%);
            position: relative;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .section-title h2 {
            font-size: 36px;
            color: var(--dark);
            margin-bottom: 15px;
            font-weight: 800;
        }
        
        .section-title p {
            color: var(--gray);
            max-width: 600px;
            margin: 0 auto;
            font-size: 18px;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }
        
        .feature-card {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            box-shadow: var(--shadow-sm);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: center;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: var(--shadow-lg);
        }
        
        .feature-card:hover::before {
            transform: scaleX(1);
        }
        
        .feature-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, rgba(44, 90, 160, 0.1), rgba(58, 134, 255, 0.1));
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            transition: all 0.4s ease;
            position: relative;
        }
        
        .feature-card:hover .feature-icon {
            transform: scale(1.15) rotate(5deg);
            background: var(--gradient-primary);
        }
        
        .feature-card:hover .feature-icon i {
            color: white;
        }
        
        .feature-icon i {
            font-size: 36px;
            color: var(--primary);
            transition: all 0.4s ease;
        }
        
        .feature-card h3 {
            font-size: 24px;
            margin-bottom: 15px;
            color: var(--dark);
            font-weight: 700;
            transition: color 0.3s ease;
        }
        
        .feature-card:hover h3 {
            color: var(--primary);
        }
        
        .feature-card p {
            color: var(--gray);
            line-height: 1.6;
        }

        /* How It Works Section */
        .how-it-works {
            padding: 100px 0;
            background: white;
            position: relative;
        }
        
        .steps {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            margin-top: 50px;
            position: relative;
        }
        
        .steps::before {
            content: '';
            position: absolute;
            top: 80px;
            left: 10%;
            right: 10%;
            height: 3px;
            background: linear-gradient(90deg, 
                var(--primary) 0%, 
                var(--accent) 50%, 
                var(--secondary) 100%);
            opacity: 0.2;
            border-radius: 3px;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .step-number {
            width: 100px;
            height: 100px;
            background: var(--gradient-primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            margin: 0 auto 25px;
            position: relative;
            transition: all 0.4s ease;
            box-shadow: var(--shadow-md);
            border: 4px solid white;
        }
        
        .step:hover .step-number {
            transform: scale(1.15) rotate(10deg);
            box-shadow: var(--shadow-lg);
        }
        
        .step h3 {
            font-size: 22px;
            margin-bottom: 15px;
            color: var(--dark);
            font-weight: 700;
        }
        
        .step p {
            color: var(--gray);
            line-height: 1.6;
        }

        /* Stats Section */
        .stats {
            padding: 80px 0;
            background: var(--gradient-primary);
            color: white;
            text-align: center;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
        }
        
        .stat-item {
            padding: 30px 20px;
        }
        
        .stat-number {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 10px;
            color: white;
        }
        
        .stat-label {
            font-size: 18px;
            opacity: 0.9;
        }

        /* CTA Section */
        .cta {
            padding: 100px 0;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            text-align: center;
        }
        
        .cta h2 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 20px;
        }
        
        .cta p {
            max-width: 600px;
            margin: 0 auto 30px;
            font-size: 18px;
            opacity: 0.9;
        }
        
        .btn-light {
            background: rgba(255, 255, 255, 0.95);
            color: var(--primary);
            border: 2px solid transparent;
            backdrop-filter: blur(10px);
            font-weight: 700;
        }
        
        .btn-light:hover {
            background: white;
            border-color: white;
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 70px 0 20px;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 50px;
        }
        
        .footer-column h3 {
            font-size: 20px;
            margin-bottom: 20px;
            position: relative;
            padding-bottom: 10px;
            font-weight: 700;
        }
        
        .footer-column h3::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 40px;
            height: 3px;
            background: var(--accent);
            border-radius: 2px;
        }
        
        .footer-column ul {
            list-style: none;
        }
        
        .footer-column ul li {
            margin-bottom: 10px;
        }
        
        .footer-column a {
            color: #aaa;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .footer-column a:hover {
            color: white;
        }
        
        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 45px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .social-links a:hover {
            background: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 201, 167, 0.3);
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: #aaa;
            font-size: 14px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
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
            max-width: 500px;
            min-height: 400px;
            overflow: hidden;
            animation: modalAppear 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        @keyframes modalAppear {
            from {
                opacity: 0;
                transform: translate(-50%, -48%) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }
        
        .modal-header {
            background: var(--gradient-primary);
            color: white;
            padding: 25px 30px;
            text-align: center;
            position: relative;
        }
        
        .modal-header h2 {
            font-size: 24px;
            margin-bottom: 8px;
            font-weight: 700;
        }
        
        .modal-header p {
            opacity: 0.9;
            font-size: 14px;
            font-weight: 400;
        }
        
        .close-modal {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            font-size: 16px;
            backdrop-filter: blur(10px);
        }
        
        .close-modal:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
      /* ===== FIX MODAL SCROLLING ===== */
.modal-body {
    padding: 30px;
    max-height: 70vh; /* Giảm chiều cao tối đa */
    overflow-y: auto;
}

/* Đảm bảo form screens hiển thị đúng */
.form-screen {
    display: none;
    min-height: 400px; /* Chiều cao tối thiểu */
}

.form-screen.active {
    display: block;
    animation: fadeIn 0.4s ease-out;
}

/* Fix chiều cao cho role selection */
.role-selection-screen {
    text-align: center;
    min-height: 400px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

/* Fix chiều cao cho form */
#registerFormScreen,
#loginFormScreen {
    min-height: 500px;
}

/* Đảm bảo modal content có chiều cao phù hợp */
.modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 20px;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
    width: 90%;
    max-width: 500px;
    max-height: 90vh; /* Giới hạn chiều cao tối đa */
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-header {
    flex-shrink: 0; /* Không cho header co lại */
}

.modal-body {
    flex: 1; /* Chiếm không gian còn lại */
    overflow-y: auto;
}

/* Responsive cho mobile */
@media (max-height: 700px) {
    .modal-content {
        max-height: 95vh;
    }
    
    .modal-body {
        max-height: 60vh;
    }
}

        /* Role Selection */
        
        /* ===== FIX FORM DISPLAY ISSUE ===== */
.role-selection-screen {
    display: block; /* Đảm bảo hiển thị block */
}

.form-screen {
    display: none; /* Ẩn hoàn toàn khi không active */
}

.form-screen.active {
    display: block; /* Hiển thị block khi active */
    animation: fadeIn 0.4s ease-out;
}

/* Đảm bảo modal body không bị ảnh hưởng */
.modal-body {
    position: relative;
    min-height: 400px;
}

/* Ẩn hoàn toàn các phần không active */
#loginRoleSelection,
#registerRoleSelection,
#loginFormScreen, 
#registerFormScreen,
#registerSuccessScreen {
    transition: all 0.3s ease;
}
        
        .role-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 25px;
            color: var(--dark);
        }
        
        .role-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .role-option {
            position: relative;
        }
        
        .role-option input {
            display: none;
        }
        
        .role-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 15px;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: center;
            height: 100%;
            background: white;
        }
        
        .role-label:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .role-option input:checked + .role-label {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(44, 90, 160, 0.05), rgba(58, 134, 255, 0.05));
            box-shadow: 0 8px 20px rgba(44, 90, 160, 0.15);
            transform: translateY(-2px);
        }
        
        .role-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, rgba(44, 90, 160, 0.1), rgba(58, 134, 255, 0.1));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            transition: all 0.3s ease;
        }
        
        .role-option input:checked + .role-label .role-icon {
            background: var(--gradient-primary);
            transform: scale(1.1);
        }
        
        .role-option input:checked + .role-label .role-icon i {
            color: white;
        }
        
        .role-icon i {
            font-size: 20px;
            color: var(--primary);
            transition: color 0.3s ease;
        }
        
        .role-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .role-desc {
            font-size: 11px;
            color: var(--gray);
            line-height: 1.4;
        }
        
        .continue-btn {
            width: 100%;
            padding: 15px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }
        
        .continue-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        
        .continue-btn:hover::before {
            left: 100%;
        }
        
        .continue-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(44, 90, 160, 0.3);
        }
        
        .continue-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .continue-btn:disabled::before {
            display: none;
        }

        /* Form Screens */
        .form-screen {
            display: none;
        }
        
        .form-screen.active {
            display: block;
            animation: fadeIn 0.4s ease-out;
        }
        
        @keyframes fadeIn {
            from { 
                opacity: 0;
                transform: translateY(10px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f3f4;
        }
        
        .back-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .back-btn:hover {
            background: rgba(44, 90, 160, 0.1);
            transform: translateX(-2px);
        }
        
        .form-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .current-role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, rgba(44, 90, 160, 0.1), rgba(58, 134, 255, 0.1));
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
            transform: translateY(-1px);
        }
        
        .submit-btn {
            width: 100%;
            padding: 15px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
            position: relative;
            overflow: hidden;
        }
        
        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        
        .submit-btn:hover::before {
            left: 100%;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(44, 90, 160, 0.3);
        }
        
        .switch-form {
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
            color: var(--gray);
        }
        
        .switch-form a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
        
        .switch-form a:hover {
            text-decoration: underline;
        }

        /* MetaMask Section */
        .metamask-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .metamask-section:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .metamask-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .metamask-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #f6851b, #e2761b);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .metamask-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(246, 133, 27, 0.3);
        }
        
        .metamask-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .wallet-address {
            background: linear-gradient(135deg, #e8f4fd, #d4edff);
            border: 1px solid #b3e0ff;
            border-radius: 8px;
            padding: 10px 12px;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: var(--primary);
            word-break: break-all;
        }

        /* Success Screen */
        .success-screen {
            text-align: center;
            padding: 40px 20px;
            animation: fadeInUp 0.6s ease-out;
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #28a745, #20c997);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: scaleIn 0.5s ease-out 0.3s both;
        }
        
        .success-icon i {
            font-size: 32px;
            color: white;
        }
        
        .success-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .success-message {
            color: var(--gray);
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .redirect-loader {
            margin: 20px 0;
            padding: 15px;
            background: linear-gradient(135deg, rgba(44, 90, 160, 0.05), rgba(58, 134, 255, 0.05));
            border-radius: 10px;
            border: 1px solid rgba(44, 90, 160, 0.1);
        }
        
        .redirect-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 14px;
            color: var(--primary);
            font-weight: 600;
        }
        
        .loader-bar {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
            position: relative;
        }
        
        .loader-progress {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 2px;
            animation: loadingProgress 3s ease-in-out;
            transform-origin: left;
        }
        
        .success-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* Alert messages */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
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
        
        /* User info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        /* Loading spinner */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes float {
            0%, 100% { 
                transform: translateY(0) rotate(0deg); 
            }
            50% { 
                transform: translateY(-10px) rotate(2deg); 
            }
        }
        
        @keyframes chainFlow {
            0% { background-position: -100% 0; }
            100% { background-position: 200% 0; }
        }
        
        @keyframes underline {
            to { transform: scaleX(1); }
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        @keyframes loadingProgress {
            0% {
                transform: scaleX(0);
            }
            100% {
                transform: scaleX(1);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 15px;
            }
            
            nav ul {
                gap: 15px;
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .hero-text h2 {
                font-size: 32px;
            }
            
            .role-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                max-width: 90%;
            }
            
            .hero-buttons {
                justify-content: center;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
            
            .steps {
                flex-direction: column;
                gap: 40px;
            }
            
            .steps::before {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .modal-body {
                padding: 20px;
            }
            
            .modal-header {
                padding: 20px;
            }
            
            .modal-content {
                max-width: 95%;
            }
            
            .hero-text h2 {
                font-size: 28px;
            }
            
            .success-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header id="header">
        <div class="container header-container">
            <div class="logo">
                <i class="fas fa-link"></i>
                <h1>Supply<span>Chain</span></h1>
            </div>
            <nav>
                <ul>
                    <li><a href="#" class="active">Trang chủ</a></li>
                    <li><a href="#features">Tính năng</a></li>
                    <li><a href="#how-it-works">Cách hoạt động</a></li>
                    <li><a href="#contact">Liên hệ</a></li>
                </ul>
            </nav>
            <div class="auth-buttons">
                <?php if($currentUser): ?>
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($currentUser['ten_nguoi_dung'], 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <div class="user-name"><?php echo htmlspecialchars($currentUser['ten_nguoi_dung']); ?></div>
                            <span class="current-role-badge"><?php echo $roleNames[$currentUser['vai_tro']] ?? $currentUser['vai_tro']; ?></span>
                        </div>
                        <a href="logout.php" class="btn btn-outline">
                            <i class="fas fa-sign-out-alt"></i> Đăng xuất
                        </a>
                    </div>
                <?php else: ?>
                    <button class="btn btn-outline" id="showLoginBtn">
                        <i class="fas fa-sign-in-alt"></i> Đăng nhập
                    </button>
                    <button class="btn btn-primary" id="showRegisterBtn">
                        <i class="fas fa-user-plus"></i> Đăng ký
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="container hero-content">
            <div class="hero-text">
                <h2>Cách mạng hóa <span>chuỗi cung ứng</span> với công nghệ Blockchain</h2>
                <p>Giải pháp minh bạch, an toàn và hiệu quả để quản lý chuỗi cung ứng của bạn. Theo dõi sản phẩm từ nguồn gốc đến tay người tiêu dùng với công nghệ blockchain tiên tiến.</p>
                <div class="hero-buttons">
                    <?php if(!$currentUser): ?>
                        <button class="btn btn-primary" id="ctaGetStarted">
                            <i class="fas fa-rocket"></i> Bắt đầu ngay
                        </button>
                    <?php else: ?>
                        <?php
                            $dashboard_url = getDashboardUrl($currentUser['vai_tro']);
                        ?>
                        <a href="<?php echo $dashboard_url; ?>" class="btn btn-primary">
                            <i class="fas fa-tachometer-alt"></i> Vào Dashboard
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-outline">
                        <i class="fas fa-play-circle"></i> Xem demo
                    </button>
                </div>
            </div>
            <div class="hero-image">
                <div class="blockchain-animation">
                    <div class="block">NSX</div>
                    <div class="block">MG</div>
                    <div class="block">KH</div>
                    <div class="chain"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="container">
            <div class="section-title">
                <h2>Tính năng nổi bật</h2>
                <p>Khám phá những tính năng đột phá giúp cách mạng hóa chuỗi cung ứng của bạn</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3>Bảo mật tuyệt đối</h3>
                    <p>Công nghệ blockchain đảm bảo tính minh bạch và bảo mật cho mọi giao dịch trong chuỗi cung ứng</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3>Theo dõi minh bạch</h3>
                    <p>Theo dõi toàn bộ hành trình sản phẩm từ nguyên liệu thô đến tay người tiêu dùng cuối cùng</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3>Xử lý nhanh chóng</h3>
                    <p>Tốc độ xử lý giao dịch nhanh chóng với công nghệ blockchain tiên tiến và hiệu quả</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Phân tích thông minh</h3>
                    <p>Hệ thống phân tích dữ liệu thông minh giúp tối ưu hóa chuỗi cung ứng và dự báo nhu cầu</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                       <i class="fas fa-leaf"></i>
                    </div>
                    <h3>Bền vững môi trường</h3>
                    <p>Theo dõi và báo cáo tác động môi trường của chuỗi cung ứng, hỗ trợ các mục tiêu phát triển bền vững</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                      <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3>Ứng dụng di động</h3>
                    <p>Truy cập và quản lý chuỗi cung ứng mọi lúc, mọi nơi với ứng dụng di động thân thiện và tiện lợi</p>
            </div>
        
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works" id="how-it-works">
        <div class="container">
            <div class="section-title">
                <h2>Cách thức hoạt động</h2>
                <p>Hệ thống của chúng tôi hoạt động đơn giản và hiệu quả qua 4 bước cơ bản</p>
            </div>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <h3>Đăng ký tài khoản</h3>
                    <p>Tạo tài khoản và chọn vai trò phù hợp với nhu cầu của bạn trong chuỗi cung ứng</p>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <h3>Kết nối MetaMask</h3>
                    <p>Kết nối ví MetaMask để thực hiện các giao dịch an toàn và minh bạch trên blockchain</p>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <h3>Quản lý sản phẩm</h3>
                    <p>Theo dõi và quản lý sản phẩm qua từng giai đoạn trong chuỗi cung ứng</p>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <h3>Theo dõi giao dịch</h3>
                    <p>Giám sát mọi giao dịch và chuyển giao quyền sở hữu trong thời gian thực</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number">500+</div>
                    <div class="stat-label">Doanh nghiệp tin dùng</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">10,000+</div>
                    <div class="stat-label">Giao dịch thành công</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">99.9%</div>
                    <div class="stat-label">Thời gian hoạt động</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Hỗ trợ khách hàng</div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta" id="contact">
        <div class="container">
            <h2>Sẵn sàng cách mạng hóa chuỗi cung ứng của bạn?</h2>
            <p>Tham gia ngay hôm nay để trải nghiệm sức mạnh của công nghệ blockchain trong quản lý chuỗi cung ứng</p>
            <button class="btn btn-light" id="ctaBottom">
                <i class="fas fa-rocket"></i> Bắt đầu ngay
            </button>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>BlockChain Supply</h3>
                    <p>Giải pháp chuỗi cung ứng thông minh với công nghệ blockchain tiên tiến, mang lại sự minh bạch và hiệu quả cho doanh nghiệp.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#"><i class="fab fa-github"></i></a>
                    </div>
                </div>
                <div class="footer-column">
                    <h3>Liên kết nhanh</h3>
                    <ul>
                        <li><a href="#home">Trang chủ</a></li>
                        <li><a href="#features">Tính năng</a></li>
                        <li><a href="#how-it-works">Cách hoạt động</a></li>
                        <li><a href="#contact">Liên hệ</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Vai trò</h3>
                    <ul>
                        <li><a href="#">Nhà sản xuất</a></li>
                        <li><a href="#">Môi giới</a></li>
                        <li><a href="#">Vận chuyển</a></li>
                        <li><a href="#">Khách hàng</a></li>
                        <li><a href="#">Quản trị viên</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>Hỗ trợ</h3>
                    <ul>
                        <li><a href="#">Trung tâm trợ giúp</a></li>
                        <li><a href="#">Hướng dẫn sử dụng</a></li>
                        <li><a href="#">Câu hỏi thường gặp</a></li>
                        <li><a href="#">Chính sách bảo mật</a></li>
                        <li><a href="#">Điều khoản sử dụng</a></li>
                    </ul>
                </div>
            </div>
            <div class="copyright">
                <p>&copy; 2024 BlockChain Supply. Tất cả quyền được bảo lưu.</p>
            </div>
        </div>
    </footer>

    <!-- Login Modal -->
    <div class="modal" id="loginModal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close-modal">&times;</button>
                <h2>Đăng nhập hệ thống</h2>
                <p>Chọn vai trò và đăng nhập vào hệ thống</p>
            </div>
            <div class="modal-body">
                <!-- Role Selection Screen -->
                <div class="role-selection-screen" id="loginRoleSelection">
                    <div class="role-title">Chọn vai trò đăng nhập</div>
                    <div class="role-grid">
                        <?php foreach($roleNames as $key => $name): ?>
                        <div class="role-option">
                            <input type="radio" id="login<?php echo ucfirst(str_replace('_', '', $key)); ?>" name="loginRole" value="<?php echo $key; ?>">
                            <label class="role-label" for="login<?php echo ucfirst(str_replace('_', '', $key)); ?>">
                                <div class="role-icon">
                                    <i class="fas fa-<?php 
                                        switch($key) {
                                            case 'admin': echo 'crown'; break;
                                            case 'nha_san_xuat': echo 'industry'; break;
                                            case 'moi_gioi': echo 'handshake'; break;
                                            case 'van_chuyen': echo 'truck'; break;
                                            case 'khach_hang': echo 'user'; break;
                                            default: echo 'user';
                                        }
                                    ?>"></i>
                                </div>
                                <div class="role-name"><?php echo $name; ?></div>
                                <div class="role-desc">
                                    <?php 
                                        switch($key) {
                                            case 'admin': echo 'Quản lý hệ thống'; break;
                                            case 'nha_san_xuat': echo 'Sản xuất & cung cấp'; break;
                                            case 'moi_gioi': echo 'Kết nối giao dịch'; break;
                                            case 'van_chuyen': echo 'Vận chuyển hàng hóa'; break;
                                            case 'khach_hang': echo 'Mua & sử dụng'; break;
                                            default: echo '';
                                        }
                                    ?>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="continue-btn" id="loginContinueBtn" disabled>
                        <i class="fas fa-arrow-right"></i> Tiếp tục đăng nhập
                    </button>
                </div>

                <!-- Login Form Screen -->
                <div class="form-screen" id="loginFormScreen">
                    <div class="form-header">
                        <button class="back-btn" id="loginBackBtn">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="form-title">
                            Đăng nhập 
                            <span class="current-role-badge" id="loginRoleBadge">
                                <i class="fas fa-user-tag"></i>
                                <span id="loginRoleText">Vai trò</span>
                            </span>
                        </div>
                    </div>
                    
                    <form id="loginForm" method="POST">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="vai_tro" id="loginVaiTro">
                        
                        <?php if(isset($login_error)): ?>
                            <div class="alert alert-error"><?php echo $login_error; ?></div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="loginEmail">
                                <i class="fas fa-envelope"></i> Email
                            </label>
                            <input type="email" class="form-control" id="loginEmail" name="email" value="<?php echo $_POST['email'] ?? ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="loginPassword">
                                <i class="fas fa-lock"></i> Mật khẩu
                            </label>
                            <input type="password" class="form-control" id="loginPassword" name="password" required>
                        </div>
                        
                        <button type="submit" class="submit-btn" id="loginSubmitBtn">
                            <i class="fas fa-sign-in-alt"></i> Đăng nhập
                        </button>
                        
                        <div class="switch-form">
                            Chưa có tài khoản? <a href="#" id="switchToRegisterFromLogin">Đăng ký ngay</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div class="modal" id="registerModal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close-modal">&times;</button>
                <h2>Đăng ký tài khoản mới</h2>
                <p>Chọn vai trò và tạo tài khoản mới</p>
            </div>
            <div class="modal-body">
                <!-- Role Selection Screen -->
                <div class="role-selection-screen" id="registerRoleSelection">
                    <div class="role-title">Chọn vai trò đăng ký</div>
                    <div class="role-grid">
                        <?php foreach($roleNames as $key => $name): ?>
                        <div class="role-option">
                            <input type="radio" id="register<?php echo ucfirst(str_replace('_', '', $key)); ?>" name="registerRole" value="<?php echo $key; ?>">
                            <label class="role-label" for="register<?php echo ucfirst(str_replace('_', '', $key)); ?>">
                                <div class="role-icon">
                                    <i class="fas fa-<?php 
                                        switch($key) {
                                            case 'admin': echo 'crown'; break;
                                            case 'nha_san_xuat': echo 'industry'; break;
                                            case 'moi_gioi': echo 'handshake'; break;
                                            case 'van_chuyen': echo 'truck'; break;
                                            case 'khach_hang': echo 'user'; break;
                                            default: echo 'user';
                                        }
                                    ?>"></i>
                                </div>
                                <div class="role-name"><?php echo $name; ?></div>
                                <div class="role-desc">
                                    <?php 
                                        switch($key) {
                                            case 'admin': echo 'Quản lý hệ thống'; break;
                                            case 'nha_san_xuat': echo 'Sản xuất & cung cấp'; break;
                                            case 'moi_gioi': echo 'Kết nối giao dịch'; break;
                                            case 'van_chuyen': echo 'Vận chuyển hàng hóa'; break;
                                            case 'khach_hang': echo 'Mua & sử dụng'; break;
                                            default: echo '';
                                        }
                                    ?>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="continue-btn" id="registerContinueBtn" disabled>
                        <i class="fas fa-arrow-right"></i> Tiếp tục đăng ký
                    </button>
                </div>

                <!-- Register Form Screen -->
                <div class="form-screen" id="registerFormScreen">
                    <div class="form-header">
                        <button class="back-btn" id="registerBackBtn">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="form-title">
                            Đăng ký tài khoản
                            <span class="current-role-badge" id="registerRoleBadge">
                                <i class="fas fa-user-tag"></i>
                                <span id="registerRoleText">Vai trò</span>
                            </span>
                        </div>
                    </div>
                    
                    <form id="registerForm" method="POST">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="vai_tro" id="registerVaiTro">
                        <input type="hidden" name="dia_chi_vi" id="registerWalletAddress">
                        
                        <?php if(isset($register_error)): ?>
                            <div class="alert alert-error"><?php echo $register_error; ?></div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="registerName">
                                <i class="fas fa-user"></i> Họ và tên
                            </label>
                            <input type="text" class="form-control" id="registerName" name="ten_nguoi_dung" value="<?php echo $_POST['ten_nguoi_dung'] ?? ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="registerPhone">
                                <i class="fas fa-phone"></i> Số điện thoại
                            </label>
                            <input type="tel" class="form-control" id="registerPhone" name="so_dien_thoai" value="<?php echo $_POST['so_dien_thoai'] ?? ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="registerEmail">
                                <i class="fas fa-envelope"></i> Email
                            </label>
                            <input type="email" class="form-control" id="registerEmail" name="email" value="<?php echo $_POST['email'] ?? ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="registerPassword">
                                <i class="fas fa-lock"></i> Mật khẩu
                            </label>
                            <input type="password" class="form-control" id="registerPassword" name="password" required minlength="6">
                        </div>
                        
                        <div class="form-group">
                            <label for="registerConfirmPassword">
                                <i class="fas fa-lock"></i> Xác nhận mật khẩu
                            </label>
                            <input type="password" class="form-control" id="registerConfirmPassword" name="confirm_password" required minlength="6">
                        </div>

                        <!-- MetaMask Integration -->
                        <div class="metamask-section">
                            <div class="metamask-title">
                                <i class="fab fa-ethereum"></i> Kết nối MetaMask
                            </div>
                            <p style="font-size: 12px; color: var(--gray); margin-bottom: 10px;">
                                Kết nối ví MetaMask để nhận địa chỉ ví blockchain (bắt buộc)
                            </p>
                            <button type="button" class="metamask-btn" id="connectMetamaskBtn">
                                <i class="fab fa-ethereum"></i> Kết nối MetaMask
                            </button>
                            <div class="wallet-address" id="walletAddress" style="display: none;">
                                Địa chỉ ví: <span id="walletAddressText"></span>
                            </div>
                        </div>
                        
                        <button type="submit" class="submit-btn" id="registerSubmitBtn" disabled>
                            <i class="fas fa-user-plus"></i> Đăng ký tài khoản
                        </button>
                        
                        <div class="switch-form">
                            Đã có tài khoản? <a href="#" id="switchToLoginFromRegister">Đăng nhập ngay</a>
                        </div>
                    </form>
                </div>

                <!-- Success Screen -->
                <div class="form-screen" id="registerSuccessScreen">
                    <div class="success-screen">
                        <div class="success-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <h3 class="success-title">Đăng ký thành công!</h3>
                        <p class="success-message">
                            Tài khoản của bạn đã được tạo thành công. 
                            Bạn sẽ được chuyển hướng đến trang đăng nhập trong giây lát...
                        </p>
                        
                        <div class="redirect-loader">
                            <div class="redirect-info">
                                <i class="fas fa-sync-alt fa-spin"></i>
                                Đang chuyển hướng...
                            </div>
                            <div class="loader-bar">
                                <div class="loader-progress"></div>
                            </div>
                        </div>
                        
                        <div class="success-actions">
                            <button class="btn btn-primary" onclick="redirectToLogin()">
                                <i class="fas fa-sign-in-alt"></i> Đăng nhập ngay
                            </button>
                            <button class="btn btn-outline" onclick="closeRegisterModal()">
                                <i class="fas fa-times"></i> Đóng
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Role names mapping for JavaScript
        const roleNames = {
            'admin': 'Quản trị viên',
            'nha_san_xuat': 'Nhà sản xuất',
            'moi_gioi': 'Môi giới',
            'van_chuyen': 'Vận chuyển',
            'khach_hang': 'Khách hàng'
        };

        // Application state
        let currentLoginRole = null;
        let currentRegisterRole = null;
        let userWalletAddress = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Modal Elements
            const loginModal = document.getElementById('loginModal');
            const registerModal = document.getElementById('registerModal');
            const showLoginBtn = document.getElementById('showLoginBtn');
            const showRegisterBtn = document.getElementById('showRegisterBtn');
            const closeButtons = document.querySelectorAll('.close-modal');
            const ctaGetStarted = document.getElementById('ctaGetStarted');
            const ctaBottom = document.getElementById('ctaBottom');

            // Login Elements
            const loginRoleInputs = document.querySelectorAll('input[name="loginRole"]');
            const loginContinueBtn = document.getElementById('loginContinueBtn');
            const loginBackBtn = document.getElementById('loginBackBtn');
            const loginForm = document.getElementById('loginForm');
            const loginRoleText = document.getElementById('loginRoleText');
            const loginVaiTro = document.getElementById('loginVaiTro');

            // Register Elements
            const registerRoleInputs = document.querySelectorAll('input[name="registerRole"]');
            const registerContinueBtn = document.getElementById('registerContinueBtn');
            const registerBackBtn = document.getElementById('registerBackBtn');
            const registerForm = document.getElementById('registerForm');
            const registerRoleText = document.getElementById('registerRoleText');
            const registerVaiTro = document.getElementById('registerVaiTro');
            const registerWalletAddress = document.getElementById('registerWalletAddress');
            const registerSubmitBtn = document.getElementById('registerSubmitBtn');

            // MetaMask Elements
            const connectMetamaskBtn = document.getElementById('connectMetamaskBtn');
            const walletAddress = document.getElementById('walletAddress');
            const walletAddressText = document.getElementById('walletAddressText');

            // Switch between modals
            const switchToRegisterFromLogin = document.getElementById('switchToRegisterFromLogin');
            const switchToLoginFromRegister = document.getElementById('switchToLoginFromRegister');

            // Show/Hide Modals
            showLoginBtn.addEventListener('click', () => {
                resetLoginModal();
                loginModal.style.display = 'block';
                registerModal.style.display = 'none';
            });

            showRegisterBtn.addEventListener('click', () => {
                resetRegisterModal();
                registerModal.style.display = 'block';
                loginModal.style.display = 'none';
            });

            ctaGetStarted.addEventListener('click', () => {
                resetRegisterModal();
                registerModal.style.display = 'block';
            });

            ctaBottom.addEventListener('click', () => {
                resetRegisterModal();
                registerModal.style.display = 'block';
            });

            // Close modals
            closeButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    loginModal.style.display = 'none';
                    registerModal.style.display = 'none';
                });
            });

            // Login Role Selection
            loginRoleInputs.forEach(input => {
                input.addEventListener('change', function() {
                    currentLoginRole = this.value;
                    loginContinueBtn.disabled = false;
                });
            });

            // Register Role Selection
            registerRoleInputs.forEach(input => {
                input.addEventListener('change', function() {
                    currentRegisterRole = this.value;
                    registerContinueBtn.disabled = false;
                });
            });

          // Continue to forms - SỬA LẠI
loginContinueBtn.addEventListener('click', function() {
    if (currentLoginRole) {
        loginRoleText.textContent = roleNames[currentLoginRole];
        loginVaiTro.value = currentLoginRole;
        
        // ẨN HOÀN TOÀN màn hình chọn vai trò
        document.getElementById('loginRoleSelection').style.display = 'none';
        
        // HIỂN THỊ form đăng nhập
        document.getElementById('loginFormScreen').classList.add('active');
    }
});

registerContinueBtn.addEventListener('click', function() {
    if (currentRegisterRole) {
        registerRoleText.textContent = roleNames[currentRegisterRole];
        registerVaiTro.value = currentRegisterRole;
        
        // ẨN HOÀN TOÀN màn hình chọn vai trò
        document.getElementById('registerRoleSelection').style.display = 'none';
        
        // HIỂN THỊ form đăng ký
        document.getElementById('registerFormScreen').classList.add('active');
    }
});

// Back buttons - SỬA LẠI
loginBackBtn.addEventListener('click', function() {
    // ẨN form đăng nhập
    document.getElementById('loginFormScreen').classList.remove('active');
    
    // HIỂN THỊ LẠI màn hình chọn vai trò
    document.getElementById('loginRoleSelection').style.display = 'block';
    
    loginContinueBtn.disabled = true;
});

registerBackBtn.addEventListener('click', function() {
    // ẨN form đăng ký
    document.getElementById('registerFormScreen').classList.remove('active');
    
    // HIỂN THỊ LẠI màn hình chọn vai trò
    document.getElementById('registerRoleSelection').style.display = 'block';
    
    registerContinueBtn.disabled = true;
});

            switchToLoginFromRegister.addEventListener('click', function(e) {
                e.preventDefault();
                registerModal.style.display = 'none';
                resetLoginModal();
                loginModal.style.display = 'block';
            });

            // MetaMask Integration
            connectMetamaskBtn.addEventListener('click', async function() {
                if (typeof window.ethereum !== 'undefined') {
                    try {
                        connectMetamaskBtn.innerHTML = '<div class="loading"></div> Đang kết nối...';
                        connectMetamaskBtn.disabled = true;

                        // Request account access
                        const accounts = await window.ethereum.request({
                            method: 'eth_requestAccounts'
                        });
                        
                        userWalletAddress = accounts[0];
                        walletAddressText.textContent = userWalletAddress;
                        walletAddress.style.display = 'block';
                        registerWalletAddress.value = userWalletAddress;
                        
                        connectMetamaskBtn.innerHTML = '<i class="fas fa-check"></i> Đã kết nối';
                        connectMetamaskBtn.disabled = true;
                        
                        // Enable submit button
                        registerSubmitBtn.disabled = false;
                    } catch (error) {
                        console.error('Error connecting to MetaMask:', error);
                        connectMetamaskBtn.innerHTML = '<i class="fab fa-ethereum"></i> Kết nối MetaMask';
                        connectMetamaskBtn.disabled = false;
                        
                        if (error.code === 4001) {
                            alert('Bạn đã từ chối kết nối MetaMask. Vui lòng đồng ý kết nối để tiếp tục.');
                        } else {
                            alert('Lỗi kết nối MetaMask: ' + error.message);
                        }
                    }
                } else {
                    alert('MetaMask không được tìm thấy. Vui lòng cài đặt MetaMask!');
                    window.open('https://metamask.io/download.html', '_blank');
                }
            });

            // Form validation
            registerForm.addEventListener('submit', function(e) {
                const password = document.getElementById('registerPassword').value;
                const confirmPassword = document.getElementById('registerConfirmPassword').value;
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Mật khẩu xác nhận không khớp!');
                    return;
                }

                if (!userWalletAddress) {
                    e.preventDefault();
                    alert('Vui lòng kết nối MetaMask để tiếp tục!');
                    return;
                }
            });

            // Password confirmation validation
            document.getElementById('registerConfirmPassword').addEventListener('input', function() {
                const password = document.getElementById('registerPassword').value;
                const confirmPassword = this.value;
                const submitBtn = document.getElementById('registerSubmitBtn');
                
                if (password !== confirmPassword && confirmPassword.length > 0) {
                    this.style.borderColor = 'var(--danger)';
                } else {
                    this.style.borderColor = '#e9ecef';
                }
            });

            // Close modal when clicking outside
            window.addEventListener('click', (e) => {
                if (e.target === loginModal) loginModal.style.display = 'none';
                if (e.target === registerModal) registerModal.style.display = 'none';
            });

            // Header scroll effect
            window.addEventListener('scroll', function() {
                const header = document.getElementById('header');
                if (window.scrollY > 100) {
                    header.classList.add('header-scrolled');
                } else {
                    header.classList.remove('header-scrolled');
                }
            });

            // Auto show modal if there's an error or success
            <?php if(isset($login_error)): ?>
                loginModal.style.display = 'block';
                // Auto fill role and show form if available
                const loginRole = '<?php echo $_POST['vai_tro'] ?? ''; ?>';
                if (loginRole) {
                    currentLoginRole = loginRole;
                    loginRoleText.textContent = roleNames[loginRole];
                    loginVaiTro.value = loginRole;
                    document.getElementById('loginRoleSelection').style.display = 'none';
                    document.getElementById('loginFormScreen').classList.add('active');
                }
            <?php elseif(isset($register_success)): ?>
                registerModal.style.display = 'block';
                // Show success screen
                document.getElementById('registerRoleSelection').style.display = 'none';
                document.getElementById('registerFormScreen').style.display = 'none';
                document.getElementById('registerSuccessScreen').classList.add('active');
                
                // Auto redirect after 5 seconds
                setTimeout(() => {
                    redirectToLogin();
                }, 5000);
            <?php elseif(isset($register_error)): ?>
                registerModal.style.display = 'block';
                // Auto fill role and show form if available
                const registerRole = '<?php echo $_POST['vai_tro'] ?? ''; ?>';
                if (registerRole) {
                    currentRegisterRole = registerRole;
                    registerRoleText.textContent = roleNames[registerRole];
                    registerVaiTro.value = registerRole;
                    document.getElementById('registerRoleSelection').style.display = 'none';
                    document.getElementById('registerFormScreen').classList.add('active');
                }
            <?php endif; ?>
        });

      function resetLoginModal() {
    const loginRoleSelection = document.getElementById('loginRoleSelection');
    const loginFormScreen = document.getElementById('loginFormScreen');
    const loginContinueBtn = document.getElementById('loginContinueBtn');
    
    // HIỂN THỊ màn hình chọn vai trò
    if (loginRoleSelection) {
        loginRoleSelection.style.display = 'block';
    }
    
    // ẨN form đăng nhập
    if (loginFormScreen) {
        loginFormScreen.classList.remove('active');
    }
    
    if (loginContinueBtn) {
        loginContinueBtn.disabled = true;
    }
    
    currentLoginRole = null;
    const checkedLogin = document.querySelector('input[name="loginRole"]:checked');
    if (checkedLogin) checkedLogin.checked = false;
}

function resetRegisterModal() {
    const registerRoleSelection = document.getElementById('registerRoleSelection');
    const registerFormScreen = document.getElementById('registerFormScreen');
    const registerSuccessScreen = document.getElementById('registerSuccessScreen');
    const registerContinueBtn = document.getElementById('registerContinueBtn');
    
    // HIỂN THỊ màn hình chọn vai trò
    if (registerRoleSelection) {
        registerRoleSelection.style.display = 'block';
    }
    
    // ẨN form đăng ký và success screen
    if (registerFormScreen) {
        registerFormScreen.classList.remove('active');
    }
    if (registerSuccessScreen) {
        registerSuccessScreen.classList.remove('active');
    }
    
    if (registerContinueBtn) {
        registerContinueBtn.disabled = true;
    }
    
    currentRegisterRole = null;
    userWalletAddress = null;
    
    // Reset MetaMask section
    const walletAddress = document.getElementById('walletAddress');
    const connectMetamaskBtn = document.getElementById('connectMetamaskBtn');
    const registerSubmitBtn = document.getElementById('registerSubmitBtn');
    
    if (walletAddress) walletAddress.style.display = 'none';
    if (connectMetamaskBtn) {
        connectMetamaskBtn.innerHTML = '<i class="fab fa-ethereum"></i> Kết nối MetaMask';
        connectMetamaskBtn.disabled = false;
    }
    if (registerSubmitBtn) registerSubmitBtn.disabled = true;
    
    const checkedRegister = document.querySelector('input[name="registerRole"]:checked');
    if (checkedRegister) checkedRegister.checked = false;
}

        function redirectToLogin() {
            const registerModal = document.getElementById('registerModal');
            const loginModal = document.getElementById('loginModal');
            
            registerModal.style.display = 'none';
            resetRegisterModal();
            loginModal.style.display = 'block';
        }

        function closeRegisterModal() {
            const registerModal = document.getElementById('registerModal');
            registerModal.style.display = 'none';
            resetRegisterModal();
        }
    </script>
</body>
</html>