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
}

// Xử lý thêm danh mục
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'add_category') {
        $ten_danh_muc = trim($_POST['ten_danh_muc'] ?? '');
        $mo_ta = trim($_POST['mo_ta'] ?? '');
        
        if (!empty($ten_danh_muc)) {
            try {
                $insert_stmt = $conn->prepare("INSERT INTO danh_muc (ten_danh_muc, mo_ta, ngay_tao) VALUES (:ten_danh_muc, :mo_ta, NOW())");
                
                $result = $insert_stmt->execute([
                    ':ten_danh_muc' => $ten_danh_muc,
                    ':mo_ta' => $mo_ta
                ]);
                
                if ($result) {
                    $success_message = "Thêm danh mục thành công!";
                    header("Location: categories.php?success=1");
                    exit;
                } else {
                    $error_message = "Lỗi khi thực hiện thêm danh mục!";
                }
            } catch(PDOException $e) {
                error_log("Error adding category: " . $e->getMessage());
                $error_message = "Lỗi khi thêm danh mục: " . $e->getMessage();
            }
        } else {
            $error_message = "Vui lòng nhập tên danh mục!";
        }
    }
    
    // Xử lý sửa danh mục
    if ($_POST['action'] == 'update_category') {
        $category_id = $_POST['category_id'] ?? '';
        $ten_danh_muc = trim($_POST['ten_danh_muc'] ?? '');
        $mo_ta = trim($_POST['mo_ta'] ?? '');
        
        if (!empty($ten_danh_muc) && !empty($category_id)) {
            try {
                $update_stmt = $conn->prepare("UPDATE danh_muc SET ten_danh_muc = :ten_danh_muc, mo_ta = :mo_ta WHERE id = :id");
                
                $result = $update_stmt->execute([
                    ':ten_danh_muc' => $ten_danh_muc,
                    ':mo_ta' => $mo_ta,
                    ':id' => $category_id
                ]);
                
                if ($result) {
                    $success_message = "Cập nhật danh mục thành công!";
                    header("Location: categories.php?success=2");
                    exit;
                } else {
                    $error_message = "Không thể cập nhật danh mục!";
                }
            } catch(PDOException $e) {
                error_log("Error updating category: " . $e->getMessage());
                $error_message = "Lỗi khi cập nhật danh mục: " . $e->getMessage();
            }
        } else {
            $error_message = "Vui lòng nhập đầy đủ thông tin!";
        }
    }
    
    // Xử lý xóa danh mục
    if ($_POST['action'] == 'delete_category') {
        $category_id = $_POST['category_id'] ?? '';
        
        if (!empty($category_id)) {
            try {
                // Kiểm tra xem danh mục có sản phẩm không
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM san_pham WHERE danh_muc_id = :danh_muc_id");
                $check_stmt->execute([':danh_muc_id' => $category_id]);
                $product_count = $check_stmt->fetchColumn();
                
                if ($product_count > 0) {
                    $error_message = "Không thể xóa danh mục vì có sản phẩm đang sử dụng!";
                } else {
                    $delete_stmt = $conn->prepare("DELETE FROM danh_muc WHERE id = :id");
                    $result = $delete_stmt->execute([':id' => $category_id]);
                    
                    if ($result && $delete_stmt->rowCount() > 0) {
                        $success_message = "Xóa danh mục thành công!";
                        header("Location: categories.php?success=3");
                        exit;
                    } else {
                        $error_message = "Không tìm thấy danh mục để xóa!";
                    }
                }
            } catch(PDOException $e) {
                error_log("Error deleting category: " . $e->getMessage());
                $error_message = "Lỗi khi xóa danh mục: " . $e->getMessage();
            }
        } else {
            $error_message = "Không có ID danh mục!";
        }
    }
}

// Hiển thị thông báo từ URL parameter
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case '1':
            $success_message = "Thêm danh mục thành công!";
            break;
        case '2':
            $success_message = "Cập nhật danh mục thành công!";
            break;
        case '3':
            $success_message = "Xóa danh mục thành công!";
            break;
    }
}

// Lấy danh sách danh mục
try {
    $categories_stmt = $conn->prepare("
        SELECT dm.*, COUNT(sp.id) as so_luong_san_pham 
        FROM danh_muc dm 
        LEFT JOIN san_pham sp ON dm.id = sp.danh_muc_id 
        GROUP BY dm.id 
        ORDER BY dm.ngay_tao DESC
    ");
    $categories_stmt->execute();
    $danh_muc = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $danh_muc = [];
}

// Thống kê
$total_categories = count($danh_muc);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Danh mục - BlockChain Supply</title>
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

        .stat-info h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .stat-info p {
            font-size: 13px;
            color: #757575;
            font-weight: 500;
        }

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

        .section-header h2 {
            font-size: 16px;
            font-weight: 600;
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

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }

        .btn-success {
            background: var(--gradient-success);
            color: white;
        }

        .btn-danger {
            background: var(--gradient-danger);
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .table-container {
            padding: 0 20px 20px 20px;
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

        .action-buttons {
            display: flex;
            gap: 6px;
        }

        .action-btn {
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            border: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn.edit {
            background: rgba(33, 150, 243, 0.1);
            color: var(--primary);
        }

        .action-btn.delete {
            background: rgba(211, 47, 47, 0.1);
            color: var(--danger);
        }

        .action-btn:disabled {
            background: rgba(189, 189, 189, 0.1);
            color: #9e9e9e;
            cursor: not-allowed;
        }

        .action-btn:hover:not(:disabled) {
            transform: translateY(-1px);
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
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            background: var(--gradient-primary);
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

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9e9e9e;
        }

        .empty-state i {
            font-size: 40px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .product-count {
            background: rgba(33, 150, 243, 0.1);
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
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
                <a href="register.php" class="menu-item">
                    <i class="fas fa-box"></i>
                    <span>Sản phẩm</span>
                </a>
                <a href="categories.php" class="menu-item active">
                    <i class="fas fa-tags"></i>
                    <span>Danh mục</span>
                </a>
                <a href="order.php" class="menu-item">
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
                <h1>Quản lý Danh mục</h1>
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

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_categories; ?></h3>
                        <p>Tổng số danh mục</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo array_sum(array_column($danh_muc, 'so_luong_san_pham')); ?></h3>
                        <p>Tổng sản phẩm</p>
                    </div>
                </div>
            </div>

            <!-- Categories Section -->
            <div class="content-section">
                <div class="section-header">
                    <h2>Danh sách Danh mục</h2>
                    <button class="btn btn-primary" id="addCategoryBtn">
                        <i class="fas fa-plus"></i> Thêm Danh mục
                    </button>
                </div>
                <div class="table-container">
                    <?php if(isset($success_message)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(isset($error_message)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(empty($danh_muc)): ?>
                        <div class="empty-state">
                            <i class="fas fa-tags"></i>
                            <h3 style="font-size: 16px; margin-bottom: 8px; color: #616161;">Chưa có danh mục nào</h3>
                            <p style="font-size: 13px;">Bắt đầu bằng cách tạo danh mục đầu tiên của bạn</p>
                            <button class="btn btn-primary" id="addCategoryEmptyBtn" style="margin-top: 12px;">
                                <i class="fas fa-plus"></i> Thêm Danh mục đầu tiên
                            </button>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Tên danh mục</th>
                                    <th>Mô tả</th>
                                    <th>Số sản phẩm</th>
                                    <th>Ngày tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($danh_muc as $dm): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($dm['ten_danh_muc']); ?></div>
                                    </td>
                                    <td>
                                        <?php if(!empty($dm['mo_ta'])): ?>
                                            <div style="font-size: 11px; color: #757575;">
                                                <?php echo htmlspecialchars(substr($dm['mo_ta'], 0, 50)) . (strlen($dm['mo_ta']) > 50 ? '...' : ''); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #9e9e9e; font-size: 11px;">Không có mô tả</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="product-count"><?php echo $dm['so_luong_san_pham']; ?> SP</span>
                                    </td>
                                    <td style="font-size: 11px; color: #757575;">
                                        <?php echo date('d/m/Y', strtotime($dm['ngay_tao'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit edit-category-btn" 
                                                    data-id="<?php echo $dm['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($dm['ten_danh_muc']); ?>"
                                                    data-description="<?php echo htmlspecialchars($dm['mo_ta']); ?>">
                                                <i class="fas fa-edit"></i> Sửa
                                            </button>
                                            <button class="action-btn delete delete-category-btn" 
                                                    data-id="<?php echo $dm['id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($dm['ten_danh_muc']); ?>"
                                                    <?php echo $dm['so_luong_san_pham'] > 0 ? 'disabled title="Không thể xóa danh mục có sản phẩm"' : ''; ?>>
                                                <i class="fas fa-trash"></i> Xóa
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

    <!-- Add/Edit Category Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="close-modal">&times;</button>
                <h2 id="categoryModalTitle">Thêm Danh mục Mới</h2>
            </div>
            <div class="modal-body">
                <form id="categoryForm" method="POST">
                    <input type="hidden" name="action" id="formAction" value="add_category">
                    <input type="hidden" name="category_id" id="categoryId">
                    
                    <div class="form-group">
                        <label for="categoryName">Tên danh mục *</label>
                        <input type="text" class="form-control" id="categoryName" name="ten_danh_muc" required placeholder="Nhập tên danh mục">
                    </div>
                    
                    <div class="form-group">
                        <label for="categoryDescription">Mô tả</label>
                        <textarea class="form-control" id="categoryDescription" name="mo_ta" rows="3" placeholder="Mô tả về danh mục..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                        <button type="button" class="btn" style="background: #e0e0e0; color: #616161;" id="cancelCategoryBtn">Hủy</button>
                        <button type="submit" class="btn btn-primary" id="submitCategoryBtn">
                            <i class="fas fa-save"></i> Lưu Danh mục
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteConfirmationModal">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--gradient-danger);">
                <button class="close-modal">&times;</button>
                <h2><i class="fas fa-exclamation-triangle"></i> Xác nhận xóa</h2>
            </div>
            <div class="modal-body" style="text-align: center; padding: 20px;">
                <h3 style="margin-bottom: 12px; font-size: 16px;">Bạn có chắc chắn muốn xóa danh mục này?</h3>
                <p style="margin-bottom: 8px; color: #757575; font-size: 13px;">Danh mục: <strong id="categoryNameToDelete"></strong></p>
                <p style="margin-bottom: 20px; color: var(--danger); font-size: 12px;"><i class="fas fa-info-circle"></i> Hành động này không thể hoàn tác.</p>
                <form id="deleteCategoryForm" method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" id="categoryIdToDelete">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Xóa
                    </button>
                </form>
                <button class="btn" style="background: #e0e0e0; color: #616161; margin-left: 10px;" id="cancelDeleteBtn">
                    <i class="fas fa-times"></i> Hủy
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const categoryModal = document.getElementById('categoryModal');
            const deleteConfirmationModal = document.getElementById('deleteConfirmationModal');
            const addCategoryBtn = document.getElementById('addCategoryBtn');
            const addCategoryEmptyBtn = document.getElementById('addCategoryEmptyBtn');
            const closeModals = document.querySelectorAll('.close-modal');
            const cancelCategoryBtn = document.getElementById('cancelCategoryBtn');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
            
            // Form elements
            const categoryForm = document.getElementById('categoryForm');
            const deleteCategoryForm = document.getElementById('deleteCategoryForm');
            const categoryModalTitle = document.getElementById('categoryModalTitle');
            const formAction = document.getElementById('formAction');
            const categoryId = document.getElementById('categoryId');
            const categoryName = document.getElementById('categoryName');
            const categoryDescription = document.getElementById('categoryDescription');
            
            // Add category modal triggers
            if (addCategoryBtn) {
                addCategoryBtn.addEventListener('click', function() {
                    resetCategoryForm();
                    categoryModal.style.display = 'block';
                });
            }
            
            if (addCategoryEmptyBtn) {
                addCategoryEmptyBtn.addEventListener('click', function() {
                    resetCategoryForm();
                    categoryModal.style.display = 'block';
                });
            }
            
            // Edit category buttons
            const editCategoryBtns = document.querySelectorAll('.edit-category-btn');
            editCategoryBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const categoryData = {
                        id: this.getAttribute('data-id'),
                        name: this.getAttribute('data-name'),
                        description: this.getAttribute('data-description')
                    };
                    
                    openEditCategoryModal(categoryData);
                });
            });
            
            // Delete category buttons
            const deleteCategoryBtns = document.querySelectorAll('.delete-category-btn:not(:disabled)');
            deleteCategoryBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const categoryId = this.getAttribute('data-id');
                    const categoryName = this.getAttribute('data-name');
                    
                    document.getElementById('categoryIdToDelete').value = categoryId;
                    document.getElementById('categoryNameToDelete').textContent = categoryName;
                    deleteConfirmationModal.style.display = 'block';
                });
            });
            
            // Close modals
            closeModals.forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    categoryModal.style.display = 'none';
                    deleteConfirmationModal.style.display = 'none';
                });
            });
            
            if (cancelCategoryBtn) {
                cancelCategoryBtn.addEventListener('click', function() {
                    categoryModal.style.display = 'none';
                });
            }
            
            if (cancelDeleteBtn) {
                cancelDeleteBtn.addEventListener('click', function() {
                    deleteConfirmationModal.style.display = 'none';
                });
            }
            
            // Close modals when clicking outside
            window.addEventListener('click', function(e) {
                if (e.target === categoryModal) {
                    categoryModal.style.display = 'none';
                }
                if (e.target === deleteConfirmationModal) {
                    deleteConfirmationModal.style.display = 'none';
                }
            });
            
            // Functions
            function resetCategoryForm() {
                categoryForm.reset();
                formAction.value = 'add_category';
                categoryModalTitle.textContent = 'Thêm Danh mục Mới';
                categoryId.value = '';
            }
            
            function openEditCategoryModal(categoryData) {
                formAction.value = 'update_category';
                categoryModalTitle.textContent = 'Sửa Danh mục';
                categoryId.value = categoryData.id;
                categoryName.value = categoryData.name;
                categoryDescription.value = categoryData.description;
                
                categoryModal.style.display = 'block';
            }
            
            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 5000);
            });
        });
    </script>
</body>
</html>