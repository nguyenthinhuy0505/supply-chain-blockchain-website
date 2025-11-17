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
    
    if (!$nha_san_xuat) {
        $_SESSION['error_message'] = "Không tìm thấy thông tin người dùng!";
        header("Location: nha_san_xuat.php");
        exit;
    }
} catch(PDOException $e) {
    error_log("Error fetching producer info: " . $e->getMessage());
    $_SESSION['error_message'] = "Lỗi khi tải thông tin người dùng!";
    header("Location: nha_san_xuat.php");
    exit;
}

// Biến thông báo
$success_message = '';
$error_message = '';

// Xử lý cập nhật thông tin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_profile') {
        $ho_ten = trim($_POST['ho_ten'] ?? '');
        $ten_cong_ty = trim($_POST['ten_cong_ty'] ?? '');
        $so_dien_thoai = trim($_POST['so_dien_thoai'] ?? '');
        $dia_chi = trim($_POST['dia_chi'] ?? '');
        $tinh_thanh = trim($_POST['tinh_thanh'] ?? '');
        $quan_huyen = trim($_POST['quan_huyen'] ?? '');
        $xa_phuong = trim($_POST['xa_phuong'] ?? '');
        $dia_chi_cu_the = trim($_POST['dia_chi_cu_the'] ?? '');
        
        // Xử lý upload hình đại diện
        $hinh_dai_dien = $nha_san_xuat['hinh_dai_dien'] ?? null;
        
        if (isset($_FILES['hinh_dai_dien']) && $_FILES['hinh_dai_dien']['error'] == 0) {
            try {
                $hinh_dai_dien = uploadAvatar($_FILES['hinh_dai_dien']);
            } catch (Exception $e) {
                $error_message = "Lỗi upload hình đại diện: " . $e->getMessage();
            }
        }
        
        // Xử lý mật khẩu (nếu có thay đổi)
        $password = null;
        if (!empty($_POST['password']) && !empty($_POST['confirm_password'])) {
            if ($_POST['password'] === $_POST['confirm_password']) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            } else {
                $error_message = "Mật khẩu xác nhận không khớp!";
            }
        }
        
        if (!empty($ho_ten) && !empty($ten_cong_ty)) {
            try {
                if ($password) {
                    // Cập nhật cả mật khẩu
                    $update_stmt = $conn->prepare("UPDATE nguoi_dung 
                        SET ho_ten = :ho_ten, ten_cong_ty = :ten_cong_ty, so_dien_thoai = :so_dien_thoai, 
                            dia_chi = :dia_chi, tinh_thanh = :tinh_thanh, quan_huyen = :quan_huyen, 
                            xa_phuong = :xa_phuong, dia_chi_cu_the = :dia_chi_cu_the, 
                            hinh_dai_dien = :hinh_dai_dien, password = :password, ngay_cap_nhat = NOW() 
                        WHERE id = :id");
                    
                    $update_result = $update_stmt->execute([
                        ':ho_ten' => $ho_ten,
                        ':ten_cong_ty' => $ten_cong_ty,
                        ':so_dien_thoai' => $so_dien_thoai,
                        ':dia_chi' => $dia_chi,
                        ':tinh_thanh' => $tinh_thanh,
                        ':quan_huyen' => $quan_huyen,
                        ':xa_phuong' => $xa_phuong,
                        ':dia_chi_cu_the' => $dia_chi_cu_the,
                        ':hinh_dai_dien' => $hinh_dai_dien,
                        ':password' => $password,
                        ':id' => $user['id']
                    ]);
                } else {
                    // Không cập nhật mật khẩu
                    $update_stmt = $conn->prepare("UPDATE nguoi_dung 
                        SET ho_ten = :ho_ten, ten_cong_ty = :ten_cong_ty, so_dien_thoai = :so_dien_thoai, 
                            dia_chi = :dia_chi, tinh_thanh = :tinh_thanh, quan_huyen = :quan_huyen, 
                            xa_phuong = :xa_phuong, dia_chi_cu_the = :dia_chi_cu_the, 
                            hinh_dai_dien = :hinh_dai_dien, ngay_cap_nhat = NOW() 
                        WHERE id = :id");
                    
                    $update_result = $update_stmt->execute([
                        ':ho_ten' => $ho_ten,
                        ':ten_cong_ty' => $ten_cong_ty,
                        ':so_dien_thoai' => $so_dien_thoai,
                        ':dia_chi' => $dia_chi,
                        ':tinh_thanh' => $tinh_thanh,
                        ':quan_huyen' => $quan_huyen,
                        ':xa_phuong' => $xa_phuong,
                        ':dia_chi_cu_the' => $dia_chi_cu_the,
                        ':hinh_dai_dien' => $hinh_dai_dien,
                        ':id' => $user['id']
                    ]);
                }
                
                if ($update_result) {
                    // Cập nhật session
                    $_SESSION['user_info']['ho_ten'] = $ho_ten;
                    $_SESSION['user_info']['ten_cong_ty'] = $ten_cong_ty;
                    
                    $success_message = "Cập nhật thông tin thành công!";
                    
                    // Lấy lại thông tin mới nhất
                    $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE id = :id");
                    $stmt->execute([':id' => $user['id']]);
                    $nha_san_xuat = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $error_message = "Lỗi khi cập nhật thông tin!";
                }
            } catch(PDOException $e) {
                error_log("Error updating profile: " . $e->getMessage());
                $error_message = "Lỗi khi cập nhật thông tin!";
            }
        } else {
            $error_message = "Vui lòng điền đầy đủ thông tin bắt buộc!";
        }
    }
}

// Hàm upload hình đại diện
function uploadAvatar($file) {
    $target_dir = "../uploads/avatars/";
    
    // Tạo thư mục nếu chưa tồn tại
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $file_name = 'avatar_' . uniqid() . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . $file_name;
    
    // Kiểm tra file ảnh
    $check = getimagesize($file["tmp_name"]);
    if ($check === false) {
        throw new Exception("File không phải là hình ảnh.");
    }
    
    // Kiểm tra kích thước file (max 2MB)
    if ($file["size"] > 2 * 1024 * 1024) {
        throw new Exception("Kích thước file quá lớn. Tối đa 2MB.");
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
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ Nhà Sản Xuất - BlockChain Supply</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS Material Design giống với trang chính */
        :root {
            --primary: #1976d2;
            --primary-light: #42a5f5;
            --primary-dark: #1565c0;
            --secondary: #7b1fa2;
            --accent: #00bcd4;
            --success: #388e3c;
            --warning: #f57c00;
            --danger: #d32f2f;
            --dark: #121212;
            --light: #fafafa;
            --surface: #ffffff;
            --on-surface: #212121;
            --on-primary: #ffffff;
            
            --gradient-primary: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-success: linear-gradient(135deg, var(--success) 0%, #2e7d32 100%);
            --gradient-warning: linear-gradient(135deg, var(--warning) 0%, #ef6c00 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger) 0%, #c62828 100%);
            --gradient-metamask: linear-gradient(135deg, #f6851b, #e2761b);
            
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
            color: var(--on-surface);
            line-height: 1.5;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #424242 0%, #212121 100%);
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
        }

        .sidebar-header p {
            font-size: 11px;
            opacity: 0.7;
            color: #bdbdbd;
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

        .menu-item:hover {
            background: rgba(33, 150, 243, 0.08);
            color: white;
            border-left-color: var(--primary-light);
        }

        .menu-item.active {
            background: rgba(33, 150, 243, 0.15);
            color: white;
            border-left-color: var(--primary);
        }

        .menu-item i {
            width: 18px;
            text-align: center;
            font-size: 14px;
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
        }

        .header h1 {
            color: #212121;
            font-size: 20px;
            font-weight: 600;
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
            color: #212121;
            font-size: 18px;
            font-weight: 600;
        }

        /* Form Styles */
        .form-container {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #616161;
            font-size: 13px;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 13px;
            transition: var(--transition);
            background: rgba(255, 255, 255, 0.8);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* Buttons */
        .btn {
            padding: 12px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #616161;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        /* Avatar Upload */
        .avatar-upload-container {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .avatar-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid #e0e0e0;
            position: relative;
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload-controls {
            flex: 1;
        }

        .avatar-upload-btn {
            display: inline-block;
            padding: 10px 16px;
            background: var(--gradient-primary);
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: var(--transition);
        }

        .avatar-upload-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .file-input {
            display: none;
        }

        .avatar-hint {
            margin-top: 8px;
            font-size: 11px;
            color: #757575;
        }

        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 16px;
            border: 1px solid transparent;
            font-weight: 500;
            backdrop-filter: blur(10px);
            font-size: 13px;
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

        /* Info Cards */
        .info-card {
            background: rgba(245, 245, 245, 0.5);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
            border-left: 4px solid var(--primary);
        }

        .info-card h3 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #212121;
        }

        .info-card p {
            font-size: 12px;
            color: #757575;
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
            
            .header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .avatar-upload-container {
                flex-direction: column;
                text-align: center;
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
                <a href="products.php" class="menu-item">
                    <i class="fas fa-box"></i>
                    <span>Sản phẩm</span>
                </a>
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Đơn hàng</span>
                </a>
                <a href="profile.php" class="menu-item active">
                    <i class="fas fa-user"></i>
                    <span>Hồ sơ</span>
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
                <h1>Hồ sơ Nhà Sản Xuất</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php 
                        if (!empty($nha_san_xuat['hinh_dai_dien'])) {
                            echo '<img src="../uploads/avatars/' . htmlspecialchars($nha_san_xuat['hinh_dai_dien']) . '" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">';
                        } else {
                            echo strtoupper(substr($nha_san_xuat['ho_ten'] ?? 'N', 0, 1));
                        }
                        ?>
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

            <!-- Profile Section -->
            <div class="content-section">
                <div class="section-header">
                    <h2><i class="fas fa-user-edit"></i> Thông tin cá nhân</h2>
                </div>
                <div class="form-container">
                    <form method="POST" enctype="multipart/form-data" id="profileForm">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <!-- Avatar Upload -->
                        <div class="avatar-upload-container">
                            <div class="avatar-preview" id="avatarPreview">
                                <?php if (!empty($nha_san_xuat['hinh_dai_dien'])): ?>
                                    <img src="../uploads/avatars/<?php echo htmlspecialchars($nha_san_xuat['hinh_dai_dien']); ?>" alt="Avatar" id="previewAvatar">
                                <?php else: ?>
                                    <div style="width: 100%; height: 100%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-size: 36px; font-weight: 600;">
                                        <?php echo strtoupper(substr($nha_san_xuat['ho_ten'] ?? 'N', 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="avatar-upload-controls">
                                <label class="avatar-upload-btn">
                                    <i class="fas fa-camera"></i> Chọn hình đại diện
                                    <input type="file" id="avatarInput" name="hinh_dai_dien" class="file-input" accept="image/*">
                                </label>
                                <div class="avatar-hint">
                                    Định dạng: JPG, PNG, GIF, WEBP - Tối đa 2MB
                                </div>
                                <?php if (!empty($nha_san_xuat['hinh_dai_dien'])): ?>
                                    <button type="button" class="btn btn-secondary" onclick="removeAvatar()" style="margin-top: 8px; padding: 6px 12px; font-size: 11px;">
                                        <i class="fas fa-trash"></i> Xóa ảnh
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="ho_ten">Họ và tên *</label>
                                <input type="text" class="form-control" id="ho_ten" name="ho_ten" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['ho_ten'] ?? ''); ?>" 
                                       required placeholder="Nhập họ và tên">
                            </div>
                            
                            <div class="form-group">
                                <label for="ten_cong_ty">Tên công ty *</label>
                                <input type="text" class="form-control" id="ten_cong_ty" name="ten_cong_ty" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['ten_cong_ty'] ?? ''); ?>" 
                                       required placeholder="Nhập tên công ty">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" class="form-control" id="email" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['email'] ?? ''); ?>" 
                                       disabled placeholder="Email">
                                <small style="color: #757575; font-size: 11px;">Email không thể thay đổi</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="so_dien_thoai">Số điện thoại</label>
                                <input type="tel" class="form-control" id="so_dien_thoai" name="so_dien_thoai" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['so_dien_thoai'] ?? ''); ?>" 
                                       placeholder="Nhập số điện thoại">
                            </div>
                        </div>

                        <div class="info-card">
                            <h3><i class="fas fa-map-marker-alt"></i> Địa chỉ liên hệ</h3>
                            <p>Thông tin địa chỉ sẽ được sử dụng làm địa chỉ mặc định cho sản phẩm</p>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="tinh_thanh">Tỉnh/Thành phố</label>
                                <input type="text" class="form-control" id="tinh_thanh" name="tinh_thanh" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['tinh_thanh'] ?? ''); ?>" 
                                       placeholder="Hà Nội, TP.HCM...">
                            </div>
                            
                            <div class="form-group">
                                <label for="quan_huyen">Quận/Huyện</label>
                                <input type="text" class="form-control" id="quan_huyen" name="quan_huyen" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['quan_huyen'] ?? ''); ?>" 
                                       placeholder="Quận 1, Ba Đình...">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="xa_phuong">Xã/Phường</label>
                                <input type="text" class="form-control" id="xa_phuong" name="xa_phuong" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['xa_phuong'] ?? ''); ?>" 
                                       placeholder="Phường Linh Trung...">
                            </div>
                            
                            <div class="form-group">
                                <label for="dia_chi_cu_the">Địa chỉ cụ thể</label>
                                <input type="text" class="form-control" id="dia_chi_cu_the" name="dia_chi_cu_the" 
                                       value="<?php echo htmlspecialchars($nha_san_xuat['dia_chi_cu_the'] ?? ''); ?>" 
                                       placeholder="Số nhà, đường, thôn/xóm...">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="dia_chi">Địa chỉ đầy đủ</label>
                            <textarea class="form-control" id="dia_chi" name="dia_chi" rows="2" 
                                      placeholder="Địa chỉ đầy đủ..."><?php echo htmlspecialchars($nha_san_xuat['dia_chi'] ?? ''); ?></textarea>
                        </div>

                        <div class="info-card">
                            <h3><i class="fas fa-lock"></i> Thay đổi mật khẩu</h3>
                            <p>Chỉ điền thông tin bên dưới nếu bạn muốn thay đổi mật khẩu</p>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="password">Mật khẩu mới</label>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Nhập mật khẩu mới">
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Xác nhận mật khẩu</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       placeholder="Nhập lại mật khẩu mới">
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Cập nhật thông tin
                            </button>
                            <a href="nha_san_xuat.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Quay lại
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Account Info Section -->
            <div class="content-section">
                <div class="section-header">
                    <h2><i class="fas fa-info-circle"></i> Thông tin tài khoản</h2>
                </div>
                <div class="form-container">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Vai trò</label>
                            <input type="text" class="form-control" value="Nhà sản xuất" disabled>
                        </div>
                        
                        <div class="form-group">
                            <label>Trạng thái</label>
                            <input type="text" class="form-control" 
                                   value="<?php echo ($nha_san_xuat['trang_thai'] ?? 'active') === 'active' ? 'Đang hoạt động' : 'Không hoạt động'; ?>" 
                                   disabled>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Ngày tạo</label>
                            <input type="text" class="form-control" 
                                   value="<?php echo !empty($nha_san_xuat['ngay_tao']) ? date('d/m/Y H:i', strtotime($nha_san_xuat['ngay_tao'])) : 'N/A'; ?>" 
                                   disabled>
                        </div>
                        
                        <div class="form-group">
                            <label>Lần đăng nhập cuối</label>
                            <input type="text" class="form-control" 
                                   value="<?php echo !empty($nha_san_xuat['last_login']) ? date('d/m/Y H:i', strtotime($nha_san_xuat['last_login'])) : 'Chưa đăng nhập'; ?>" 
                                   disabled>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Avatar upload functionality
            const avatarInput = document.getElementById('avatarInput');
            const avatarPreview = document.getElementById('avatarPreview');
            
            if (avatarInput) {
                avatarInput.addEventListener('change', function(e) {
                    handleAvatarSelection(e.target.files[0]);
                });
            }
            
            function handleAvatarSelection(file) {
                if (file && file.type.startsWith('image/')) {
                    if (file.size > 2 * 1024 * 1024) {
                        alert('Kích thước file quá lớn. Tối đa 2MB.');
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        avatarPreview.innerHTML = `<img src="${e.target.result}" alt="Avatar Preview" id="previewAvatar" style="width: 100%; height: 100%; object-fit: cover;">`;
                    };
                    reader.readAsDataURL(file);
                } else {
                    alert('Vui lòng chọn file hình ảnh hợp lệ.');
                }
            }
            
            // Form validation
            const profileForm = document.getElementById('profileForm');
            if (profileForm) {
                profileForm.addEventListener('submit', function(e) {
                    const password = document.getElementById('password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    
                    if (password && password !== confirmPassword) {
                        e.preventDefault();
                        alert('Mật khẩu xác nhận không khớp!');
                        return false;
                    }
                    
                    return true;
                });
            }
        });
        
        function removeAvatar() {
            if (confirm('Bạn có chắc chắn muốn xóa hình đại diện?')) {
                const avatarPreview = document.getElementById('avatarPreview');
                const initials = '<?php echo strtoupper(substr($nha_san_xuat['ho_ten'] ?? 'N', 0, 1)); ?>';
                
                avatarPreview.innerHTML = `
                    <div style="width: 100%; height: 100%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-size: 36px; font-weight: 600;">
                        ${initials}
                    </div>
                `;
                
                // Clear file input
                document.getElementById('avatarInput').value = '';
            }
        }
    </script>
</body>
</html>