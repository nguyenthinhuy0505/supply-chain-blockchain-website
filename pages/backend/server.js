// backend/server.js
const express = require('express');
const mysql = require('mysql2');
const cors = require('cors');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');

const app = express();
const PORT = 3000;

// Middleware
app.use(cors());
app.use(express.json());

// Kết nối MySQL (XAMPP)
const db = mysql.createConnection({
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'supply_chain', // Tên database đã có sẵn
    port: 3306
});

// Kết nối database
db.connect((err) => {
    if (err) {
        console.error('❌ Database connection failed: ', err);
        console.log('💡 Please make sure:');
        console.log('1. XAMPP is running');
        console.log('2. MySQL service is started');
        console.log('3. Database "supply_chain" exists');
        return;
    }
    console.log('✅ Connected to MySQL database (XAMPP)');
});

// Routes

// Health check
app.get('/api/health', (req, res) => {
    res.json({ 
        status: 'connected', 
        message: 'Server is running',
        database: 'MySQL (XAMPP)'
    });
});

// Đăng ký người dùng
app.post('/api/users/register', async (req, res) => {
    try {
        const {
            dia_chi_vi,
            ten_nguoi_dung,
            email,
            password,
            vai_tro,
            so_dien_thoai,
            dia_chi
        } = req.body;

        console.log('📝 Registration attempt:', { email, vai_tro });

        // Kiểm tra email đã tồn tại
        const checkEmailQuery = 'SELECT id FROM nguoi_dung WHERE email = ?';
        db.query(checkEmailQuery, [email], async (err, results) => {
            if (err) {
                console.error('Database error:', err);
                return res.status(500).json({ error: 'Lỗi database' });
            }

            if (results.length > 0) {
                return res.status(400).json({ error: 'Email đã tồn tại trong hệ thống' });
            }

            // Hash password
            const hashedPassword = await bcrypt.hash(password, 10);

            // Tạo user mới
            const insertQuery = `
                INSERT INTO nguoi_dung 
                (dia_chi_vi, ten_nguoi_dung, email, password, vai_tro, so_dien_thoai, dia_chi) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            `;

            db.query(insertQuery, [
                dia_chi_vi || '',
                ten_nguoi_dung,
                email,
                hashedPassword,
                vai_tro,
                so_dien_thoai,
                dia_chi || ''
            ], (err, results) => {
                if (err) {
                    console.error('Error creating user:', err);
                    return res.status(500).json({ error: 'Lỗi tạo người dùng' });
                }

                console.log('✅ User registered successfully:', results.insertId);
                res.json({
                    success: true,
                    message: 'Đăng ký thành công',
                    userId: results.insertId
                });
            });
        });
    } catch (error) {
        console.error('Server error:', error);
        res.status(500).json({ error: 'Lỗi server' });
    }
});

// Đăng nhập
app.post('/api/users/login', (req, res) => {
    const { email, password } = req.body;

    console.log('🔐 Login attempt:', email);

    const query = 'SELECT * FROM nguoi_dung WHERE email = ?';
    
    db.query(query, [email], async (err, results) => {
        if (err) {
            console.error('Database error:', err);
            return res.status(500).json({ error: 'Lỗi database' });
        }

        if (results.length === 0) {
            return res.status(401).json({ error: 'Email hoặc mật khẩu không đúng' });
        }

        const user = results[0];

        // Kiểm tra password
        const isPasswordValid = await bcrypt.compare(password, user.password);
        if (!isPasswordValid) {
            return res.status(401).json({ error: 'Email hoặc mật khẩu không đúng' });
        }

        // Cập nhật last_login
        db.query('UPDATE nguoi_dung SET last_login = NOW() WHERE id = ?', [user.id]);

        // Tạo JWT token
        const token = jwt.sign(
            { 
                userId: user.id, 
                email: user.email, 
                vai_tro: user.vai_tro 
            },
            'blockchain-supply-secret-key',
            { expiresIn: '24h' }
        );

        console.log('✅ User logged in:', user.email);

        res.json({
            success: true,
            message: 'Đăng nhập thành công',
            token,
            user: {
                id: user.id,
                ten_nguoi_dung: user.ten_nguoi_dung,
                email: user.email,
                vai_tro: user.vai_tro,
                so_dien_thoai: user.so_dien_thoai,
                dia_chi: user.dia_chi,
                dia_chi_vi: user.dia_chi_vi
            }
        });
    });
});

// Lấy thông tin user
app.get('/api/users/:id', authenticateToken, (req, res) => {
    const userId = req.params.id;

    const query = 'SELECT id, ten_nguoi_dung, email, vai_tro, so_dien_thoai, dia_chi, dia_chi_vi FROM nguoi_dung WHERE id = ?';
    
    db.query(query, [userId], (err, results) => {
        if (err) {
            return res.status(500).json({ error: 'Database error' });
        }

        if (results.length === 0) {
            return res.status(404).json({ error: 'User not found' });
        }

        res.json({ success: true, user: results[0] });
    });
});

// Lấy tất cả users (cho admin)
app.get('/api/users', authenticateToken, (req, res) => {
    // Kiểm tra nếu user là admin
    if (req.user.vai_tro !== 'admin') {
        return res.status(403).json({ error: 'Không có quyền truy cập' });
    }

    const query = 'SELECT id, ten_nguoi_dung, email, vai_tro, trang_thai, ngay_tao FROM nguoi_dung';
    
    db.query(query, (err, results) => {
        if (err) {
            return res.status(500).json({ error: 'Database error' });
        }

        res.json({ success: true, users: results });
    });
});

// Cập nhật thông tin user
app.put('/api/users/:id', authenticateToken, (req, res) => {
    const userId = req.params.id;
    const { ten_nguoi_dung, so_dien_thoai, dia_chi } = req.body;

    // Kiểm tra quyền (chỉ được cập nhật thông tin của chính mình hoặc admin)
    if (req.user.userId != userId && req.user.vai_tro !== 'admin') {
        return res.status(403).json({ error: 'Không có quyền cập nhật thông tin người khác' });
    }

    const query = 'UPDATE nguoi_dung SET ten_nguoi_dung = ?, so_dien_thoai = ?, dia_chi = ? WHERE id = ?';
    
    db.query(query, [ten_nguoi_dung, so_dien_thoai, dia_chi, userId], (err, results) => {
        if (err) {
            return res.status(500).json({ error: 'Database error' });
        }

        res.json({ success: true, message: 'Cập nhật thông tin thành công' });
    });
});

// Thêm sản phẩm
app.post('/api/products', authenticateToken, (req, res) => {
    const { ten_san_pham, mo_ta, gia_ban } = req.body;
    
    const query = 'INSERT INTO san_pham (ten_san_pham, mo_ta, gia_ban, nguoi_tao_id) VALUES (?, ?, ?, ?)';
    
    db.query(query, [ten_san_pham, mo_ta, gia_ban, req.user.userId], (err, results) => {
        if (err) {
            console.error('Error creating product:', err);
            return res.status(500).json({ error: 'Lỗi tạo sản phẩm' });
        }

        res.json({ 
            success: true, 
            message: 'Thêm sản phẩm thành công', 
            productId: results.insertId 
        });
    });
});

// Lấy tất cả sản phẩm
app.get('/api/products', authenticateToken, (req, res) => {
    const query = `
        SELECT sp.*, nd.ten_nguoi_dung 
        FROM san_pham sp 
        JOIN nguoi_dung nd ON sp.nguoi_tao_id = nd.id 
        WHERE sp.trang_thai = 'active'
    `;
    
    db.query(query, (err, results) => {
        if (err) {
            console.error('Error fetching products:', err);
            return res.status(500).json({ error: 'Lỗi lấy danh sách sản phẩm' });
        }

        res.json({ success: true, products: results });
    });
});

// Lấy sản phẩm theo ID
app.get('/api/products/:id', authenticateToken, (req, res) => {
    const productId = req.params.id;
    
    const query = `
        SELECT sp.*, nd.ten_nguoi_dung, nd.email, nd.so_dien_thoai
        FROM san_pham sp 
        JOIN nguoi_dung nd ON sp.nguoi_tao_id = nd.id 
        WHERE sp.id = ?
    `;
    
    db.query(query, [productId], (err, results) => {
        if (err) {
            console.error('Error fetching product:', err);
            return res.status(500).json({ error: 'Lỗi lấy thông tin sản phẩm' });
        }

        if (results.length === 0) {
            return res.status(404).json({ error: 'Sản phẩm không tồn tại' });
        }

        res.json({ success: true, product: results[0] });
    });
});

// Tạo giao dịch
app.post('/api/transactions', authenticateToken, (req, res) => {
    const { san_pham_id, nguoi_mua_id, gia, hash_blockchain } = req.body;
    
    const query = `
        INSERT INTO giao_dich 
        (san_pham_id, nguoi_ban_id, nguoi_mua_id, gia, hash_blockchain) 
        VALUES (?, ?, ?, ?, ?)
    `;
    
    db.query(query, [
        san_pham_id, 
        req.user.userId, // Người bán là user hiện tại
        nguoi_mua_id, 
        gia, 
        hash_blockchain || ''
    ], (err, results) => {
        if (err) {
            console.error('Error creating transaction:', err);
            return res.status(500).json({ error: 'Lỗi tạo giao dịch' });
        }

        res.json({ 
            success: true, 
            message: 'Tạo giao dịch thành công', 
            transactionId: results.insertId 
        });
    });
});

// Middleware xác thực JWT
function authenticateToken(req, res, next) {
    const authHeader = req.headers['authorization'];
    const token = authHeader && authHeader.split(' ')[1];

    if (!token) {
        return res.status(401).json({ error: 'Access token required' });
    }

    jwt.verify(token, 'blockchain-supply-secret-key', (err, user) => {
        if (err) {
            return res.status(403).json({ error: 'Invalid token' });
        }
        req.user = user;
        next();
    });
}

// Xử lý lỗi không tìm thấy route
app.use('*', (req, res) => {
    res.status(404).json({ error: 'Route not found' });
});

// Xử lý lỗi chung
app.use((err, req, res, next) => {
    console.error('Unhandled error:', err);
    res.status(500).json({ error: 'Internal server error' });
});

app.listen(PORT, () => {
    console.log(`🚀 Server running on port ${PORT}`);
    console.log(`📊 API available at: http://localhost:${PORT}/api`);
    console.log(`🔍 Health check: http://localhost:${PORT}/api/health`);
});