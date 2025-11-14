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
                    // Kiểm tra xem danh mục có danh mục con không
                    $check_child_stmt = $conn->prepare("SELECT COUNT(*) FROM danh_muc WHERE danh_muc_cha_id = :danh_muc_cha_id");
                    $check_child_stmt->execute([':danh_muc_cha_id' => $category_id]);
                    $child_count = $check_child_stmt->fetchColumn();
                    
                    if ($child_count > 0) {
                        $error_message = "Không thể xóa danh mục vì có danh mục con! Hãy xóa hoặc chuyển các danh mục con trước.";
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
                }
            } catch(PDOException $e) {
                error_log("Error deleting category: " . $e->getMessage());
                // Xử lý lỗi constraint cụ thể
                if (strpos($e->getMessage(), '1451') !== false) {
                    $error_message = "Không thể xóa danh mục vì có danh mục con đang tham chiếu đến nó!";
                } else {
                    $error_message = "Lỗi khi xóa danh mục: " . $e->getMessage();
                }
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

// Lấy danh sách danh mục với thông tin danh mục cha
try {
    $categories_stmt = $conn->prepare("
        SELECT dm.*, dm_cha.ten_danh_muc as ten_danh_muc_cha 
        FROM danh_muc dm 
        LEFT JOIN danh_muc dm_cha ON dm.danh_muc_cha_id = dm_cha.id 
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
        /* CSS giữ nguyên như trước */
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
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background: var(--dark);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            background: rgba(255, 255, 255, 0.1);
            text-align: center;
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
            border-left: 3px solid transparent;
        }
        
        .menu-item:hover, .menu-item.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--accent);
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 20px;
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
        }
        
        .content-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .section-header {
            padding: 20px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: var(--dark);
            border: 1px solid #dee2e6;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .table-container {
            padding: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
        }
        
        .action-btn {
            padding: 5px 8px;
            border-radius: 5px;
            font-size: 11px;
            cursor: pointer;
            border: none;
        }
        
        .action-btn.edit {
            background: #e7f3ff;
            color: var(--primary);
        }
        
        .action-btn.delete {
            background: #f8d7da;
            color: var(--danger);
        }
        
        .action-btn.delete:disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
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
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            background: var(--gradient-primary);
            color: white;
            padding: 20px 25px;
            text-align: center;
            position: relative;
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
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
        }
        
        .alert {
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 15px;
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
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        
        .confirmation-modal {
            text-align: center;
        }
        
        .category-hierarchy {
            font-size: 12px;
            color: var(--gray);
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Nhà Sản Xuất</h2>
                <p><?php echo htmlspecialchars($nha_san_xuat['ten_nguoi_dung']); ?></p>
            </div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tổng quan</span>
                </a>
                <a href="products.php" class="menu-item">
                    <i class="fas fa-box"></i>
                    <span>Sản phẩm</span>
                </a>
                <a href="categories.php" class="menu-item active">
                    <i class="fas fa-tags"></i>
                    <span>Danh mục</span>
                </a>
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-industry"></i>
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
                        <div class="alert alert-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($error_message)): ?>
                        <div class="alert alert-error"><?php echo $error_message; ?></div>
                    <?php endif; ?>
                    
                    <?php if(empty($danh_muc)): ?>
                        <div class="empty-state">
                            <i class="fas fa-tags"></i>
                            <p>Chưa có danh mục nào. Hãy tạo danh mục đầu tiên!</p>
                            <button class="btn btn-primary" id="addCategoryEmptyBtn">
                                <i class="fas fa-plus"></i> Thêm Danh mục
                            </button>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tên danh mục</th>
                                    <th>Mô tả</th>
                                    
                                    <th>Ngày tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($danh_muc as $dm): 
                                    // Kiểm tra xem danh mục có danh mục con không
                                    $has_children = false;
                                    $has_products = false;
                                    
                                    try {
                                        $check_children_stmt = $conn->prepare("SELECT COUNT(*) FROM danh_muc WHERE danh_muc_cha_id = :id");
                                        $check_children_stmt->execute([':id' => $dm['id']]);
                                        $has_children = $check_children_stmt->fetchColumn() > 0;
                                        
                                        $check_products_stmt = $conn->prepare("SELECT COUNT(*) FROM san_pham WHERE danh_muc_id = :id");
                                        $check_products_stmt->execute([':id' => $dm['id']]);
                                        $has_products = $check_products_stmt->fetchColumn() > 0;
                                    } catch(PDOException $e) {
                                        error_log("Error checking category constraints: " . $e->getMessage());
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dm['id']); ?></td>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($dm['ten_danh_muc']); ?></div>
                                        <?php if($has_children): ?>
                                            <div class="category-hierarchy">
                                                <i class="fas fa-sitemap"></i> Có danh mục con
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($dm['mo_ta'])): ?>
                                            <div title="<?php echo htmlspecialchars($dm['mo_ta']); ?>">
                                                <?php echo htmlspecialchars($dm['mo_ta']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-style: italic;">Không có mô tả</span>
                                        <?php endif; ?>
                                    </td>
                              
                                    <td><?php echo date('d/m/Y', strtotime($dm['ngay_tao'])); ?></td>
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
                                                    <?php echo ($has_children || $has_products) ? 'disabled title="Không thể xóa danh mục có danh mục con hoặc sản phẩm"' : ''; ?>>
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
                        <input type="text" class="form-control" id="categoryName" name="ten_danh_muc" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="categoryDescription">Mô tả</label>
                        <textarea class="form-control" id="categoryDescription" name="mo_ta" rows="3" placeholder="Mô tả về danh mục..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" id="cancelCategoryBtn">Hủy</button>
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
            <div class="modal-header">
                <button class="close-modal">&times;</button>
                <h2>Xác nhận xóa</h2>
            </div>
            <div class="modal-body confirmation-modal">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: var(--warning); margin-bottom: 20px;"></i>
                <h3>Xóa danh mục</h3>
                <p>Bạn có chắc chắn muốn xóa danh mục "<span id="categoryNameToDelete"></span>"?</p>
                <p style="color: var(--danger); font-size: 14px;"><i class="fas fa-info-circle"></i> Hành động này không thể hoàn tác.</p>
                <div class="confirmation-buttons">
                    <form id="deleteCategoryForm" method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="category_id" id="categoryIdToDelete">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Xóa
                        </button>
                    </form>
                    <button class="btn btn-secondary" id="cancelDeleteBtn">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                </div>
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
            
            // Edit category buttons - chỉ cho phép click nếu không disabled
            const editCategoryBtns = document.querySelectorAll('.edit-category-btn:not(:disabled)');
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
            
            // Delete category buttons - chỉ cho phép click nếu không disabled
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