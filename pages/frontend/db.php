<?php
class Database {
    private $host = "localhost";
    private $db_name = "supply_chain";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            return null;
        }
        return $this->conn;
    }
}
class BlockchainService {
    private $conn;
    private $web3;
    private $contract;
    private $contractAddress;
    private $adminPrivateKey;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->initializeBlockchain();
    }

    private function initializeBlockchain() {
        try {
            // Cấu hình RSK Testnet
            $rpcUrl = "https://public-node.testnet.rsk.co";
            
            // Load config từ database
            $stmt = $this->conn->prepare("SELECT * FROM blockchain_config WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config) {
                $this->contractAddress = $config['contract_address'];
                $this->adminPrivateKey = $config['admin_private_key']; // Trong thực tế nên mã hóa
                
                // Khởi tạo Web3 (cần cài đặt thư viện web3.php)
                // $this->web3 = new Web3\Web3($rpcUrl);
                // $this->contract = $this->loadContract();
            }
        } catch (Exception $e) {
            error_log("Blockchain init error: " . $e->getMessage());
        }
    }

    public function activateUserOnBlockchain($userData) {
        try {
            // Trong môi trường production, đây sẽ là transaction thực
            // Tạm thời giả lập transaction hash
            $txHash = '0x' . bin2hex(random_bytes(32));
            
            // Lưu transaction vào database
            $stmt = $this->conn->prepare("
                INSERT INTO blockchain_transactions 
                (user_id, transaction_hash, action, fee, status, created_at) 
                VALUES (:user_id, :tx_hash, 'activation', :fee, 'confirmed', NOW())
            ");
            
            $stmt->execute([
                ':user_id' => $userData['user_id'],
                ':tx_hash' => $txHash,
                ':fee' => 0.001
            ]);

            // Cập nhật thông tin user
            $updateStmt = $this->conn->prepare("
                UPDATE nguoi_dung 
                SET trang_thai = 'active', 
                    blockchain_tx_hash = :tx_hash,
                    blockchain_address = :address,
                    ngay_cap_nhat = NOW()
                WHERE id = :id
            ");
            
            $updateStmt->execute([
                ':tx_hash' => $txHash,
                ':address' => $userData['user_address'],
                ':id' => $userData['user_id']
            ]);

            return $txHash;

        } catch (Exception $e) {
            error_log("Blockchain activation error: " . $e->getMessage());
            return false;
        }
    }

    public function getBlockchainConfig() {
        $stmt = $this->conn->prepare("SELECT * FROM blockchain_config WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getBlockchainStats() {
        $stats = [
            'total_activations' => 0,
            'total_fees' => 0,
            'pending_transactions' => 0
        ];

        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_activations,
                    COALESCE(SUM(fee), 0) as total_fees
                FROM blockchain_transactions 
                WHERE status = 'confirmed'
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $stats['total_activations'] = $result['total_activations'];
                $stats['total_fees'] = $result['total_fees'];
            }

            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as pending 
                FROM blockchain_transactions 
                WHERE status = 'pending'
            ");
            $stmt->execute();
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['pending_transactions'] = $pending['pending'];

        } catch (Exception $e) {
            error_log("Blockchain stats error: " . $e->getMessage());
        }

        return $stats;
    }
}
?>