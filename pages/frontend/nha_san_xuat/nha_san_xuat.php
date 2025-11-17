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

// Lấy dữ liệu thống kê cho biểu đồ
try {
    // Tổng số sản phẩm
    $total_stmt = $conn->prepare("SELECT COUNT(*) as total FROM san_pham WHERE nha_san_xuat_id = :id");
    $total_stmt->execute([':id' => $user['id']]);
    $total_products = $total_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Sản phẩm trên blockchain
    $blockchain_stmt = $conn->prepare("SELECT COUNT(*) as total FROM san_pham WHERE nha_san_xuat_id = :id AND blockchain_tx_hash IS NOT NULL");
    $blockchain_stmt->execute([':id' => $user['id']]);
    $blockchain_products = $blockchain_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Sản phẩm chờ xử lý
    $pending_products = $total_products - $blockchain_products;

    // Sản phẩm có giá
    $price_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT sp.id) as total 
        FROM san_pham sp 
        JOIN gia_san_pham gp ON sp.id = gp.san_pham_id 
        WHERE sp.nha_san_xuat_id = :id AND gp.trang_thai = 'active'
    ");
    $price_stmt->execute([':id' => $user['id']]);
    $products_with_price = $price_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Dữ liệu phân bố theo danh mục
    $category_stmt = $conn->prepare("
        SELECT dm.ten_danh_muc, COUNT(sp.id) as count 
        FROM san_pham sp 
        JOIN danh_muc dm ON sp.danh_muc_id = dm.id 
        WHERE sp.nha_san_xuat_id = :id 
        GROUP BY dm.id, dm.ten_danh_muc
    ");
    $category_stmt->execute([':id' => $user['id']]);
    $category_data = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dữ liệu theo tháng
    $monthly_stmt = $conn->prepare("
        SELECT 
            MONTH(ngay_tao) as month,
            COUNT(*) as count
        FROM san_pham 
        WHERE nha_san_xuat_id = :id AND YEAR(ngay_tao) = YEAR(CURDATE())
        GROUP BY MONTH(ngay_tao)
        ORDER BY month
    ");
    $monthly_stmt->execute([':id' => $user['id']]);
    $monthly_data_raw = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Chuẩn bị dữ liệu cho biểu đồ tháng
    $monthly_data = array_fill(0, 12, 0);
    foreach ($monthly_data_raw as $data) {
        $monthly_data[$data['month'] - 1] = $data['count'];
    }

    // Giá trị sản phẩm theo danh mục
    $value_stmt = $conn->prepare("
        SELECT 
            dm.ten_danh_muc,
            COALESCE(SUM(gp.gia_ban * sp.so_luong), 0) as total_value
        FROM san_pham sp 
        JOIN danh_muc dm ON sp.danh_muc_id = dm.id 
        LEFT JOIN gia_san_pham gp ON sp.id = gp.san_pham_id AND gp.trang_thai = 'active'
        WHERE sp.nha_san_xuat_id = :id 
        GROUP BY dm.id, dm.ten_danh_muc
    ");
    $value_stmt->execute([':id' => $user['id']]);
    $value_data = $value_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Doanh thu theo tháng
    $revenue_stmt = $conn->prepare("
        SELECT 
            MONTH(dh.ngay_tao) as month,
            COALESCE(SUM(ctdh.so_luong * ctdh.gia_ban), 0) as revenue
        FROM don_hang dh 
        JOIN chi_tiet_don_hang ctdh ON dh.id = ctdh.don_hang_id 
        JOIN san_pham sp ON ctdh.san_pham_id = sp.id 
        WHERE sp.nha_san_xuat_id = :id AND YEAR(dh.ngay_tao) = YEAR(CURDATE()) AND dh.trang_thai = 'completed'
        GROUP BY MONTH(dh.ngay_tao)
        ORDER BY month
    ");
    $revenue_stmt->execute([':id' => $user['id']]);
    $revenue_data_raw = $revenue_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Chuẩn bị dữ liệu doanh thu
    $revenue_data = array_fill(0, 12, 0);
    foreach ($revenue_data_raw as $data) {
        $revenue_data[$data['month'] - 1] = $data['revenue'] / 1000000; // Chuyển sang triệu VND
    }

    // Phân bố theo đơn vị tính
    $unit_stmt = $conn->prepare("
        SELECT don_vi_tinh, COUNT(*) as count 
        FROM san_pham 
        WHERE nha_san_xuat_id = :id 
        GROUP BY don_vi_tinh
    ");
    $unit_stmt->execute([':id' => $user['id']]);
    $unit_data = $unit_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    error_log("Error fetching chart data: " . $e->getMessage());
    // Dữ liệu mẫu nếu có lỗi
    $total_products = 0;
    $blockchain_products = 0;
    $pending_products = 0;
    $products_with_price = 0;
    $category_data = [];
    $monthly_data = array_fill(0, 12, 0);
    $value_data = [];
    $revenue_data = array_fill(0, 12, 0);
    $unit_data = [];
}

// Chuẩn bị dữ liệu cho JavaScript
$chart_data_json = [
    'categories' => array_column($category_data, 'ten_danh_muc'),
    'categoryCounts' => array_column($category_data, 'count'),
    'statusData' => [
        'blockchain' => $blockchain_products,
        'pending' => $pending_products
    ],
    'monthlyData' => $monthly_data,
    'valueData' => array_column($value_data, 'total_value'),
    'revenueData' => $revenue_data,
    'unitData' => array_column($unit_data, 'don_vi_tinh'),
    'unitCounts' => array_column($unit_data, 'count')
];
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Biểu Đồ - Nhà Sản Xuất</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 20px;
            transition: var(--transition);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .chart-header h3 {
            font-size: 14px;
            color: #616161;
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
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
            .charts-grid {
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
                <a href="nha_san_xuat.php" class="menu-item active">
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
                <h1>Dashboard Nhà Sản Xuất</h1>
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

            
         
            <!-- Charts Grid -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Phân bố sản phẩm theo danh mục</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Tình trạng sản phẩm</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Sản phẩm theo tháng</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Giá trị sản phẩm theo danh mục</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="valueChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Additional Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Doanh thu theo tháng (triệu VND)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Tỷ lệ sản phẩm theo đơn vị tính</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="unitChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Dữ liệu từ PHP
        const chartData = <?php echo json_encode($chart_data_json); ?>;

        // Đảm bảo tất cả mảng đều có dữ liệu
        const ensureArrayData = (data, defaultValue = 0) => {
            if (!data || data.length === 0) return [defaultValue];
            return data.map(item => item || defaultValue);
        };

        // Biểu đồ phân bố danh mục
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: ensureArrayData(chartData.categories, 'Không có dữ liệu'),
                datasets: [{
                    data: ensureArrayData(chartData.categoryCounts, 1),
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                        '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#9966FF'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });

        // Biểu đồ tình trạng sản phẩm
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: ['Blockchain', 'Chờ xử lý'],
                datasets: [{
                    data: [
                        chartData.statusData?.blockchain || 0,
                        chartData.statusData?.pending || 0
                    ],
                    backgroundColor: ['#4CAF50', '#FF9800'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Biểu đồ sản phẩm theo tháng
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'],
                datasets: [{
                    label: 'Số sản phẩm',
                    data: ensureArrayData(chartData.monthlyData),
                    borderColor: '#1976d2',
                    backgroundColor: 'rgba(25, 118, 210, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Biểu đồ giá trị sản phẩm
        const valueCtx = document.getElementById('valueChart').getContext('2d');
        new Chart(valueCtx, {
            type: 'bar',
            data: {
                labels: ensureArrayData(chartData.categories, 'Không có dữ liệu'),
                datasets: [{
                    label: 'Giá trị (VND)',
                    data: ensureArrayData(chartData.valueData),
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(54, 162, 235, 0.7)',
                        'rgba(255, 206, 86, 0.7)',
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(153, 102, 255, 0.7)'
                    ],
                    borderColor: [
                        'rgb(255, 99, 132)',
                        'rgb(54, 162, 235)',
                        'rgb(255, 206, 86)',
                        'rgb(75, 192, 192)',
                        'rgb(153, 102, 255)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('vi-VN').format(value) + ' đ';
                            }
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

        // Biểu đồ doanh thu
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'],
                datasets: [{
                    label: 'Doanh thu (triệu VND)',
                    data: ensureArrayData(chartData.revenueData),
                    backgroundColor: 'rgba(76, 175, 80, 0.7)',
                    borderColor: 'rgb(76, 175, 80)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + ' tr';
                            }
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

        // Biểu đồ đơn vị tính
        const unitCtx = document.getElementById('unitChart').getContext('2d');
        new Chart(unitCtx, {
            type: 'polarArea',
            data: {
                labels: ensureArrayData(chartData.unitData, 'Không có dữ liệu'),
                datasets: [{
                    data: ensureArrayData(chartData.unitCounts, 1),
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF',
                        '#FF9F40'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });

        // Hiệu ứng cho các card
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card, .chart-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
</body>
</html>