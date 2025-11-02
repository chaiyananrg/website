<?php
// =========================================================================
// SECTION: CORS HEADERS (ต้องอยู่บนสุด และยืดหยุ่นที่สุด)
// =========================================================================
// บรรทัดนี้สำคัญที่สุด: อนุญาตให้ทุกโดเมนสามารถเรียก API นี้ได้
header("Access-Control-Allow-Origin: *"); 
// อนุญาต Method ต่างๆ ที่ใช้ในการ CRUD (Create, Read, Update, Delete)
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
// อนุญาต Header ต่างๆ ที่จำเป็นสำหรับการเรียกใช้ API
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
// บอกเบราว์เซอร์ให้แคชข้อมูล CORS เป็นเวลา 1 ชั่วโมง (3600 วินาที)
header("Access-Control-Max-Age: 3600");

// Handle OPTIONS request for preflight check. 
// If the request method is OPTIONS, we only send the headers and exit.
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// =========================================================================
// SECTION: Database Configuration & Connection (PHP)
// =========================================================================
// ตั้งค่า Timezone ให้เป็นประเทศไทย (UTC+7) สำหรับ PHP Script
date_default_timezone_set('Asia/Bangkok');

// **กรุณาเปลี่ยนค่าเหล่านี้ให้ถูกต้อง**
define('DB_HOST', 'sql211.infinityfree.com');
define('DB_USER', 'if0_40300010');
define('DB_PASS', 'pondshop');
define('DB_NAME', 'if0_40300010_pond');
define('DB_CHARSET', 'utf8mb4'); 

/**
 * Connects to the database using PDO.
 * @return PDO|null PDO object on success, null on failure.
 */
function connectDB() {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // 1. ตั้งค่าการเชื่อมต่อเพื่อรองรับภาษาไทยอย่างชัดเจน
        $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
        
        return $pdo;
    } catch (\PDOException $e) {
        // ในระบบจริงควร Log ข้อผิดพลาดแทนการแสดงออกสู่ภายนอก
        return null;
    }
}

/**
 * Handles the API call for adding data.
 * @param PDO $pdo The PDO database connection object.
 * @return array The API response data.
 */
function handleApiAdd($pdo) {
    // Check if the 'name' parameter (table name) is provided
    if (!isset($_GET['name']) || empty($_GET['name'])) {
        return ['status' => 'error', 'message' => 'Missing table name parameter: "name"'];
    }

    // Sanitize table name (IMPORTANT SECURITY STEP)
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['name']);

    // Collect all parameters starting from 1 (e.g., ?1=value1, ?2=value2, ...)
    $dataToInsert = [];
    $i = 1;
    while (isset($_GET[$i])) {
        // Use rawurldecode() to correctly handle Thai characters passed via URL
        $dataToInsert["param_{$i}"] = rawurldecode($_GET[$i]); 
        $i++;
    }

    if (empty($dataToInsert)) {
        return ['status' => 'error', 'message' => 'No data parameters (e.g., ?1=..., ?2=...) provided for insertion.'];
    }
    
    // ดึงเวลาปัจจุบันของประเทศไทย (UTC+7) ที่ตั้งค่าไว้ใน PHP
    $currentDateTime = date('Y-m-d H:i:s');
    
    $response = ['status' => 'success', 'message' => 'Data added successfully.', 'timestamp' => $currentDateTime, 'data_inserted' => $dataToInsert];

    try {
        // 1. Check/Add Columns Dynamically (Flexible Schema)
        $existingColumnsStmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}`");
        $existingColumnsStmt->execute();
        $existingColumns = $existingColumnsStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach (array_keys($dataToInsert) as $col) {
            if (!in_array($col, $existingColumns)) {
                // Add new column as VARCHAR(255) with UTF8mb4 support for Thai/Emoji
                $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$col}` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL");
                $response['schema_change'][] = "Added column '{$col}' to table '{$tableName}' with UTF8mb4 support.";
            }
        }

        // 2. Prepare and Execute the INSERT statement
        $cols = implode(', ', array_keys($dataToInsert));
        
        // เพิ่มคอลัมน์ created_at เข้าไปในการ INSERT
        $cols_with_timestamp = empty($cols) ? "`created_at`" : "`created_at`, {$cols}";
        
        // เพิ่ม Placeholder สำหรับ created_at ก่อน
        $placeholders = '?' . (empty($dataToInsert) ? '' : ', ' . implode(', ', array_fill(0, count($dataToInsert), '?')));
        
        // จัดเรียง Values โดยให้ created_at อยู่หน้าสุด
        $values = array_values($dataToInsert);
        array_unshift($values, $currentDateTime);

        $sql = "INSERT INTO `{$tableName}` ({$cols_with_timestamp}) VALUES ({$placeholders})"; 
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        $response['insert_id'] = $pdo->lastInsertId();

    } catch (\PDOException $e) {
        // If the table does not exist, attempt to create it
        if (strpos($e->getMessage(), 'Base table or view not found') !== false || strpos($e->getMessage(), 'no such table') !== false) {
            // New table creation with DATETIME and UTF8mb4 support
            $pdo->exec("CREATE TABLE `{$tableName}` (`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `created_at` DATETIME NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            // After creating, re-run the process (recursive call is acceptable here for simplicity)
            return handleApiAdd($pdo);
        }
        return ['status' => 'error', 'message' => 'Database error during insertion.', 'details' => $e->getMessage()];
    }

    return $response;
}

// =========================================================================
// SECTION: Main Router (PHP)
// =========================================================================
$dbStatus = 'Connect Failed';
$pdo = connectDB();
if ($pdo) {
    $dbStatus = 'Connect Success';
}

if (isset($_GET['api'])) {
    // ---------------------------------
    // API Call Execution Mode
    // ---------------------------------
    
    // ตั้งค่า Content-Type เป็น JSON
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$pdo) {
        http_response_code(503); // Service Unavailable
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed. Service is unavailable.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $action = strtolower($_GET['api']);
    $response = ['status' => 'error', 'message' => 'Invalid API action.'];

    switch ($action) {
        case 'add':
            $response = handleApiAdd($pdo);
            break;
        // Add more API actions here (e.g., 'get', 'update', 'delete')
    }

    // Set response code to 200 OK or appropriate error code
    if (isset($response['status']) && $response['status'] === 'error') {
        http_response_code(400); // Bad Request for API errors
    } else {
        http_response_code(200);
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;

} else {
    // ---------------------------------
    // Documentation/UI Display Mode
    // ---------------------------------
    // Continue to HTML/CSS/JS section below
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡️ Fox Shop API Documentation & Tester</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&family=Noto+Sans+Thai:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMDJ8Zc16i2Fh8Z+C7c8Vz5K7/8p6F+9+K8Xg72N/27xW/5f1l5lW5F3W3Y3/5W4Q==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
        /* [CSS Code (Same as previous, omitted for brevity)] */
        /* ========================================================================= */
        /* SECTION: Global & Variables Styles (CSS) */
        /* ========================================================================= */
        :root {
            --color-primary: #1e3a8a; /* Deep Blue */
            --color-secondary: #06b6d4; /* Cyan */
            --color-accent: #fcd34d; /* Amber/Yellow for highlight */
            --color-text: #e2e8f0; /* Light Gray/White */
            --color-bg-dark: #0f172a; /* Very Dark Blue/Black */
            --color-bg-light: #1e293b; /* Dark Blue */
            --color-success: #10b981; /* Emerald Green */
            --color-error: #ef4444; /* Red */
            --font-main: 'Poppins', 'Noto Sans Thai', sans-serif;
            --border-radius: 8px;
            --shadow-light: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--color-bg-dark);
            color: var(--color-text);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ========================================================================= */
        /* SECTION: Header & Status Styles */
        /* ========================================================================= */
        .header {
            background-color: var(--color-primary);
            padding: 20px 40px;
            box-shadow: var(--shadow-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            letter-spacing: 1px;
        }
        
        .header h1 span {
            color: var(--color-accent);
        }

        .db-status {
            padding: 5px 10px;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }

        .db-status i {
            margin-right: 5px;
            font-size: 1.1rem;
        }

        .status-success {
            background-color: var(--color-success);
        }

        .status-error {
            background-color: var(--color-error);
        }

        /* ========================================================================= */
        /* SECTION: Main Content Layout */
        /* ========================================================================= */
        .container {
            flex-grow: 1;
            max-width: 1200px;
            width: 100%;
            margin: 30px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }
        
        @media (min-width: 900px) {
            .container {
                grid-template-columns: 2fr 1fr; /* Doc on left, Tester on right */
            }
        }

        /* ========================================================================= */
        /* SECTION: Documentation Styles */
        /* ========================================================================= */
        .docs-section, .tester-section {
            background-color: var(--color-bg-light);
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
        }

        .docs-section h2, .tester-section h2 {
            font-size: 1.5rem;
            color: var(--color-secondary);
            border-bottom: 2px solid var(--color-secondary);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .endpoint-block {
            margin-bottom: 30px;
            border-left: 5px solid var(--color-accent);
            padding-left: 15px;
        }
        
        .endpoint-block h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--color-accent);
        }

        .method-tag {
            background-color: var(--color-success);
            color: var(--color-bg-dark);
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.8rem;
            margin-right: 10px;
        }

        .code-block {
            background-color: var(--color-bg-dark);
            border: 1px solid var(--color-secondary);
            padding: 15px;
            border-radius: var(--border-radius);
            position: relative;
            margin-top: 10px;
            font-family: monospace;
            overflow-x: auto;
        }

        .code-block code {
            color: var(--color-secondary);
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        .copy-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--color-primary);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .copy-btn:hover {
            background-color: var(--color-secondary);
        }

        .params-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        
        .params-table th, .params-table td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid var(--color-bg-dark);
        }

        .params-table th {
            background-color: var(--color-primary);
            font-weight: 600;
        }

        .required {
            color: var(--color-error);
            font-weight: 600;
        }
        
        .info-box {
            background-color: #334155; /* Blue-Gray 600 */
            padding: 15px;
            border-radius: var(--border-radius);
            margin-top: 15px;
            border-left: 5px solid var(--color-success);
        }
        
        .info-box p {
            margin: 0;
            font-size: 0.95rem;
        }


        /* ========================================================================= */
        /* SECTION: API Tester/Form Styles */
        /* ========================================================================= */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--color-text);
        }

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 10px;
            background-color: var(--color-bg-dark);
            border: 1px solid var(--color-primary);
            border-radius: var(--border-radius);
            color: var(--color-text);
            font-family: var(--font-main);
            transition: border-color 0.2s;
        }

        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--color-secondary);
        }

        .add-param-btn, .submit-btn {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
        }
        
        .add-param-btn {
            background-color: var(--color-secondary);
            color: var(--color-bg-dark);
            margin-right: 10px;
        }
        
        .add-param-btn:hover {
            background-color: #0d9488; /* Darker Cyan */
        }

        .submit-btn {
            background-color: var(--color-accent);
            color: var(--color-bg-dark);
            width: 100%;
            justify-content: center;
            margin-top: 20px;
        }
        
        .submit-btn:hover {
            background-color: #fbbf24; /* Darker Amber */
        }
        
        .submit-btn i {
            margin-right: 8px;
        }

        .api-output {
            margin-top: 20px;
        }

        .api-output pre {
            background-color: #1f2937;
            padding: 15px;
            border-radius: var(--border-radius);
            overflow-x: auto;
            color: #d1d5db;
        }

        .param-list {
            margin-bottom: 15px;
        }
        
        .param-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .param-item input {
            flex-grow: 1;
            margin-right: 10px;
        }

        .remove-param-btn {
            background: none;
            border: none;
            color: var(--color-error);
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        /* ========================================================================= */
        /* SECTION: Footer Styles */
        /* ========================================================================= */
        .footer {
            margin-top: 50px;
            padding: 20px;
            text-align: center;
            background-color: var(--color-bg-light);
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .footer a {
            color: var(--color-secondary);
            text-decoration: none;
        }
    </style>
</head>
<body>

    <header class="header">
        <h1>🦊 FOX <span>API</span> v1.0</h1>
        <?php
        $statusClass = ($dbStatus === 'Connect Success') ? 'status-success' : 'status-error';
        $statusIcon = ($dbStatus === 'Connect Success') ? 'fa-check-circle' : 'fa-times-circle';
        // แสดง Time Zone ในหน้า UI
        echo "<div class='db-status {$statusClass}' title='Database Time Zone: PHP controlled (Asia/Bangkok)'>";
        echo "<i class='fas {$statusIcon}'></i>{$dbStatus} (TH Time: +7)</div>";
        ?>
    </header>

    <div class="container">
        <section class="docs-section">
            <h2><i class="fas fa-book-open"></i> เอกสารประกอบการใช้งาน API</h2>
            
            <div class="info-box">
                <p><i class="fas fa-lock-open"></i> <strong>CORS (Cross-Origin Resource Sharing):</strong> API นี้ถูกตั้งค่าให้ **อนุญาตการเรียกใช้จากทุกโดเมนโดยไม่มีข้อจำกัด** (<code>Access-Control-Allow-Origin: *</code>)</p>
            </div>

            <p style="margin-top: 20px;">Fox Shop API ให้บริการ Endpoint ที่ยืดหยุ่นและง่ายต่อการใช้งานสำหรับการบันทึกข้อมูลเข้าสู่ตาราง SQL แบบ Dynamic ออกแบบมาให้ใช้งานด้วยคำขอ HTTP GET ที่เรียบง่าย</p>

            <div class="endpoint-block">
                <h3><span class="method-tag">GET</span> /add เพิ่มข้อมูล</h3>
                <p>เพิ่มข้อมูลใหม่ไปยังตาราง SQL ที่ระบุ คอลัมน์จะถูกสร้างโดยอัตโนมัติหากยังไม่มีอยู่ (รองรับภาษาไทย/UTF8mb4)</p>
                
                <h4>รูปแบบ URL ของ API:</h4>
                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode(this)">คัดลอก</button>
                    <code id="add-url-format">/api.php?api=add&name=[ชื่อตาราง]&1=[ค่า1]&2=[ค่า2]&...</code>
                </div>
                
                <h4>พารามิเตอร์:</h4>
                <table class="params-table">
                    <thead>
                        <tr>
                            <th>พารามิเตอร์</th>
                            <th>จำเป็น</th>
                            <th>คำอธิบาย</th>
                            <th>ตัวอย่าง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>api</code></td>
                            <td><span class="required">ใช่</span></td>
                            <td>การดำเนินการที่ต้องการ. ต้องเป็น <code>add</code>.</td>
                            <td><code>add</code></td>
                        </tr>
                        <tr>
                            <td><code>name</code></td>
                            <td><span class="required">ใช่</span></td>
                            <td>ชื่อตาราง SQL ที่ต้องการแทรกข้อมูล (ตารางจะถูกสร้างอัตโนมัติหากไม่มี)</td>
                            <td><code>user_events</code></td>
                        </tr>
                        <tr>
                            <td><code>1, 2, 3, ...</code></td>
                            <td><span class="required">ไม่</span></td>
                            <td>ข้อมูลที่จะแทรก พารามิเตอร์ <code>1, 2, 3</code> จะกลายเป็นคอลัมน์ <code>param_1, param_2, param_3</code> ในตาราง SQL</td>
                            <td><code>&1=สวัสดีครับ</code>, <code>&2=บันทึกสำเร็จ</code></td>
                        </tr>
                    </tbody>
                </table>

                <h4>ตัวอย่าง URL เต็ม:</h4>
                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode(this)">คัดลอก</button>
                    <code id="add-url-example">/api.php?api=add&name=th_log&1=ข้อมูลภาษาไทย&2=123456</code>
                </div>

                <h4>ตัวอย่างการตอบกลับเมื่อสำเร็จ (JSON):</h4>
                <div class="code-block">
                    <button class="copy-btn" onclick="copyCode(this)">คัดลอก</button>
                    <pre><code>{
    "status": "success",
    "message": "Data added successfully.",
    "timestamp": "<?php echo date('Y-m-d H:i:s'); ?>", 
    "data_inserted": {
        "param_1": "ข้อมูลภาษาไทย",
        "param_2": "123456"
    },
    "insert_id": "5"
}</code></pre>
                </div>
                
            </div>
            
            <p><strong>หมายเหตุฐานข้อมูล:</strong> ตาราง <code>[ชื่อตาราง]</code> จะถูกสร้างโดยอัตโนมัติหากไม่มีอยู่ คอลัมน์ <code>param_1, param_2, ...</code> จะถูกเพิ่มโดยอัตโนมัติเพื่อรองรับข้อมูลของคุณ และเวลาจะถูกบันทึกเป็น DATETIME ตามเวลาประเทศไทย (+7:00) ที่กำหนดโดย PHP โดยตรง</p>
        </section>

        <section class="tester-section">
            <h2><i class="fas fa-terminal"></i> ทดสอบ API</h2>

            <form id="api-tester-form">
                <div class="form-group">
                    <label for="table-name">ชื่อตาราง (พารามิเตอร์ <code>name</code>)</label>
                    <input type="text" id="table-name" value="my_thai_log" required>
                </div>
                
                <div class="form-group">
                    <label>พารามิเตอร์ข้อมูล (<code>1, 2, 3, ...</code>)</label>
                    <div id="param-list" class="param-list">
                        </div>
                    <button type="button" class="add-param-btn" id="add-param-btn">
                        <i class="fas fa-plus"></i> เพิ่มพารามิเตอร์
                    </button>
                </div>

                <div class="form-group">
                    <label for="full-url">URL API ที่สร้างขึ้น</label>
                    <input type="text" id="full-url" readonly title="นี่คือ URL ที่จะถูกเรียกใช้งาน">
                </div>

                <button type="submit" class="submit-btn" id="execute-btn">
                    <i class="fas fa-paper-plane"></i> เรียกใช้งาน API
                </button>
            </form>

            <div class="api-output">
                <h3>ผลตอบกลับจาก API</h3>
                <pre id="api-response-output">รอการเรียกใช้งาน...</pre>
            </div>
        </section>
    </div>

    <footer class="footer">
        Fox Shop API v1.0 | พัฒนาโดย สุดยอด AI เขียนโค้ด &copy; 2024.
    </footer>

    <script>
        // Use an IIFE for a clean scope
        (function() {
            const paramList = document.getElementById('param-list');
            const addParamBtn = document.getElementById('add-param-btn');
            const apiForm = document.getElementById('api-tester-form');
            const tableNameInput = document.getElementById('table-name');
            const fullUrlInput = document.getElementById('full-url');
            const responseOutput = document.getElementById('api-response-output');
            let paramCounter = 0; // Starts from 0, the actual parameter name will be paramCounter + 1

            /**
             * Generates a new parameter input field.
             * @param {string} value The default value for the input.
             */
            function addParamField(value = '') {
                paramCounter++;
                const paramIndex = paramCounter;
                
                const item = document.createElement('div');
                item.className = 'param-item';
                item.innerHTML = `
                    <input type="text" data-param-index="${paramIndex}" placeholder="${paramIndex}=ข้อมูลที่ต้องการบันทึก" value="${value}" required>
                    <button type="button" class="remove-param-btn" title="ลบพารามิเตอร์ ${paramIndex}">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                `;
                
                // Add event listener to remove button
                item.querySelector('.remove-param-btn').addEventListener('click', () => {
                    item.remove();
                    // Re-index all remaining parameters after removal
                    reindexParams();
                    updateApiUrl();
                });

                // Add event listener for dynamic URL update
                item.querySelector('input').addEventListener('input', updateApiUrl);
                
                paramList.appendChild(item);
                
                // Focus on the new input field
                item.querySelector('input').focus();
            }

            /**
             * Re-indexes the data-param-index and placeholder of remaining parameters
             * to ensure they are sequential (1, 2, 3, ...).
             */
            function reindexParams() {
                const items = paramList.querySelectorAll('.param-item input');
                paramCounter = 0; // Reset counter
                items.forEach((input, index) => {
                    const newIndex = index + 1;
                    input.dataset.paramIndex = newIndex;
                    input.placeholder = `${newIndex}=ข้อมูลที่ต้องการบันทึก`;
                    paramCounter = newIndex; // Update counter to the last index
                });
            }

            /**
             * Updates the generated full API URL in the input field.
             */
            function updateApiUrl() {
                const baseUrl = window.location.href.split('?')[0]; // Get the base URL (e.g., /api.php)
                let url = `${baseUrl}?api=add&name=${tableNameInput.value.trim()}`;
                
                const inputs = paramList.querySelectorAll('.param-item input');
                inputs.forEach(input => {
                    const index = input.dataset.paramIndex;
                    // ใช้ encodeURIComponent() เพื่อเข้ารหัสภาษาไทยใน URL
                    const value = encodeURIComponent(input.value.trim()); 
                    if (value) {
                        url += `&${index}=${value}`;
                    }
                });
                
                fullUrlInput.value = url;
            }

            /**
             * Handles the form submission to execute the API call.
             */
            async function handleFormSubmit(event) {
                event.preventDefault();
                
                const apiUrl = fullUrlInput.value;
                if (!apiUrl || !tableNameInput.value.trim()) {
                    responseOutput.textContent = 'ข้อผิดพลาด: ต้องระบุชื่อตาราง';
                    return;
                }

                responseOutput.textContent = 'กำลังเรียกใช้ API...';
                document.getElementById('execute-btn').disabled = true;

                try {
                    // Fetch API call (CORS is handled by the PHP headers)
                    const response = await fetch(apiUrl);
                    
                    // Check for HTTP errors (e.g., 400, 500)
                    if (!response.ok) {
                        const errorText = await response.text(); // Read as text in case it's not JSON
                        let errorData = { status: 'error', message: `API Call Failed (HTTP ${response.status})` };
                        try {
                            errorData = JSON.parse(errorText); // Try to parse as JSON
                        } catch (e) {
                            errorData.details = errorText; // If JSON fails, use raw text as details
                        }
                        
                        responseOutput.textContent = `API Call Failed (HTTP ${response.status}):\n${JSON.stringify(errorData, null, 4)}`;
                        responseOutput.style.color = 'var(--color-error)';
                        return;
                    }

                    const data = await response.json();

                    // Display the response JSON
                    responseOutput.textContent = JSON.stringify(data, null, 4);

                    // Highlight the status based on the response
                    if (data.status === 'success') {
                        responseOutput.style.color = 'var(--color-success)';
                    } else {
                        responseOutput.style.color = 'var(--color-error)';
                    }

                } catch (error) {
                    responseOutput.textContent = `การเรียก API ล้มเหลว: ${error.message}`;
                    responseOutput.style.color = 'var(--color-error)';
                } finally {
                    document.getElementById('execute-btn').disabled = false;
                }
            }
            
            /**
             * Copies the content of a code block to the clipboard.
             * @param {HTMLElement} button The copy button element.
             */
            window.copyCode = function(button) {
                const codeBlock = button.parentElement.querySelector('code, pre');
                const textToCopy = codeBlock.textContent || codeBlock.innerText;

                if (navigator.clipboard && window.isSecureContext) {
                    // Use modern Clipboard API
                    navigator.clipboard.writeText(textToCopy);
                } else {
                    // Fallback for older browsers
                    const textArea = document.createElement("textarea");
                    textArea.value = textToCopy;
                    textArea.style.position = "absolute";
                    textArea.style.left = "-9999px";
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    try {
                        document.execCommand('copy');
                    } catch (err) {
                        console.error('Fallback: Could not copy text', err);
                    }
                    document.body.removeChild(textArea);
                }
                
                // Give visual feedback
                button.textContent = 'คัดลอกแล้ว!';
                setTimeout(() => {
                    button.textContent = 'คัดลอก';
                }, 2000);
            }

            // =========================================================================
            // SECTION: Initial Setup & Event Listeners
            // =========================================================================
            
            // Initial parameters for demonstration
            addParamField('สวัสดีประเทศไทย'); 
            addParamField('บันทึกเวลา');

            // Event listeners
            addParamBtn.addEventListener('click', () => addParamField());
            tableNameInput.addEventListener('input', updateApiUrl);
            apiForm.addEventListener('submit', handleFormSubmit);

            // Initial URL update
            updateApiUrl();

        })();
    </script>
</body>
</html>
