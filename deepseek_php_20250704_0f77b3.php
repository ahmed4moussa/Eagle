<?php
// config.php - ملف الإعدادات
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'customer_management_system');
define('BASE_URL', 'http://localhost/customer_management');

// db.php - ملف الاتصال بقاعدة البيانات
class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $name = DB_NAME;
    
    private $dbh;
    private $stmt;
    private $error;
    
    public function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->name};charset=utf8mb4";
        $options = [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        
        try {
            $this->dbh = new PDO($dsn, $this->user, $this->pass, $options);
        } catch(PDOException $e) {
            $this->error = $e->getMessage();
            die("فشل الاتصال بقاعدة البيانات: " . $this->error);
        }
    }
    
    public function query($sql) {
        $this->stmt = $this->dbh->prepare($sql);
    }
    
    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
    }
    
    public function execute() {
        return $this->stmt->execute();
    }
    
    public function resultSet() {
        $this->execute();
        return $this->stmt->fetchAll();
    }
    
    public function single() {
        $this->execute();
        return $this->stmt->fetch();
    }
    
    public function rowCount() {
        return $this->stmt->rowCount();
    }
    
    public function lastInsertId() {
        return $this->dbh->lastInsertId();
    }
}

// auth.php - نظام المصادقة
class Auth {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
        session_start();
    }
    
    public function register($data) {
        $this->db->query('INSERT INTO users (username, password, full_name, email, role) 
                         VALUES (:username, :password, :full_name, :email, :role)');
        
        $this->db->bind(':username', $data['username']);
        $this->db->bind(':password', password_hash($data['password'], PASSWORD_DEFAULT));
        $this->db->bind(':full_name', $data['full_name']);
        $this->db->bind(':email', $data['email']);
        $this->db->bind(':role', $data['role'] ?? 'employee');
        
        return $this->db->execute();
    }
    
    public function login($username, $password) {
        $this->db->query('SELECT * FROM users WHERE username = :username AND is_active = TRUE');
        $this->db->bind(':username', $username);
        
        $user = $this->db->single();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            // تحديث وقت آخر تسجيل دخول
            $this->db->query('UPDATE users SET last_login = NOW() WHERE id = :id');
            $this->db->bind(':id', $user['id']);
            $this->db->execute();
            
            return true;
        }
        
        return false;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function logout() {
        session_unset();
        session_destroy();
    }
    
    public function getUser() {
        if (!$this->isLoggedIn()) return null;
        
        $this->db->query('SELECT * FROM users WHERE id = :id');
        $this->db->bind(':id', $_SESSION['user_id']);
        return $this->db->single();
    }
    
    public function hasPermission($requiredRole) {
        if (!$this->isLoggedIn()) return false;
        
        $roles = ['employee' => 1, 'manager' => 2, 'admin' => 3];
        $userRole = $_SESSION['role'];
        
        return $roles[$userRole] >= $roles[$requiredRole];
    }
}

// customer.php - إدارة العملاء
class Customer {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function addCustomer($data) {
        $this->db->query('INSERT INTO customers 
                         (name, phone, email, address, type, has_debt, debt_amount, created_by) 
                         VALUES (:name, :phone, :email, :address, :type, :has_debt, :debt_amount, :created_by)');
        
        $this->db->bind(':name', $data['name']);
        $this->db->bind(':phone', $data['phone']);
        $this->db->bind(':email', $data['email']);
        $this->db->bind(':address', $data['address']);
        $this->db->bind(':type', $data['type']);
        $this->db->bind(':has_debt', $data['has_debt']);
        $this->db->bind(':debt_amount', $data['debt_amount'] ?? 0);
        $this->db->bind(':created_by', $_SESSION['user_id']);
        
        if ($this->db->execute()) {
            // إضافة إشعار إذا كان هناك ديون
            if ($data['has_debt']) {
                $this->addDebtNotification($this->db->lastInsertId(), $data['debt_amount']);
            }
            return true;
        }
        return false;
    }
    
    private function addDebtNotification($customerId, $amount) {
        $this->db->query('INSERT INTO notifications 
                         (user_id, title, message, type, related_id) 
                         VALUES (:user_id, :title, :message, :type, :related_id)');
        
        $this->db->bind(':user_id', $_SESSION['user_id']);
        $this->db->bind(':title', 'عميل جديد عليه ديون');
        $this->db->bind(':message', "تم إضافة عميل جديد عليه ديون بقيمة $amount ر.س");
        $this->db->bind(':type', 'debt');
        $this->db->bind(':related_id', $customerId);
        
        return $this->db->execute();
    }
    
    public function getCustomers($filters = []) {
        $sql = 'SELECT c.*, u.username as created_by_name 
                FROM customers c 
                JOIN users u ON c.created_by = u.id 
                WHERE 1=1';
        
        // تطبيق الفلاتر
        if (!empty($filters['debt'])) {
            $sql .= $filters['debt'] === 'with' ? ' AND c.has_debt = TRUE' : ' AND c.has_debt = FALSE';
        }
        
        if (!empty($filters['type'])) {
            $sql .= ' AND c.type = :type';
        }
        
        if (!empty($filters['search'])) {
            $sql .= ' AND (c.name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)';
        }
        
        $this->db->query($sql);
        
        if (!empty($filters['type'])) {
            $this->db->bind(':type', $filters['type']);
        }
        
        if (!empty($filters['search'])) {
            $this->db->bind(':search', '%' . $filters['search'] . '%');
        }
        
        return $this->db->resultSet();
    }
    
    public function getCustomerById($id) {
        $this->db->query('SELECT * FROM customers WHERE id = :id');
        $this->db->bind(':id', $id);
        return $this->db->single();
    }
    
    public function updateCustomer($data) {
        $this->db->query('UPDATE customers 
                         SET name = :name, phone = :phone, email = :email, address = :address, 
                             type = :type, has_debt = :has_debt, debt_amount = :debt_amount, 
                             updated_at = NOW() 
                         WHERE id = :id');
        
        $this->db->bind(':id', $data['id']);
        $this->db->bind(':name', $data['name']);
        $this->db->bind(':phone', $data['phone']);
        $this->db->bind(':email', $data['email']);
        $this->db->bind(':address', $data['address']);
        $this->db->bind(':type', $data['type']);
        $this->db->bind(':has_debt', $data['has_debt']);
        $this->db->bind(':debt_amount', $data['debt_amount'] ?? 0);
        
        return $this->db->execute();
    }
    
    public function deleteCustomer($id) {
        $this->db->query('DELETE FROM customers WHERE id = :id');
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }
    
    public function getDebtSummary() {
        $this->db->query('SELECT 
                          COUNT(*) as total_customers,
                          SUM(CASE WHEN has_debt = TRUE THEN 1 ELSE 0 END) as customers_with_debt,
                          SUM(debt_amount) as total_debt 
                          FROM customers');
        return $this->db->single();
    }
    
    public function getOverdueDebts() {
        $this->db->query('SELECT c.* FROM customers c 
                         JOIN invoices i ON c.id = i.customer_id 
                         WHERE i.due_date < CURDATE() AND i.status != "paid" 
                         GROUP BY c.id');
        return $this->db->resultSet();
    }
}

// invoice.php - إدارة الفواتير
class Invoice {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function createInvoice($data) {
        $this->db->query('INSERT INTO invoices 
                         (customer_id, invoice_number, amount, issued_date, due_date, status, created_by) 
                         VALUES (:customer_id, :invoice_number, :amount, :issued_date, :due_date, :status, :created_by)');
        
        $this->db->bind(':customer_id', $data['customer_id']);
        $this->db->bind(':invoice_number', $this->generateInvoiceNumber());
        $this->db->bind(':amount', $data['amount']);
        $this->db->bind(':issued_date', $data['issued_date']);
        $this->db->bind(':due_date', $data['due_date']);
        $this->db->bind(':status', 'unpaid');
        $this->db->bind(':created_by', $_SESSION['user_id']);
        
        if ($this->db->execute()) {
            $invoiceId = $this->db->lastInsertId();
            
            // تحديث حالة العميل إذا كان هناك ديون
            $this->updateCustomerDebtStatus($data['customer_id'], $data['amount']);
            
            return $invoiceId;
        }
        return false;
    }
    
    private function generateInvoiceNumber() {
        return 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
    
    private function updateCustomerDebtStatus($customerId, $amount) {
        $this->db->query('UPDATE customers 
                         SET has_debt = TRUE, debt_amount = debt_amount + :amount 
                         WHERE id = :id');
        
        $this->db->bind(':id', $customerId);
        $this->db->bind(':amount', $amount);
        return $this->db->execute();
    }
    
    public function recordPayment($data) {
        $this->db->query('INSERT INTO payments 
                         (invoice_id, amount, payment_date, payment_method, reference, notes, recorded_by) 
                         VALUES (:invoice_id, :amount, :payment_date, :payment_method, :reference, :notes, :recorded_by)');
        
        $this->db->bind(':invoice_id', $data['invoice_id']);
        $this->db->bind(':amount', $data['amount']);
        $this->db->bind(':payment_date', $data['payment_date']);
        $this->db->bind(':payment_method', $data['payment_method']);
        $this->db->bind(':reference', $data['reference']);
        $this->db->bind(':notes', $data['notes']);
        $this->db->bind(':recorded_by', $_SESSION['user_id']);
        
        if ($this->db->execute()) {
            $this->updateInvoiceStatus($data['invoice_id'], $data['amount']);
            return true;
        }
        return false;
    }
    
    private function updateInvoiceStatus($invoiceId, $paymentAmount) {
        $this->db->query('SELECT amount, paid_amount FROM invoices WHERE id = :id');
        $this->db->bind(':id', $invoiceId);
        $invoice = $this->db->single();
        
        $newPaidAmount = $invoice['paid_amount'] + $paymentAmount;
        $status = 'unpaid';
        
        if ($newPaidAmount >= $invoice['amount']) {
            $status = 'paid';
        } elseif ($newPaidAmount > 0) {
            $status = 'partial';
        }
        
        $this->db->query('UPDATE invoices 
                         SET paid_amount = :paid_amount, status = :status 
                         WHERE id = :id');
        
        $this->db->bind(':id', $invoiceId);
        $this->db->bind(':paid_amount', $newPaidAmount);
        $this->db->bind(':status', $status);
        return $this->db->execute();
    }
    
    public function getCustomerInvoices($customerId) {
        $this->db->query('SELECT * FROM invoices WHERE customer_id = :customer_id ORDER BY due_date DESC');
        $this->db->bind(':customer_id', $customerId);
        return $this->db->resultSet();
    }
    
    public function getOverdueInvoices() {
        $this->db->query('SELECT i.*, c.name as customer_name 
                         FROM invoices i 
                         JOIN customers c ON i.customer_id = c.id 
                         WHERE i.due_date < CURDATE() AND i.status != "paid" 
                         ORDER BY i.due_date ASC');
        return $this->db->resultSet();
    }
    
    public function getInvoicePayments($invoiceId) {
        $this->db->query('SELECT p.*, u.username as recorded_by_name 
                         FROM payments p 
                         JOIN users u ON p.recorded_by = u.id 
                         WHERE p.invoice_id = :invoice_id 
                         ORDER BY p.payment_date DESC');
        $this->db->bind(':invoice_id', $invoiceId);
        return $this->db->resultSet();
    }
}

// report.php - التقارير
class Report {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function getDebtReport() {
        $this->db->query('SELECT c.*, SUM(i.amount - i.paid_amount) as total_debt 
                         FROM customers c 
                         LEFT JOIN invoices i ON c.id = i.customer_id 
                         WHERE i.status != "paid" OR i.status IS NULL
                         GROUP BY c.id 
                         HAVING total_debt > 0 
                         ORDER BY total_debt DESC');
        return $this->db->resultSet();
    }
    
    public function getPaymentReport($startDate, $endDate) {
        $this->db->query('SELECT p.*, c.name as customer_name, u.username as recorded_by_name 
                         FROM payments p 
                         JOIN invoices i ON p.invoice_id = i.id 
                         JOIN customers c ON i.customer_id = c.id 
                         JOIN users u ON p.recorded_by = u.id 
                         WHERE p.payment_date BETWEEN :start_date AND :end_date 
                         ORDER BY p.payment_date DESC');
        
        $this->db->bind(':start_date', $startDate);
        $this->db->bind(':end_date', $endDate);
        return $this->db->resultSet();
    }
    
    public function getCustomerActivityReport($customerId) {
        $this->db->query('SELECT i.*, 
                         (SELECT SUM(amount) FROM payments WHERE invoice_id = i.id) as total_paid,
                         (i.amount - (SELECT IFNULL(SUM(amount), 0) FROM payments WHERE invoice_id = i.id)) as remaining 
                         FROM invoices i 
                         WHERE i.customer_id = :customer_id 
                         ORDER BY i.issued_date DESC');
        
        $this->db->bind(':customer_id', $customerId);
        return $this->db->resultSet();
    }
}

// notification.php - إدارة الإشعارات
class Notification {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function getUserNotifications($userId, $unreadOnly = false) {
        $sql = 'SELECT * FROM notifications WHERE user_id = :user_id';
        
        if ($unreadOnly) {
            $sql .= ' AND is_read = FALSE';
        }
        
        $sql .= ' ORDER BY created_at DESC LIMIT 10';
        
        $this->db->query($sql);
        $this->db->bind(':user_id', $userId);
        return $this->db->resultSet();
    }
    
    public function markAsRead($notificationId) {
        $this->db->query('UPDATE notifications SET is_read = TRUE WHERE id = :id');
        $this->db->bind(':id', $notificationId);
        return $this->db->execute();
    }
    
    public function createNotification($userId, $title, $message, $type, $relatedId = null) {
        $this->db->query('INSERT INTO notifications 
                         (user_id, title, message, type, related_id) 
                         VALUES (:user_id, :title, :message, :type, :related_id)');
        
        $this->db->bind(':user_id', $userId);
        $this->db->bind(':title', $title);
        $this->db->bind(':message', $message);
        $this->db->bind(':type', $type);
        $this->db->bind(':related_id', $relatedId);
        
        return $this->db->execute();
    }
    
    public function checkOverdueInvoices() {
        $this->db->query('SELECT i.id, i.customer_id, c.name as customer_name, 
                         DATEDIFF(CURDATE(), i.due_date) as days_overdue 
                         FROM invoices i 
                         JOIN customers c ON i.customer_id = c.id 
                         WHERE i.due_date < CURDATE() AND i.status != "paid" 
                         AND i.id NOT IN (
                             SELECT related_id FROM notifications 
                             WHERE type = "debt" AND DATE(created_at) = CURDATE()
                         )');
        
        $overdueInvoices = $this->db->resultSet();
        
        foreach ($overdueInvoices as $invoice) {
            $title = "فاتورة متأخرة: " . $invoice['customer_name'];
            $message = "فاتورة متأخرة منذ " . $invoice['days_overdue'] . " أيام";
            
            $this->createNotification(
                $_SESSION['user_id'], 
                $title, 
                $message, 
                'debt', 
                $invoice['id']
            );
        }
        
        return count($overdueInvoices);
    }
}