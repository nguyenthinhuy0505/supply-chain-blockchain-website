<?php
session_start();
require_once 'db.php';

// Khởi tạo kết nối database
$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("Lỗi kết nối database. Vui lòng thử lại sau.");
}

// Xử lý đăng nhập
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $vai_tro = $_POST['vai_tro'] ?? '';
    
    // Validate cơ bản
    if (empty($email) || empty($password) || empty($vai_tro)) {
        $login_error = "Vui lòng điền đầy đủ thông tin!";
    } else {
        try {
            $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE email = :email AND vai_tro = :vai_tro AND trang_thai = 'active'");
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':vai_tro', $vai_tro);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_info'] = $user;
                    
                    // Cập nhật thời gian đăng nhập cuối
                    $update_stmt = $conn->prepare("UPDATE nguoi_dung SET lan_dang_nhap_cuoi = NOW() WHERE id = :id");
                    $update_stmt->bindParam(':id', $user['id']);
                    $update_stmt->execute();
                    
                    // Redirect to appropriate dashboard
                    switch($user['vai_tro']) {
                        case 'admin':
                            header("Location: admin/admin.php");
                            exit;
                        case 'nha_san_xuat':
                            header("Location: nha_san_xuat/nha_san_xuat.php");
                            exit;
                        case 'moi_gioi':
                            header("Location: moi_gioi/moi_gioi.php");
                            exit;
                        case 'van_chuyen':
                            header("Location:van_chuyen/van_chuyen.php");
                            exit;
                        case 'khach_hang':
                            header("Location: khach_hang/khach_hang.php");
                            exit;
                        default:
                            $login_error = "Vai trò không hợp lệ!";
                            break;
                    }
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
    
    // Validate dữ liệu
    $register_error = '';
    
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
    
    // Nếu không có lỗi validation, tiến hành đăng ký
    if (empty($register_error)) {
        try {
            // Kiểm tra email đã tồn tại chưa
            $check_stmt = $conn->prepare("SELECT id FROM nguoi_dung WHERE email = :email");
            $check_stmt->bindParam(':email', $email);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $register_error = "Email đã tồn tại trong hệ thống!";
            } else {
                // Kiểm tra địa chỉ ví đã tồn tại chưa
                $check_wallet_stmt = $conn->prepare("SELECT id FROM nguoi_dung WHERE dia_chi_vi = :dia_chi_vi");
                $check_wallet_stmt->bindParam(':dia_chi_vi', $dia_chi_vi);
                $check_wallet_stmt->execute();
                
                if ($check_wallet_stmt->rowCount() > 0) {
                    $register_error = "Địa chỉ ví đã được sử dụng!";
                } else {
                    // Mã hóa mật khẩu
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Thêm người dùng mới
                    $insert_stmt = $conn->prepare("INSERT INTO nguoi_dung (ten_nguoi_dung, email, password, so_dien_thoai, vai_tro, dia_chi_vi, ngay_tao, trang_thai) 
                                                 VALUES (:ten_nguoi_dung, :email, :password, :so_dien_thoai, :vai_tro, :dia_chi_vi, NOW(), 'active')");
                    
                    $insert_stmt->bindParam(':ten_nguoi_dung', $ten_nguoi_dung);
                    $insert_stmt->bindParam(':email', $email);
                    $insert_stmt->bindParam(':password', $hashed_password);
                    $insert_stmt->bindParam(':so_dien_thoai', $so_dien_thoai);
                    $insert_stmt->bindParam(':vai_tro', $vai_tro);
                    $insert_stmt->bindParam(':dia_chi_vi', $dia_chi_vi);
                    
                    if ($insert_stmt->execute()) {
                        $register_success = "Đăng ký thành công! Vui lòng đăng nhập.";
                        // Reset form values
                        $_POST = array();
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

// Lấy thông tin user từ session nếu có
$currentUser = isset($_SESSION['user_info']) ? $_SESSION['user_info'] : null;
?>