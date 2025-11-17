<?php
/* ==============================================================
   FULL Hexa Call Stats + USER MANAGEMENT + REPORTS + LIST UPLOAD + CAMPAIGNS
   ============================================================== */

session_start();

/* ---------- DATABASE CONFIG ---------- */
define('DB_SERVER',   'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'Hal0o(0m@72427242');
define('DB_NAME',     'tilak_db');

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error);

/* ---------- CREATE TABLES (if not exists) ---------- */
$tables = [
    "CREATE TABLE IF NOT EXISTS prime_products_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        code VARCHAR(100) NOT NULL,
        msp DECIMAL(10,2) NOT NULL,
        halooprice DECIMAL(10,2) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "CREATE TABLE IF NOT EXISTS call_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_time DATETIME NOT NULL,
        customer_name VARCHAR(255) NOT NULL,
        interested VARCHAR(50),
        country VARCHAR(100),
        offer_letter VARCHAR(50),
        university VARCHAR(255),
        course_level VARCHAR(100),
        degree VARCHAR(255),
        status VARCHAR(50) DEFAULT 'pending'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // Lists table for upload functionality
    "CREATE TABLE IF NOT EXISTS lists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        list_name VARCHAR(255) NOT NULL,
        campaign_id VARCHAR(100),
        list_status VARCHAR(50) DEFAULT 'active',
        total_leads INT DEFAULT 0,
        file_path VARCHAR(500),
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // Lead recycling table
    "CREATE TABLE IF NOT EXISTS lead_recycling (
        id INT AUTO_INCREMENT PRIMARY KEY,
        list_id INT,
        campaign_id VARCHAR(100),
        recycle_rules TEXT,
        status VARCHAR(50) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // Campaigns table
    "CREATE TABLE IF NOT EXISTS campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id VARCHAR(100) NOT NULL UNIQUE,
        campaign_name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(50) DEFAULT 'active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

foreach ($tables as $sql) {
    if (!$conn->query($sql)) {
        die("Table creation failed: " . $conn->error);
    }
}

/* ---------- AJAX HANDLERS ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        /* ----- LOGOUT ----- */
        if ($_POST['ajax'] === 'logout') {
            session_unset();
            session_destroy();
            $response['success'] = true;
            $response['message'] = 'Logged out successfully';
        }

        /* ----- EDIT PRODUCT ----- */
        elseif ($_POST['ajax'] === 'edit') {
            if (!isset($_POST['id'], $_POST['name'], $_POST['code'], $_POST['msp'])) {
                throw new Exception('Missing required fields');
            }

            $id   = (int)$_POST['id'];
            $name = trim($_POST['name']);
            $code = trim($_POST['code']);
            $msp  = (float)$_POST['msp'];
            $halooprice = !empty($_POST['halooprice']) ? (float)$_POST['halooprice'] : null;

            $sql = "UPDATE prime_products_details SET name=?, code=?, msp=?";
            $params = [$name, $code, $msp];
            $types  = 'ssd';

            if ($halooprice !== null) {
                $sql .= ", halooprice=?";
                $params[] = $halooprice;
                $types   .= 'd';
            }
            $sql .= " WHERE id=?";
            $params[] = $id;
            $types   .= 'i';

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

            $stmt->bind_param($types, ...$params);
            $response['success'] = $stmt->execute();
            
            if (!$response['success']) throw new Exception("Execute failed: " . $stmt->error);
            $stmt->close();
            $response['message'] = 'Record updated successfully';
        }

        /* ----- DELETE PRODUCT ----- */
        elseif ($_POST['ajax'] === 'delete') {
            $id = (int)$_POST['id'];
            $rowResult = $conn->query("SELECT * FROM prime_products_details WHERE id=$id");
            $row = $rowResult->fetch_assoc();

            $stmt = $conn->prepare("DELETE FROM prime_products_details WHERE id=?");
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
            
            $stmt->bind_param('i', $id);
            $response['success'] = $stmt->execute();
            
            if (!$response['success']) throw new Exception("Delete failed: " . $stmt->error);
            $stmt->close();
            $response['row'] = $row ?: null;
        }

        /* ----- UNDO DELETE ----- */
        elseif ($_POST['ajax'] === 'undo' && isset($_POST['data'])) {
            $data = json_decode($_POST['data'], true);
            
            $sql = "INSERT INTO prime_products_details (id, name, code, msp, halooprice) 
                     VALUES (?,?,?,?,?) 
                     ON DUPLICATE KEY UPDATE 
                     name=VALUES(name), code=VALUES(code), msp=VALUES(msp), halooprice=VALUES(halooprice)";

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

            $stmt->bind_param('issdd', $data['id'], $data['name'], $data['code'], $data['msp'], $data['halooprice']);
            $response['success'] = $stmt->execute();
            
            if (!$response['success']) throw new Exception("Undo failed: " . $stmt->error);
            $stmt->close();
            $response['row'] = $data;
            $response['message'] = 'Record restored';
        }

        /* ----- UPLOAD LIST ----- */
        elseif ($_POST['ajax'] === 'uploadList' && isset($_FILES['listFile'])) {
            $listName = trim($_POST['listName']);
            $campaignId = trim($_POST['campaignId']);
            
            if (empty($listName)) throw new Exception("List name is required");
            
            $file = $_FILES['listFile'];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("File upload error: " . $file['error']);
            
            $allowedTypes = ['text/csv', 'application/csv', 'application/vnd.ms-excel'];
            if (!in_array($file['type'], $allowedTypes)) throw new Exception("Only CSV files are allowed");
            
            // Process CSV
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) throw new Exception("Cannot open uploaded file");
            
            $header = fgetcsv($handle);
            if (!$header) throw new Exception("Empty or invalid CSV file");
            
            $leadCount = 0;
            while (fgetcsv($handle) !== false) $leadCount++;
            fclose($handle);
            
            // Save file
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $fileName = uniqid() . '_' . basename($file['name']);
            $filePath = $uploadDir . $fileName;
            
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception("Failed to save uploaded file");
            }
            
            // Insert into database
            $stmt = $conn->prepare("INSERT INTO lists (list_name, campaign_id, file_path, total_leads) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('sssi', $listName, $campaignId, $filePath, $leadCount);
            $response['success'] = $stmt->execute();
            
            if (!$response['success']) throw new Exception("Database insert failed: " . $stmt->error);
            
            $response['listId'] = $stmt->insert_id;
            $response['message'] = "List uploaded successfully with $leadCount leads";
            $stmt->close();
        }

        /* ----- GET LISTS ----- */
        elseif ($_POST['ajax'] === 'getLists') {
            $result = $conn->query("SELECT * FROM lists ORDER BY uploaded_at DESC");
            $lists = [];
            while ($row = $result->fetch_assoc()) $lists[] = $row;
            $response['success'] = true;
            $response['lists'] = $lists;
        }

        /* ----- DELETE LIST ----- */
        elseif ($_POST['ajax'] === 'deleteList') {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM lists WHERE id=?");
            $stmt->bind_param('i', $id);
            $response['success'] = $stmt->execute();
            $response['message'] = $response['success'] ? 'List deleted successfully' : 'Delete failed';
            $stmt->close();
        }

        /* ----- SAVE LEAD RECYCLING ----- */
        elseif ($_POST['ajax'] === 'saveLeadRecycling') {
            $listId = (int)$_POST['listId'];
            $campaignId = trim($_POST['campaignId']);
            $rules = trim($_POST['recycleRules']);
            
            $stmt = $conn->prepare("INSERT INTO lead_recycling (list_id, campaign_id, recycle_rules) VALUES (?, ?, ?)");
            $stmt->bind_param('iss', $listId, $campaignId, $rules);
            $response['success'] = $stmt->execute();
            $response['message'] = $response['success'] ? 'Recycling rules saved' : 'Save failed';
            $stmt->close();
        }

        /* ----- CREATE CAMPAIGN ----- */
        elseif ($_POST['ajax'] === 'createCampaign') {
            $campaignId = trim($_POST['campaignId']);
            $campaignName = trim($_POST['campaignName']);
            $description = trim($_POST['description']);
            
            if (empty($campaignId) || empty($campaignName)) {
                throw new Exception("Campaign ID and Name are required");
            }
            
            $stmt = $conn->prepare("INSERT INTO campaigns (campaign_id, campaign_name, description) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $campaignId, $campaignName, $description);
            $response['success'] = $stmt->execute();
            
            if (!$response['success']) {
                if ($conn->errno == 1062) {
                    throw new Exception("Campaign ID already exists");
                }
                throw new Exception("Database insert failed: " . $stmt->error);
            }
            
            $response['message'] = 'Campaign created successfully';
            $stmt->close();
        }

        /* ----- GET CAMPAIGNS ----- */
        elseif ($_POST['ajax'] === 'getCampaigns') {
            $result = $conn->query("SELECT * FROM campaigns ORDER BY created_at DESC");
            $campaigns = [];
            while ($row = $result->fetch_assoc()) $campaigns[] = $row;
            $response['success'] = true;
            $response['campaigns'] = $campaigns;
        }

        /* ----- DELETE CAMPAIGN ----- */
        elseif ($_POST['ajax'] === 'deleteCampaign') {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM campaigns WHERE id=?");
            $stmt->bind_param('i', $id);
            $response['success'] = $stmt->execute();
            $response['message'] = $response['success'] ? 'Campaign deleted successfully' : 'Delete failed';
            $stmt->close();
        }

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    ob_clean();
    echo json_encode($response);
    exit;
}

/* ---------- FETCH DATA FOR VIEWS ---------- */
$userSQL    = "SELECT id, name, code, msp, halooprice FROM prime_products_details ORDER BY id";
$userResult = $conn->query($userSQL);

$reportSQL = "SELECT * FROM call_logs ORDER BY date_time DESC";
$reportResult = $conn->query($reportSQL);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Hexa Call Stats</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* ========== ENHANCED THEME VARIABLES ========== */
    :root {
      --bg-primary: linear-gradient(135deg, #f0f4ff 0%, #e6eeff 100%);
      --bg-overlay: radial-gradient(circle at 10% 20%, rgba(120, 119, 198, 0.05) 0%, transparent 20%), 
                    radial-gradient(circle at 90% 80%, rgba(255, 107, 107, 0.05) 0%, transparent 20%);
      --bg-sidebar: linear-gradient(180deg, #ffffff 0%, #f8faff 100%);
      --text-primary: #2c3e50;
      --text-secondary: #5a6c7d;
      --accent-primary: #7877c6;
      --accent-secondary: #ff6b6b;
      --accent-tertiary: #4ecdc4;
      --accent-quaternary: #ffd166;
      --card-bg: rgba(255, 255, 255, 0.9);
      --card-border: 1px solid rgba(255, 255, 255, 0.8);
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
      --btn-primary: linear-gradient(135deg, #7877c6 0%, #ff6b6b 100%);
      --btn-primary-hover: linear-gradient(135deg, #6a69b8 0%, #e85c5c 100%);
      --danger: #ff6b6b;
      --success: #4ecdc4;
      --warning: #ffd166;
      --info: #6c63ff;
      --input-bg: rgba(255, 255, 255, 0.8);
      --input-border: rgba(120, 119, 198, 0.2);
      --hover-bg: rgba(120, 119, 198, 0.08);
      --undo-bg: #fff3e0;
      --undo-border: #ffe0b2;
    }
    
    /* ========== ENHANCED STYLES ========== */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg-primary);
      color: var(--text-primary);
      min-height: 100vh;
      transition: all 0.3s ease;
    }
    
    body::before {
      content: "";
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: var(--bg-overlay);
      animation: float 20s infinite ease-in-out;
      z-index: -1;
    }
    
    @keyframes float {
      0%, 100% { transform: translate(0, 0) rotate(0deg); }
      33% { transform: translate(-10px, -10px) rotate(2deg); }
      66% { transform: translate(10px, -10px) rotate(-2deg); }
    }
    
    /* ========== SIDEBAR ENHANCEMENTS ========== */
    .sidebar {
      width: 260px;
      background: var(--bg-sidebar);
      box-shadow: 4px 0 25px rgba(0, 0, 0, 0.1);
      padding: 30px 20px;
      position: fixed;
      height: 100vh;
      overflow-y: auto;
      z-index: 1000;
      border-right: 1px solid var(--card-border);
    }
    
    .sidebar .logo {
      width: 160px;
      margin: 0 auto 35px;
      display: block;
      filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
      transition: transform 0.3s;
    }
    
    .sidebar .logo:hover {
      transform: scale(1.05) rotate(2deg);
    }
    
    .sidebar .menu {
      list-style: none;
    }
    
    .sidebar .menu li {
      padding: 14px 18px;
      margin-bottom: 8px;
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      position: relative;
      overflow: hidden;
    }
    
    .sidebar .menu li::before {
      content: "";
      position: absolute;
      left: 0;
      top: 0;
      height: 100%;
      width: 4px;
      background: linear-gradient(to bottom, var(--accent-primary), var(--accent-secondary));
      transform: translateX(-4px);
      transition: transform 0.3s;
    }
    
    .sidebar .menu li:hover {
      background: var(--hover-bg);
      color: var(--accent-primary);
      transform: translateX(8px);
    }
    
    .sidebar .menu li:hover::before {
      transform: translateX(0);
    }
    
    .sidebar .menu li.active {
      background: linear-gradient(90deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
      color: #fff;
      box-shadow: 0 8px 20px rgba(120, 119, 198, 0.3);
      transform: translateX(0);
    }
    
    .sidebar .menu li.active::before {
      content: none;
    }
    
    .sidebar .menu li i {
      font-size: 1.2rem;
      transition: transform 0.3s;
    }
    
    .sidebar .menu li:hover i {
      transform: scale(1.2);
    }
    
    /* ========== MAIN CONTENT ENHANCEMENTS ========== */
    .main-content {
      flex: 1;
      margin-left: 260px;
      padding: 30px;
      transition: all 0.3s ease;
    }
    
    .header {
      background: var(--card-bg);
      backdrop-filter: blur(10px);
      padding: 20px 30px;
      border-radius: 20px;
      box-shadow: var(--shadow);
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      border: var(--card-border);
      position: relative;
      overflow: hidden;
    }
    
    .header::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(to right, var(--accent-primary), var(--accent-secondary));
    }
    
    .header h4 {
      font-weight: 700;
      color: var(--accent-primary);
      margin: 0;
      font-size: 1.8rem;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    
    .user-info {
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 600;
      color: var(--text-secondary);
      cursor: pointer;
      padding: 10px 18px;
      border-radius: 50px;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      background: var(--input-bg);
      backdrop-filter: blur(10px);
      border: 1px solid var(--input-border);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    
    .user-info:hover {
      background: var(--hover-bg);
      color: var(--accent-primary);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(120, 119, 198, 0.15);
    }
    
    .user-info i {
      font-size: 2rem;
      color: var(--accent-primary);
      transition: transform 0.3s;
    }
    
    .user-info:hover i {
      transform: scale(1.1) rotate(5deg);
    }
    
    /* ========== DATE SECTION ENHANCEMENTS ========== */
    .date-section {
      background: var(--card-bg);
      backdrop-filter: blur(10px);
      padding: 25px;
      border-radius: 20px;
      box-shadow: var(--shadow);
      display: flex;
      gap: 25px;
      margin-bottom: 30px;
      align-items: flex-end;
      border: var(--card-border);
      animation: fadeInUp 0.6s;
    }
    
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .date-section > div {
      flex: 1;
      max-width: 250px;
    }
    
    .date-section label {
      font-weight: 600;
      color: var(--text-secondary);
      margin-bottom: 10px;
      display: block;
      font-size: 0.95rem;
    }
    
    .date-section .form-control {
      padding: 12px 18px;
      border: 2px solid var(--input-border);
      border-radius: 12px;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      background: var(--input-bg);
      font-weight: 500;
    }
    
    .date-section .form-control:focus {
      border-color: var(--accent-primary);
      box-shadow: 0 0 0 4px var(--hover-bg);
      background: var(--input-bg);
      transform: translateY(-2px);
      outline: none;
    }
    
    /* ========== STAT CARDS ENHANCEMENTS ========== */
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 25px;
      margin-bottom: 40px;
    }
    
    .stat-card {
      background: var(--card-bg);
      backdrop-filter: blur(10px);
      padding: 30px;
      border-radius: 20px;
      box-shadow: var(--shadow);
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      align-items: center;
      gap: 20px;
      border: var(--card-border);
      position: relative;
      overflow: hidden;
    }
    
    .stat-card::after {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(to right, var(--accent-primary), var(--accent-secondary));
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.4s;
    }
    
    .stat-card:hover {
      transform: translateY(-8px) scale(1.02);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
    }
    
    .stat-card:hover::after {
      transform: scaleX(1);
    }
    
    .stat-card i {
      font-size: 2.5rem;
      color: var(--accent-primary);
      background: linear-gradient(135deg, rgba(120, 119, 198, 0.1) 0%, rgba(255, 107, 107, 0.1) 100%);
      padding: 18px;
      border-radius: 16px;
      width: 80px;
      height: 80px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 15px rgba(120, 119, 198, 0.2);
      transition: all 0.3s;
    }
    
    .stat-card:hover i {
      transform: scale(1.1) rotate(5deg);
      box-shadow: 0 6px 20px rgba(120, 119, 198, 0.3);
    }
    
    .stat-card div h6 {
      color: var(--text-secondary);
      font-weight: 500;
      margin-bottom: 8px;
      font-size: 0.95rem;
    }
    
    .stat-card div h4 {
      font-weight: 700;
      color: var(--text-primary);
      margin: 0;
      font-size: 2.2rem;
      background: linear-gradient(to right, var(--accent-primary), var(--accent-secondary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    
    /* ========== CHART ENHANCEMENTS ========== */
    .charts-section {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
      gap: 30px;
    }
    
    .chart-card {
      background: var(--card-bg);
      backdrop-filter: blur(10px);
      padding: 30px;
      border-radius: 20px;
      box-shadow: var(--shadow);
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      border: var(--card-border);
      position: relative;
      overflow: hidden;
    }
    
    .chart-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
    }
    
    .chart-card h6 {
      font-weight: 700;
      color: var(--text-primary);
      margin-bottom: 25px;
      font-size: 1.3rem;
      text-align: center;
      background: linear-gradient(to right, var(--accent-primary), var(--accent-secondary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    
    .chart-card canvas {
      max-height: 300px;
      transition: all 0.3s ease;
    }
    
    .chart-card:hover canvas {
      filter: drop-shadow(0 5px 15px rgba(0, 0, 0, 0.1));
    }
    
    /* Enhanced pulse animation for the chart */
    @keyframes chartPulse {
      0% { 
        transform: translate(-50%, -50%) scale(0.8); 
        opacity: 0.7; 
      }
      50% { 
        transform: translate(-50%, -50%) scale(1.2); 
        opacity: 0.3; 
      }
      100% { 
        transform: translate(-50%, -50%) scale(0.8); 
        opacity: 0.7; 
      }
    }
    
    .pulse-effect {
      animation: chartPulse 3s ease-in-out infinite;
    }
    
    /* ========== SECTION STYLES ========== */
    .call-logs-section {
      display: none;
      background: var(--card-bg);
      backdrop-filter: blur(10px);
      padding: 30px;
      border-radius: 20px;
      box-shadow: var(--shadow);
      border: var(--card-border);
      animation: fadeInUp 0.6s;
    }
    
    .call-logs-section h5 {
      font-weight: 700;
      color: var(--text-primary);
      margin-bottom: 25px;
      font-size: 1.4rem;
      background: linear-gradient(to right, var(--accent-primary), var(--accent-secondary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    
    .search-box {
      position: relative;
      margin-bottom: 25px;
    }
    
    .search-box i {
      position: absolute;
      left: 18px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-secondary);
      font-size: 1.2rem;
      z-index: 10;
    }
    
    .search-box input {
      padding: 14px 15px 14px 50px;
      border: 2px solid var(--input-border);
      border-radius: 14px;
      width: 100%;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      background: var(--input-bg);
      font-weight: 500;
      font-size: 1rem;
    }
    
    .search-box input:focus {
      border-color: var(--accent-primary);
      box-shadow: 0 0 0 4px var(--hover-bg);
      background: var(--input-bg);
      transform: translateY(-2px);
      outline: none;
    }
    
    /* ========== TABLE ENHANCEMENTS ========== */
    .call-log-table {
      border-radius: 12px;
      overflow: hidden;
      background: var(--input-bg);
    }
    
    .call-log-table th {
      background: linear-gradient(to right, var(--accent-primary), var(--accent-secondary));
      color: #fff;
      font-weight: 600;
      padding: 18px 15px;
      border: none;
      font-size: 0.95rem;
      letter-spacing: 0.5px;
    }
    
    .call-log-table td {
      padding: 18px 15px;
      vertical-align: middle;
      border-color: rgba(240, 244, 248, 0.5);
      font-weight: 500;
      transition: background 0.2s;
      color: var(--text-primary);
    }
    
    .call-log-table tbody tr {
      transition: all 0.3s;
    }
    
    .call-log-table tbody tr:hover {
      background: var(--hover-bg);
      transform: translateX(5px);
    }
    
    /* ========== ACTION BUTTONS ========== */
    .upload-actions {
      margin-bottom: 30px;
      display: flex;
      gap: 15px;
      justify-content: flex-end;
    }
    
    .btn-action {
      background: var(--btn-primary);
      color: #fff;
      border: none;
      padding: 12px 24px;
      border-radius: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 15px rgba(120, 119, 198, 0.3);
    }
    
    .btn-action:hover {
      background: var(--btn-primary-hover);
      transform: translateY(-3px);
      box-shadow: 0 6px 20px rgba(120, 119, 198, 0.4);
    }
    
    /* ========== MODAL ENHANCEMENTS ========== */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(5px);
      z-index: 2000;
      animation: fadeIn 0.3s;
    }
    
    .modal-overlay.active {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    .modal-content {
      background: var(--card-bg);
      border-radius: 20px;
      padding: 35px;
      max-width: 600px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: slideUp 0.3s;
      position: relative;
    }
    
    @keyframes slideUp {
      from { transform: translateY(50px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
    
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 25px;
      padding-bottom: 20px;
      border-bottom: 2px solid var(--input-border);
    }
    
    .modal-header h5 {
      font-weight: 700;
      color: var(--text-primary);
      margin: 0;
      font-size: 1.5rem;
      background: linear-gradient(to right, var(--accent-primary), var(--accent-secondary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    
    .modal-close {
      background: none;
      border: none;
      font-size: 1.8rem;
      cursor: pointer;
      color: var(--text-secondary);
      transition: all 0.3s;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
    }
    
    .modal-close:hover {
      background: rgba(255, 107, 107, 0.1);
      color: var(--danger);
      transform: rotate(90deg);
    }
    
    /* ========== FORM ENHANCEMENTS ========== */
    .form-group {
      margin-bottom: 25px;
    }
    
    .form-group label {
      display: block;
      font-weight: 600;
      color: var(--text-secondary);
      margin-bottom: 10px;
      font-size: 0.95rem;
    }
    
    .form-group input, .form-group select, .form-group textarea {
      width: 100%;
      padding: 12px 18px;
      border: 2px solid var(--input-border);
      border-radius: 12px;
      transition: all 0.3s;
      font-size: 1rem;
      font-weight: 500;
      background: var(--input-bg);
      color: var(--text-primary);
    }
    
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
      border-color: var(--accent-primary);
      box-shadow: 0 0 0 4px var(--hover-bg);
      background: var(--input-bg);
      outline: none;
    }
    
    .file-upload-area {
      border: 3px dashed var(--input-border);
      border-radius: 16px;
      padding: 40px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s;
      background: var(--hover-bg);
    }
    
    .file-upload-area:hover {
      border-color: var(--accent-primary);
      background: var(--hover-bg);
      transform: translateY(-3px);
    }
    
    .file-upload-area i {
      font-size: 3rem;
      color: var(--accent-primary);
      margin-bottom: 15px;
    }
    
    .file-upload-area p {
      color: var(--text-secondary);
      font-weight: 500;
      margin: 0;
    }
    
    .file-upload-area .file-info {
      margin-top: 10px;
      color: var(--accent-primary);
      font-weight: 600;
    }
    
    .modal-actions {
      display: flex;
      gap: 15px;
      justify-content: flex-end;
      margin-top: 30px;
      padding-top: 20px;
      border-top: 2px solid var(--input-border);
    }
    
    .btn-primary {
      background: var(--btn-primary);
      color: #fff;
      border: none;
      padding: 12px 30px;
      border-radius: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      box-shadow: 0 4px 15px rgba(120, 119, 198, 0.3);
    }
    
    .btn-primary:hover {
      background: var(--btn-primary-hover);
      transform: translateY(-3px);
      box-shadow: 0 6px 20px rgba(120, 119, 198, 0.4);
    }
    
    .btn-secondary {
      background: var(--input-bg);
      color: var(--text-secondary);
      border: 2px solid var(--input-border);
      padding: 12px 30px;
      border-radius: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
    }
    
    .btn-secondary:hover {
      background: var(--hover-bg);
      border-color: var(--accent-primary);
      color: var(--accent-primary);
    }
    
    /* ========== ANIMATIONS ========== */
    .shimmer {
      background: linear-gradient(90deg, #f0f4ff 25%, #e6eeff 50%, #f0f4ff 75%);
      background-size: 200% 100%;
      animation: shimmer 1.5s infinite;
    }
    
    @keyframes shimmer {
      0% { background-position: -200% 0; }
      100% { background-position: 200% 0; }
    }
    
    /* ========== RESPONSIVE STYLES ========== */
    @media (max-width: 992px) {
      .sidebar { width: 220px; }
      .main-content { margin-left: 220px; }
    }
    
    @media (max-width: 768px) {
      .sidebar { width: 100%; height: auto; position: relative; }
      .main-content { margin-left: 0; padding: 20px; }
      .charts-section { grid-template-columns: 1fr; }
      .date-section { flex-direction: column; }
      .date-section > div { max-width: 100%; }
      .upload-actions { flex-direction: column; }
    }
    
    /* ========== USER-MANAGEMENT STYLES ========== */
    .dropdown {
      position: relative;
      display: inline-block;
      margin-bottom: 15px;
      z-index: 100;
    }
    
    .dropbtn {
      background: var(--success);
      color: #fff;
      padding: 10px 15px;
      border: none;
      cursor: pointer;
      border-radius: 8px;
      font-weight: 600;
      transition: all 0.3s;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .dropbtn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .dropdown-content {
      display: none;
      position: absolute;
      background: var(--card-bg);
      min-width: 180px;
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
      z-index: 1000;
      border-radius: 8px;
      border: var(--card-border);
      margin-top: 5px;
      backdrop-filter: blur(10px);
    }
    
    .dropdown.active .dropdown-content,
    .dropdown:hover .dropdown-content {
      display: block;
    }
    
    .dropdown-content label {
      display: block;
      padding: 10px 15px;
      cursor: pointer;
      color: var(--text-primary);
      transition: background 0.2s;
      margin: 0;
      font-weight: 500;
    }
    
    .dropdown-content label:hover {
      background: var(--hover-bg);
    }
    
    .dropdown-content input[type="checkbox"] {
      margin-right: 8px;
      accent-color: var(--accent-primary);
    }
    
    .options button {
      padding: 5px 10px;
      margin: 2px;
      border: none;
      cursor: pointer;
      border-radius: 3px;
      font-weight: 500;
      transition: all 0.2s;
    }
    
    .btn-edit {
      background: var(--accent-primary);
      color: #fff;
    }
    
    .btn-copy {
      background: var(--warning);
      color: #fff;
    }
    
    .btn-delete {
      background: var(--danger);
      color: #fff;
    }
    
    .btn-edit:hover, .btn-copy:hover, .btn-delete:hover {
      transform: scale(1.05);
    }
    
    .undo-banner {
      background: var(--undo-bg);
      border: 1px solid var(--undo-border);
      padding: 8px 12px;
      margin: 4px 0;
      border-radius: 4px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.9em;
    }
    
    .undo-btn {
      background: var(--accent-primary);
      color: #fff;
      padding: 4px 10px;
      border: none;
      border-radius: 3px;
      cursor: pointer;
      font-size: 0.85em;
      font-weight: 600;
    }
    
    .undo-btn:hover {
      background: var(--btn-primary-hover);
    }
    
    /* ========== REPORT VIEW STYLES ========== */
    .badge-status {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.85em;
      font-weight: 600;
    }
    
    .badge-yes, .badge-active { 
      background: var(--success); 
      color: white; 
    }
    
    .badge-no, .badge-inactive { 
      background: var(--danger); 
      color: white; 
    }
    
    .badge-maybe { 
      background: var(--warning); 
      color: white; 
    }
    
    .badge-offer-sent { 
      background: var(--info); 
      color: white; 
    }
    
    .badge-offer-pending { 
      background: var(--warning); 
      color: white; 
    }
    
    .badge-offer-rejected { 
      background: var(--danger); 
      color: white; 
    }
    
    .badge-info { 
      background: var(--info); 
      color: white; 
    }
  </style>
</head>
<body>
  <div class="dashboard-container">
    <!-- Sidebar -->
    <div class="sidebar">
      <img src="Hexalogo1.png" alt="Hexa Logo" class="logo" />
      <ul class="menu">
        <li class="menu-item active" data-view="dashboard"><i class="bi bi-speedometer2"></i> Dashboard</li>
        <li class="menu-item" data-view="campaigns"><i class="bi bi-megaphone"></i> Campaigns</li>
        <li class="menu-item" data-view="upload"><i class="bi bi-upload"></i> List Upload</li>
        <li class="menu-item" data-view="callLogs"><i class="bi bi-telephone"></i> Call Logs</li>
        <li class="menu-item" data-view="report"><i class="bi bi-bar-chart"></i> Report</li>
      </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
      <div class="header">
        <h4 id="pageTitle">Hexa Call Stats</h4>
        <div class="user-info" id="logoutBtn" title="Click to logout">
          <i class="bi bi-person-circle"></i>🙂👉 Logout
        </div>
      </div>

      <!-- Dashboard View -->
      <div id="dashboardView">
        <div class="date-section">
          <div><label>From Date:</label><input type="date" id="fromDate" class="form-control" value="2025-11-10"></div>
          <div><label>To Date:</label><input type="date" id="toDate" class="form-control" value="2025-11-10"></div>
        </div>

        <div class="cards">
          <div class="stat-card"><i class="bi bi-telephone"></i><div><h6>Total Calls</h6><h4 id="totalCalls" class="shimmer">0</h4></div></div>
          <div class="stat-card"><i class="bi bi-headset"></i><div><h6>Connected Calls</h6><h4 id="connectedCalls" class="shimmer">0</h4></div></div>
          <div class="stat-card"><i class="bi bi-x-circle"></i><div><h6>Not Connected Calls</h6><h4 id="notConnected" class="shimmer">0</h4></div></div>
          <div class="stat-card"><i class="bi bi-clock-history"></i><div><h6>Average Call Duration</h6><h4 id="avgDuration" class="shimmer">00:00:00</h4></div></div>
          <div class="stat-card"><i class="bi bi-hourglass-split"></i><div><h6>Total Call Duration</h6><h4 id="totalDuration">00:18:57</h4></div></div>
        </div>

        <div class="charts-section">
          <div class="chart-card"><h6>Total Calls Trend</h6><canvas id="totalCallsChart"></canvas></div>
          <div class="chart-card"><h6>Call Overview</h6><canvas id="callOverviewChart"></canvas></div>
        </div>
      </div>

      <!-- Campaigns View -->
      <div id="campaignsView" class="call-logs-section" style="display:none;">
        <div class="upload-actions">
          <button class="btn-action" onclick="showCreateCampaignModal()"><i class="bi bi-plus-circle"></i> Campaign</button>
        </div>
        <div class="search-box"><i class="bi bi-search"></i><input type="text" id="searchCampaigns" class="form-control" placeholder="Search by campaign name or ID..."></div>
        <div class="table-responsive">
          <table class="table table-hover call-log-table">
            <thead><tr><th>Sr.No.</th><th>Campaign ID</th><th>Campaign Name</th><th>Description</th><th>Created At</th><th>Status</th><th>Action</th></tr></thead>
            <tbody id="campaignsTableBody"><tr><td colspan="7" style="text-align:center;padding:40px;color:#7a8a9a;">No campaigns found. Click "+ Campaign" to create one.</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- List Upload View -->
      <div id="listUploadView" class="call-logs-section" style="display:none;">
        <div class="upload-actions">
          <button class="btn-action" onclick="showUploadModal()"><i class="bi bi-list"></i> Upload List</button>
          <button class="btn-action" onclick="showLeadRecyclingModal()"><i class="bi bi-recycle"></i> Lead Recycling</button>
          <button class="btn-action" onclick="downloadListFormat()"><i class="bi bi-download"></i> List Format</button>
        </div>
        <div class="search-box"><i class="bi bi-search"></i><input type="text" id="searchLists" class="form-control" placeholder="Search by list name, campaign, or status..."></div>
        <div class="table-responsive">
          <table class="table table-hover call-log-table">
            <thead><tr><th>Sr.No.</th><th>List Id</th><th>List Name</th><th>Campaign</th><th>Total Leads</th><th>Status</th><th>Uploaded At</th><th>Action</th></tr></thead>
            <tbody id="listTableBody"><tr><td colspan="8" style="text-align:center;padding:40px;color:#7a8a9a;">No data available. Click "Upload List" to add one.</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- USER MANAGEMENT (CALL LOGS TAB) -->
      <div id="callLogsView" class="call-logs-section" style="display:none;">
        <h5 style="margin-bottom:25px;">Product / Price Management</h5>

        <!-- Column Toggle -->
        <div class="dropdown" id="columnDropdown">
          <button class="dropbtn" onclick="toggleDropdown(event)">
            Toggle Columns <i class="bi bi-chevron-down"></i>
          </button>
          <div class="dropdown-content" id="dropdownMenu">
            <label><input type="checkbox" id="toggle_id" checked> ID</label>
            <label><input type="checkbox" id="toggle_name" checked> Name</label>
            <label><input type="checkbox" id="toggle_code" checked> Code</label>
            <label><input type="checkbox" id="toggle_msp" checked> MSP</label>
            <label><input type="checkbox" id="toggle_halooprice" checked> Haloo Price</label>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover call-log-table" id="userTable">
            <thead>
              <tr>
                <th class="col-id">ID</th>
                <th class="col-name">Name</th>
                <th class="col-code">Code</th>
                <th class="col-msp">MSP</th>
                <th class="col-halooprice">Haloo Price</th>
                <th>Options</th>
              </tr>
            </thead>
            <tbody id="tableBody">
              <?php if ($userResult && $userResult->num_rows > 0):
                  while ($row = $userResult->fetch_assoc()): ?>
                <tr data-id="<?= $row['id'] ?>">
                  <td class="col-id"><?= $row['id'] ?></td>
                  <td class="col-name"><?= htmlspecialchars($row['name']) ?></td>
                  <td class="col-code"><?= htmlspecialchars($row['code']) ?></td>
                  <td class="col-msp" data-raw="<?= $row['msp'] ?>"><?= number_format($row['msp'],2) ?></td>
                  <td class="col-halooprice" data-raw="<?= $row['halooprice'] ?>"><?= number_format($row['halooprice'],2) ?></td>
                  <td class="options">
                    <button class="btn-edit"   onclick="openEdit(<?= $row['id'] ?>)">Edit</button>
                    <button class="btn-copy"   onclick="copyRow(this)">Copy</button>
                    <button class="btn-delete" onclick="deleteRow(<?= $row['id'] ?>, this)">Delete</button>
                  </td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="6" style="text-align:center;padding:60px;color:#7a8a9a;">No records found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- REPORT VIEW -->
      <div id="reportView" class="call-logs-section" style="display:none;">
        <h5 style="margin-bottom:25px;">Call Logs Report</h5>
        
        <div class="date-section">
          <div><label>Start Date:</label><input type="date" id="reportStartDate" class="form-control" value="2025-11-13"></div>
          <div><label>End Date:</label><input type="date" id="reportEndDate" class="form-control" value="2025-11-13"></div>
          <div style="display:flex;align-items:flex-end;">
            <button class="btn-action" id="applyFilterBtn"><i class="bi bi-filter"></i> Apply Filter</button>
            <button class="btn-secondary" id="resetFilterBtn" style="margin-left:10px;"><i class="bi bi-arrow-clockwise"></i> Reset</button>
          </div>
        </div>

        <div class="search-box"><i class="bi bi-search"></i><input type="text" id="searchReport" class="form-control" placeholder="Search by customer, university, degree or country..."></div>
        
        <div class="table-responsive">
          <table class="table table-hover call-log-table">
            <thead>
              <tr>
                <th>Date Time</th>
                <th>Customer Name</th>
                <th>Interested</th>
                <th>Country</th>
                <th>Offer Letter</th>
                <th>University</th>
                <th>Course Level</th>
                <th>Degree</th>
              </tr>
            </thead>
            <tbody id="reportTableBody">
              <?php if ($reportResult && $reportResult->num_rows > 0):
                  while ($row = $reportResult->fetch_assoc()): 
                    $interested_class = '';
                    if ($row['interested'] === 'Yes') $interested_class = 'badge-yes';
                    elseif ($row['interested'] === 'No') $interested_class = 'badge-no';
                    elseif ($row['interested'] === 'Maybe') $interested_class = 'badge-maybe';
                    
                    $offer_class = '';
                    if ($row['offer_letter'] === 'Sent') $offer_class = 'badge-offer-sent';
                    elseif ($row['offer_letter'] === 'Pending') $offer_class = 'badge-offer-pending';
                    elseif ($row['offer_letter'] === 'Rejected') $offer_class = 'badge-offer-rejected';
              ?>
                <tr data-datetime="<?= date('Y-m-d', strtotime($row['date_time'])) ?>">
                  <td><?= date('Y-m-d H:i', strtotime($row['date_time'])) ?></td>
                  <td><?= htmlspecialchars($row['customer_name']) ?></td>
                  <td><span class="badge-status <?= $interested_class ?>"><?= $row['interested'] ?></span></td>
                  <td><?= htmlspecialchars($row['country']) ?></td>
                  <td><span class="badge-status <?= $offer_class ?>"><?= $row['offer_letter'] ?></span></td>
                  <td><?= htmlspecialchars($row['university']) ?></td>
                  <td><?= htmlspecialchars($row['course_level']) ?></td>
                  <td><?= htmlspecialchars($row['degree']) ?></td>
                </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:#7a8a9a;">No call logs found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ==================== CREATE CAMPAIGN MODAL ==================== -->
  <div id="createCampaignModal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <h5>Create New Campaign</h5>
        <button class="modal-close" onclick="closeModal('createCampaignModal')">×</button>
      </div>
      <form id="createCampaignForm">
        <div class="form-group">
          <label>Campaign ID *</label>
          <input type="text" id="campaignIdInput" required placeholder="Enter unique campaign ID (e.g., CAMP-2025-001)">
        </div>
        <div class="form-group">
          <label>Campaign Name *</label>
          <input type="text" id="campaignNameInput" required placeholder="Enter campaign name">
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea id="campaignDescriptionInput" rows="3" placeholder="Enter campaign description (optional)"></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-secondary" onclick="closeModal('createCampaignModal')">Cancel</button>
          <button type="submit" class="btn-primary"><i class="bi bi-save"></i> Create Campaign</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================== UPLOAD LIST MODAL ==================== -->
  <div id="uploadModal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <h5>Upload New List</h5>
        <button class="modal-close" onclick="closeModal('uploadModal')">×</button>
      </div>
      <form id="uploadForm">
        <div class="form-group">
          <label>List Name *</label>
          <input type="text" id="listName" required placeholder="Enter list name">
        </div>
        <div class="form-group">
          <label>Campaign ID (optional)</label>
          <input type="text" id="campaignId" placeholder="Enter campaign ID">
        </div>
        <div class="form-group">
          <label>CSV File *</label>
          <div class="file-upload-area" onclick="document.getElementById('listFile').click()">
            <i class="bi bi-cloud-upload"></i>
            <p>Click to browse or drag & drop CSV file</p>
            <input type="file" id="listFile" name="listFile" accept=".csv" style="display:none;" onchange="onFileSelected(this)">
            <div class="file-info" id="fileInfo"></div>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
          <button type="submit" class="btn-primary"><i class="bi bi-upload"></i> Upload List</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================== LEAD RECYCLING MODAL ==================== -->
  <div id="leadRecyclingModal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <h5>Lead Recycling Rules</h5>
        <button class="modal-close" onclick="closeModal('leadRecyclingModal')">×</button>
      </div>
      <form id="recyclingForm">
        <div class="form-group">
          <label>List ID</label>
          <select id="recycleListId" class="form-control">
            <option value="">Select List</option>
          </select>
        </div>
        <div class="form-group">
          <label>Campaign ID (optional)</label>
          <input type="text" id="recycleCampaignId" placeholder="Enter campaign ID">
        </div>
        <div class="form-group">
          <label>Recycling Rules (JSON format)</label>
          <textarea id="recycleRules" rows="4" placeholder='{"attempts":3,"interval":"24h","conditions":"no_answer,busy"}'></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-secondary" onclick="closeModal('leadRecyclingModal')">Cancel</button>
          <button type="submit" class="btn-primary">Save Rules</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================== EDIT MODAL ==================== -->
  <div id="editModal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <h5>Edit Product</h5>
        <button class="modal-close" onclick="closeModal('editModal')">×</button>
      </div>
      <form id="editForm">
        <input type="hidden" id="edit_id">
        <div class="form-group"><label>Name</label><input type="text" id="edit_name" required></div>
        <div class="form-group"><label>Code</label><input type="text" id="edit_code" required></div>
        <div class="form-group"><label>MSP</label><input type="number" step="0.01" id="edit_msp" required></div>
        <div class="form-group"><label>Haloo Price <small>(leave blank to keep current)</small></label><input type="number" step="0.01" id="edit_halooprice"></div>
        <div class="modal-actions">
          <button type="button" class="btn-secondary" onclick="closeModal('editModal')">Cancel</button>
          <button type="submit" class="btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    /* --------------------------------------------------------------
       VIEW SWITCHING
       -------------------------------------------------------------- */
    const menuItems = document.querySelectorAll('.menu-item');
    const views = {
      dashboard: document.getElementById('dashboardView'),
      campaigns: document.getElementById('campaignsView'),
      upload   : document.getElementById('listUploadView'),
      callLogs : document.getElementById('callLogsView'),
      report   : document.getElementById('reportView')
    };
    const pageTitle = document.getElementById('pageTitle');

    function showView(name) {
      Object.values(views).forEach(v => v.style.display = 'none');
      menuItems.forEach(i => i.classList.remove('active'));
      views[name].style.display = 'block';
      pageTitle.textContent = name === 'dashboard' ? 'Hexa Call Stats' :
                              name === 'campaigns' ? 'Campaigns' :
                              name === 'upload'    ? 'List Upload' :
                              name === 'callLogs'  ? 'Product Management' : 'Call Reports';
      document.querySelector(`[data-view="${name}"]`).classList.add('active');
      
      // Load data when switching to specific views
      if (name === 'upload') loadLists();
      if (name === 'campaigns') loadCampaigns();
    }
    menuItems.forEach(m => m.addEventListener('click', () => showView(m.dataset.view)));

    /* --------------------------------------------------------------
       LOGOUT FUNCTIONALITY
       -------------------------------------------------------------- */
    document.getElementById('logoutBtn').addEventListener('click', function() {
      if (confirm('Are you sure you want to logout?')) {
        const fd = new FormData();
        fd.append('ajax', 'logout');
        
        fetch('', {method: 'POST', body: fd})
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              window.location.href = 'login.php';
            } else {
              alert('Logout failed: ' + res.message);
            }
          })
          .catch(err => {
            console.error('Logout error:', err);
            alert('❌ Network error during logout.');
          });
      }
    });

    /* --------------------------------------------------------------
       DASHBOARD CHARTS (demo data)
       -------------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => {
        document.getElementById('totalCalls').textContent = '1,247';
        document.getElementById('connectedCalls').textContent = '894';
        document.getElementById('notConnected').textContent = '353';
        document.getElementById('avgDuration').textContent = '00:01:24';
        document.querySelectorAll('.shimmer').forEach(el => el.classList.remove('shimmer'));
      }, 1500);

      const lineCtx = document.getElementById('totalCallsChart')?.getContext('2d');
      if (lineCtx) new Chart(lineCtx, {
        type:'line',
        data:{
          labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
          datasets:[{
            label:'Total Calls',
            data:[120,190,300,250,200,300,250],
            borderColor:'#7877c6',
            backgroundColor:'rgba(120, 119, 198, 0.1)',
            fill:true,
            tension:0.4
          }]
        },
        options:{
          responsive:true,
          plugins:{
            legend:{display:false}
          }
        }
      });

      // ANIMATED DOUGHNUT CHART - ROTATE ONCE AND STOP
      const doughCtx = document.getElementById('callOverviewChart')?.getContext('2d');
      if (doughCtx) {
        // Create the chart with animation
        const callOverviewChart = new Chart(doughCtx, {
          type: 'doughnut',
          data: {
            labels: ['Connected', 'Not Connected', 'Missed'],
            datasets: [{
              data: [894, 353, 0],
              backgroundColor: ['#4ecdc4', '#ff6b6b', '#ffd166'],
              borderWidth: 0,
              borderColor: '#fff',
              hoverBorderWidth: 3,
              hoverBorderColor: '#fff',
              hoverOffset: 15
            }]
          },
          options: {
            responsive: true,
            cutout: '65%',
            plugins: {
              legend: {
                position: 'bottom',
                labels: {
                  padding: 20,
                  usePointStyle: true,
                  pointStyle: 'circle',
                  font: {
                    size: 12,
                    weight: '500'
                  }
                }
              },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const label = context.label || '';
                    const value = context.parsed;
                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                    const percentage = Math.round((value / total) * 100);
                    return `${label}: ${value} (${percentage}%)`;
                  }
                }
              }
            },
            animation: {
              animateScale: true,
              animateRotate: true,
              duration: 2000,
              easing: 'easeOutQuart'
            },
            hover: {
              animationDuration: 300
            },
            layout: {
              padding: {
                top: 10,
                bottom: 10
              }
            }
          }
        });

        // Add one-time rotation animation
        function rotateOnce() {
          let rotation = 0;
          const targetRotation = 360; // One full rotation
          const duration = 1500; // 1.5 seconds
          const startTime = performance.now();
          
          function animateRotation(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function for smooth rotation
            const easeOutQuart = 1 - Math.pow(1 - progress, 4);
            rotation = targetRotation * easeOutQuart;
            
            callOverviewChart.options.rotation = rotation;
            callOverviewChart.update('none');
            
            if (progress < 1) {
              requestAnimationFrame(animateRotation);
            }
          }
          
          requestAnimationFrame(animateRotation);
        }
        
        // Start one-time rotation after initial chart animation
        setTimeout(() => {
          rotateOnce();
        }, 500);

        // Add hover effects
        const chartContainer = doughCtx.canvas.parentElement;
        chartContainer.style.transition = 'all 0.3s ease';
        
        chartContainer.addEventListener('mouseenter', function() {
          this.style.transform = 'scale(1.05)';
          callOverviewChart.options.cutout = '55%';
          callOverviewChart.update();
        });
        
        chartContainer.addEventListener('mouseleave', function() {
          this.style.transform = 'scale(1)';
          callOverviewChart.options.cutout = '65%';
          callOverviewChart.update();
        });

        // Add pulsing animation to the chart center (keeps pulsing)
        const centerPulse = document.createElement('div');
        centerPulse.style.cssText = `
          position: absolute;
          top: 50%;
          left: 50%;
          transform: translate(-50%, -50%);
          width: 80px;
          height: 80px;
          border-radius: 50%;
          background: radial-gradient(circle, rgba(255,255,255,0.8) 0%, rgba(255,255,255,0) 70%);
          pointer-events: none;
          z-index: 1;
          animation: chartPulse 3s infinite;
        `;
        
        chartContainer.style.position = 'relative';
        centerPulse.classList.add('pulse-effect');
        chartContainer.appendChild(centerPulse);
      }
    });

    /* --------------------------------------------------------------
       COLUMN TOGGLE
       -------------------------------------------------------------- */
    document.querySelectorAll('.dropdown-content input[type="checkbox"]').forEach(cb => {
      cb.addEventListener('change', function () {
        const colClass = this.id.replace('toggle_', 'col-');
        document.querySelectorAll('.' + colClass).forEach(c => c.style.display = this.checked ? '' : 'none');
      });
    });

    /* --------------------------------------------------------------
       COPY ROW
       -------------------------------------------------------------- */
    function copyRow(btn) {
      const cells = Array.from(btn.closest('tr').cells).slice(0, -1).map(c => c.textContent.trim());
      navigator.clipboard.writeText(cells.join('\t')).then(() => {
        alert('Row copied to clipboard!');
      }).catch(() => {
        alert('Copy failed. Please try again.');
      });
    }

    /* --------------------------------------------------------------
       TOGGLE DROPDOWN
       -------------------------------------------------------------- */
    function toggleDropdown(event) {
      event.stopPropagation();
      const dropdown = document.getElementById('columnDropdown');
      const menu = document.getElementById('dropdownMenu');
      
      // Close all other dropdowns first
      document.querySelectorAll('.dropdown').forEach(d => {
        if (d !== dropdown) d.classList.remove('active');
      });
      
      // Toggle current dropdown
      dropdown.classList.toggle('active');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function() {
      document.getElementById('columnDropdown')?.classList.remove('active');
    });

    /* --------------------------------------------------------------
       EDIT MODAL
       -------------------------------------------------------------- */
    function openEdit(id) {
      const row = document.querySelector(`tr[data-id="${id}"]`);
      
      document.getElementById('edit_id').value = id;
      document.getElementById('edit_name').value = row.querySelector('.col-name').textContent.trim();
      document.getElementById('edit_code').value = row.querySelector('.col-code').textContent.trim();
      document.getElementById('edit_msp').value = row.querySelector('.col-msp').dataset.raw;
      document.getElementById('edit_halooprice').value = row.querySelector('.col-halooprice').dataset.raw;
      
      document.getElementById('editModal').classList.add('active');
    }

    function closeModal(id) {
      document.getElementById(id).classList.remove('active');
    }

    // Edit form handler that stays on current page
    document.getElementById('editForm').addEventListener('submit', e => {
      e.preventDefault();
      
      const fd = new FormData();
      fd.append('ajax', 'edit');
      fd.append('id', document.getElementById('edit_id').value);
      fd.append('name', document.getElementById('edit_name').value);
      fd.append('code', document.getElementById('edit_code').value);
      fd.append('msp', document.getElementById('edit_msp').value);
      
      const hp = document.getElementById('edit_halooprice').value;
      if (hp) fd.append('halooprice', hp);

      fetch('', {method:'POST', body:fd})
        .then(r => {
          if (!r.ok) throw new Error('HTTP error: ' + r.status);
          return r.json();
        })
        .then(res => {
          if (res.success) {
            // ✅ SUCCESS - Update the row instead of reloading
            const rowId = document.getElementById('edit_id').value;
            const row = document.querySelector(`tr[data-id="${rowId}"]`);
            
            if (row) {
              // Update the row cells with new values
              row.querySelector('.col-name').textContent = document.getElementById('edit_name').value;
              row.querySelector('.col-code').textContent = document.getElementById('edit_code').value;
              
              const mspValue = parseFloat(document.getElementById('edit_msp').value);
              const haloopriceValue = document.getElementById('edit_halooprice').value 
                ? parseFloat(document.getElementById('edit_halooprice').value) 
                : 0;
              
              // Update MSP cell
              const mspCell = row.querySelector('.col-msp');
              mspCell.dataset.raw = mspValue;
              mspCell.textContent = mspValue.toLocaleString('en-US', {minimumFractionDigits: 2});
              
              // Update Haloo Price cell
              const haloopriceCell = row.querySelector('.col-halooprice');
              haloopriceCell.dataset.raw = haloopriceValue;
              haloopriceCell.textContent = haloopriceValue.toLocaleString('en-US', {minimumFractionDigits: 2});
            }
            
            alert('✅ Changes saved successfully!');
            closeModal('editModal'); // Close the modal
          } else {
            alert('❌ Save failed: ' + (res.message || 'Unknown database error'));
          }
        })
        .catch(err => {
          console.error('Fetch error:', err);
          alert('❌ Network error. Check console for details.');
        });
    });

    /* --------------------------------------------------------------
       DELETE + UNDO
       -------------------------------------------------------------- */
    let undoTimer = null;

    function deleteRow(id, btn) {
      if (!confirm('Are you sure you want to delete this record?')) return;
      
      const row = btn.closest('tr');
      const data = {
        id:   row.dataset.id,
        name: row.querySelector('.col-name').textContent.trim(),
        code: row.querySelector('.col-code').textContent.trim(),
        msp:  row.querySelector('.col-msp').dataset.raw,
        halooprice: row.querySelector('.col-halooprice').dataset.raw
      };

      const fd = new FormData();
      fd.append('ajax','delete');
      fd.append('id',id);

      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          if (!res.success) {
            alert('❌ Delete failed: ' + (res.message || 'Unknown error'));
            return;
          }
          
          const nextSibling = row.nextElementSibling;
          row.remove();
          showUndoBanner(res.row, nextSibling);
        })
        .catch(err => {
          console.error('Delete error:', err);
          alert('❌ Network error during delete.');
        });
    }

    function showUndoBanner(rowData, insertBeforeNode) {
      if (undoTimer) clearTimeout(undoTimer);
      const banner = document.createElement('div');
      banner.className = 'undo-banner';
      banner.innerHTML = `<span>Deleted <strong>${escapeHtml(rowData.name)}</strong></span><button class="undo-btn">Undo</button>`;
      const tbody = document.getElementById('tableBody');
      
      if (insertBeforeNode) {
        tbody.insertBefore(banner, insertBeforeNode);
      } else {
        tbody.appendChild(banner);
      }

      banner.querySelector('.undo-btn').onclick = () => {
        const fd = new FormData();
        fd.append('ajax','undo');
        fd.append('data', JSON.stringify(rowData));
        
        fetch('', {method:'POST', body:fd})
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              banner.remove();
              insertRow(res.row, insertBeforeNode);
            } else {
              alert('❌ Undo failed: ' + (res.message || 'Unknown error'));
            }
          })
          .catch(err => {
            console.error('Undo error:', err);
            alert('❌ Network error during undo.');
          });
      };

      undoTimer = setTimeout(() => banner.remove(), 10000);
    }

    function insertRow(data, beforeNode) {
      const tr = document.createElement('tr');
      tr.dataset.id = data.id;
      tr.innerHTML = `
        <td class="col-id">${data.id}</td>
        <td class="col-name">${escapeHtml(data.name)}</td>
        <td class="col-code">${escapeHtml(data.code)}</td>
        <td class="col-msp" data-raw="${data.msp}">${Number(data.msp).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
        <td class="col-halooprice" data-raw="${data.halooprice}">${Number(data.halooprice).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
        <td class="options">
          <button class="btn-edit"   onclick="openEdit(${data.id})">Edit</button>
          <button class="btn-copy"   onclick="copyRow(this)">Copy</button>
          <button class="btn-delete" onclick="deleteRow(${data.id}, this)">Delete</button>
        </td>`;
      
      const tbody = document.getElementById('tableBody');
      if (beforeNode) {
        tbody.insertBefore(tr, beforeNode);
      } else {
        tbody.appendChild(tr);
      }
    }

    function escapeHtml(text){ 
      const div=document.createElement('div'); 
      div.textContent=text; 
      return div.innerHTML; 
    }

    /* --------------------------------------------------------------
       CAMPAIGN MANAGEMENT FUNCTIONALITY
       -------------------------------------------------------------- */
    function showCreateCampaignModal() {
      document.getElementById('createCampaignModal').classList.add('active');
    }

    // Handle campaign creation form submission
    document.getElementById('createCampaignForm')?.addEventListener('submit', e => {
      e.preventDefault();
      
      const fd = new FormData();
      fd.append('ajax', 'createCampaign');
      fd.append('campaignId', document.getElementById('campaignIdInput').value);
      fd.append('campaignName', document.getElementById('campaignNameInput').value);
      fd.append('description', document.getElementById('campaignDescriptionInput').value);

      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert('✅ Campaign created successfully!');
            closeModal('createCampaignModal');
            loadCampaigns(); // Refresh campaigns list
            // Reset form
            document.getElementById('createCampaignForm').reset();
          } else {
            alert('❌ Create failed: ' + res.message);
          }
        })
        .catch(err => {
          console.error('Campaign create error:', err);
          alert('❌ Network error during campaign creation');
        });
    });

    // Load campaigns from database
    function loadCampaigns() {
      const fd = new FormData();
      fd.append('ajax', 'getCampaigns');
      
      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          const tbody = document.getElementById('campaignsTableBody');
          if (res.success && res.campaigns && res.campaigns.length > 0) {
            tbody.innerHTML = res.campaigns.map((campaign, index) => `
              <tr>
                <td>${index + 1}</td>
                <td><strong>${escapeHtml(campaign.campaign_id)}</strong></td>
                <td>${escapeHtml(campaign.campaign_name)}</td>
                <td>${escapeHtml(campaign.description || 'N/A')}</td>
                <td>${new Date(campaign.created_at).toLocaleString()}</td>
                <td><span class="badge-status ${campaign.status === 'active' ? 'badge-active' : 'badge-inactive'}">${campaign.status}</span></td>
                <td>
                  <button class="btn-edit" onclick="editCampaign(${campaign.id})"><i class="bi bi-pencil"></i> Edit</button>
                  <button class="btn-delete" onclick="deleteCampaign(${campaign.id}, this)"><i class="bi bi-trash"></i> Delete</button>
                </td>
              </tr>
            `).join('');
          } else {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:#7a8a9a;">No campaigns found. Click "+ Campaign" to create one.</td></tr>';
          }
        })
        .catch(err => {
          console.error('Load campaigns error:', err);
          alert('❌ Failed to load campaigns');
        });
    }

    // Delete campaign
    function deleteCampaign(id, btn) {
      if (!confirm('Are you sure you want to delete this campaign? This will also delete associated list uploads.')) return;
      
      const fd = new FormData();
      fd.append('ajax', 'deleteCampaign');
      fd.append('id', id);
      
      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert('✅ Campaign deleted successfully');
            loadCampaigns(); // Refresh the list
          } else {
            alert('❌ Delete failed: ' + res.message);
          }
        })
        .catch(err => {
          console.error('Delete campaign error:', err);
          alert('❌ Network error during delete');
        });
    }

    // Edit campaign (placeholder - you can extend this)
    function editCampaign(id) {
      alert(`Edit campaign functionality for ID: ${id}\n\nThis can be extended to open an edit modal.`);
    }

    // Search campaigns
    document.getElementById('searchCampaigns')?.addEventListener('keyup', function() {
      const searchTerm = this.value.toLowerCase();
      const rows = document.querySelectorAll('#campaignsTableBody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
      });
    });

    /* --------------------------------------------------------------
       LIST UPLOAD FUNCTIONALITY
       -------------------------------------------------------------- */
    function showUploadModal() {
      document.getElementById('uploadModal').classList.add('active');
    }

    function onFileSelected(input) {
      const file = input.files[0];
      if (file) {
        document.getElementById('fileInfo').innerHTML = 
          `<strong>Selected:</strong> ${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
      }
    }

    function loadLists() {
      const fd = new FormData();
      fd.append('ajax', 'getLists');
      
      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          const tbody = document.getElementById('listTableBody');
          if (res.success && res.lists && res.lists.length > 0) {
            tbody.innerHTML = res.lists.map((list, index) => `
              <tr>
                <td>${index + 1}</td>
                <td>${list.id}</td>
                <td><strong>${escapeHtml(list.list_name)}</strong></td>
                <td>${escapeHtml(list.campaign_id || 'N/A')}</td>
                <td><span class="badge-status badge-info">${list.total_leads}</span></td>
                <td><span class="badge-status ${list.list_status === 'active' ? 'badge-yes' : 'badge-no'}">${list.list_status}</span></td>
                <td>${new Date(list.uploaded_at).toLocaleString()}</td>
                <td>
                  <button class="btn-edit" onclick="viewList(${list.id})"><i class="bi bi-eye"></i> View</button>
                  <button class="btn-delete" onclick="deleteList(${list.id}, this)"><i class="bi bi-trash"></i> Delete</button>
                </td>
              </tr>
            `).join('');
          } else {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:#7a8a9a;">No lists found. Click "Upload List" to add one.</td></tr>';
          }
        })
        .catch(err => {
          console.error('Load lists error:', err);
          alert('❌ Failed to load lists');
        });
    }

    function deleteList(id, btn) {
      if (!confirm('Are you sure you want to delete this list?')) return;
      
      const fd = new FormData();
      fd.append('ajax', 'deleteList');
      fd.append('id', id);
      
      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert('✅ List deleted successfully');
            loadLists(); // Refresh the list
          } else {
            alert('❌ Delete failed: ' + res.message);
          }
        })
        .catch(err => {
          console.error('Delete list error:', err);
          alert('❌ Network error during delete');
        });
    }

    // Handle list upload form submission
    document.getElementById('uploadForm')?.addEventListener('submit', e => {
      e.preventDefault();
      
      const listName = document.getElementById('listName').value.trim();
      const campaignId = document.getElementById('campaignId').value.trim();
      const fileInput = document.getElementById('listFile');
      
      if (!listName) {
        alert('❌ List name is required');
        return;
      }
      
      if (!fileInput.files[0]) {
        alert('❌ Please select a CSV file');
        return;
      }

      const fd = new FormData();
      fd.append('ajax', 'uploadList');
      fd.append('listName', listName);
      fd.append('campaignId', campaignId);
      fd.append('listFile', fileInput.files[0]);

      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert('✅ ' + res.message);
            closeModal('uploadModal');
            loadLists(); // Refresh the list
            // Reset form
            document.getElementById('uploadForm').reset();
            document.getElementById('fileInfo').innerHTML = '';
          } else {
            alert('❌ Upload failed: ' + res.message);
          }
        })
        .catch(err => {
          console.error('Upload error:', err);
          alert('❌ Network error during upload');
        });
    });

    // Download list format template
    function downloadListFormat() {
      const csvContent = `Phone Number,First Name,Last Name,Email,Country,University,Course Level,Degree,Lead Source
+1234567890,John,Doe,john@example.com,USA,Harvard University,Undergraduate,Computer Science,Website
+9876543210,Jane,Smith,jane@example.com,UK,Oxford University,Postgraduate,Business Administration,Referral`;

      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      const url = URL.createObjectURL(blob);
      link.setAttribute('href', url);
      link.setAttribute('download', 'list_template.csv');
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }

    // View list details (placeholder - you can implement modal/view)
    function viewList(id) {
      alert(`Viewing list details for ID: ${id}\n\nThis feature can be extended to show lead details.`);
    }

    // Lead recycling form
    document.getElementById('recyclingForm')?.addEventListener('submit', e => {
      e.preventDefault();
      
      const fd = new FormData();
      fd.append('ajax', 'saveLeadRecycling');
      fd.append('listId', document.getElementById('recycleListId').value);
      fd.append('campaignId', document.getElementById('recycleCampaignId').value);
      fd.append('recycleRules', document.getElementById('recycleRules').value);
      
      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert('✅ Recycling rules saved successfully');
            closeModal('leadRecyclingModal');
          } else {
            alert('❌ Save failed: ' + res.message);
          }
        })
        .catch(err => {
          console.error('Recycling save error:', err);
          alert('❌ Network error');
        });
    });

    // Populate lists dropdown in recycling modal
    function showLeadRecyclingModal() {
      loadListsDropdown();
      document.getElementById('leadRecyclingModal').classList.add('active');
    }

    function loadListsDropdown() {
      const fd = new FormData();
      fd.append('ajax', 'getLists');
      
      fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
          const dropdown = document.getElementById('recycleListId');
          if (res.success && res.lists) {
            dropdown.innerHTML = '<option value="">Select List</option>' +
              res.lists.map(list => `<option value="${list.id}">${list.list_name}</option>`).join('');
          }
        });
    }

    // Search functionality for lists
    document.getElementById('searchLists')?.addEventListener('keyup', function() {
      const searchTerm = this.value.toLowerCase();
      const rows = document.querySelectorAll('#listTableBody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
      });
    });

    /* --------------------------------------------------------------
       REPORT FILTER FUNCTIONALITY
       -------------------------------------------------------------- */
    function filterReportByDate() {
      console.log('Debug: Applying date filter...');
      
      const startDateInput = document.getElementById('reportStartDate');
      const endDateInput = document.getElementById('reportEndDate');
      
      if (!startDateInput || !endDateInput) {
        console.error('Date input elements not found!');
        return;
      }
      
      const startDate = startDateInput.value;
      const endDate = endDateInput.value;
      
      if (!startDate || !endDate) {
        alert('Please select both start and end dates');
        return;
      }
      
      // Create Date objects for proper comparison (set to midnight)
      const start = new Date(startDate + 'T00:00:00');
      const end = new Date(endDate + 'T23:59:59');
      
      if (isNaN(start.getTime()) || isNaN(end.getTime())) {
        alert('Invalid date format. Please select valid dates.');
        return;
      }
      
      const tbody = document.getElementById('reportTableBody');
      if (!tbody) {
        console.error('Report table body not found!');
        return;
      }
      
      const rows = tbody.querySelectorAll('tr');
      let visibleCount = 0;
      let totalRows = 0;
      
      rows.forEach(row => {
        const rowDateStr = row.dataset.datetime;
        // Skip rows without data-datetime attribute (like "no records" row)
        if (!rowDateStr) return;
        
        totalRows++;
        const rowDate = new Date(rowDateStr + 'T00:00:00');
        const show = rowDate >= start && rowDate <= end;
        
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
      });
      
      console.log(`Filter complete: ${visibleCount} of ${totalRows} rows visible`);
      
      // Show feedback if no results
      if (visibleCount === 0 && totalRows > 0) {
        // Create or update a "no results" message
        let noResultsRow = tbody.querySelector('.no-results-message');
        if (!noResultsRow) {
          noResultsRow = document.createElement('tr');
          noResultsRow.className = 'no-results-message';
          noResultsRow.innerHTML = '<td colspan="8" style="text-align:center;padding:40px;color:var(--text-secondary);">No records found for the selected date range.</td>';
          tbody.appendChild(noResultsRow);
        }
        noResultsRow.style.display = '';
      } else {
        // Hide the "no results" message if it exists
        const noResultsRow = tbody.querySelector('.no-results-message');
        if (noResultsRow) noResultsRow.style.display = 'none';
      }
    }

    // Reset report filter to show all rows
    function resetReportFilter() {
      console.log('Debug: Resetting report filter...');
      
      const startDateInput = document.getElementById('reportStartDate');
      const endDateInput = document.getElementById('reportEndDate');
      
      if (startDateInput && endDateInput) {
        // Reset to default dates
        startDateInput.value = '2025-11-13';
        endDateInput.value = '2025-11-13';
      }
      
      const tbody = document.getElementById('reportTableBody');
      if (!tbody) {
        console.error('Report table body not found!');
        return;
      }
      
      const rows = tbody.querySelectorAll('tr');
      rows.forEach(row => {
        row.style.display = '';
      });
      
      // Remove any "no results" message
      const noResultsRow = tbody.querySelector('.no-results-message');
      if (noResultsRow) noResultsRow.remove();
      
      console.log('Filter reset complete');
    }

    // Search report functionality
    document.getElementById('searchReport')?.addEventListener('keyup', function() {
      const searchTerm = this.value.toLowerCase();
      const rows = document.querySelectorAll('#reportTableBody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
      });
    });

    /* --------------------------------------------------------------
       MODAL HELPERS
       -------------------------------------------------------------- */
    // Close modal when clicking outside
    document.querySelectorAll('.modal-overlay').forEach(m => {
      m.addEventListener('click', e => {
        if(e.target === m) m.classList.remove('active');
      });
    });

    // Load campaigns when page loads if on campaigns view
    if (window.location.hash === '#campaigns') {
      showView('campaigns');
    }

    /* --------------------------------------------------------------
       EVENT LISTENERS FOR REPORT FILTER BUTTONS
       -------------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function() {
      // Attach event listeners to report filter buttons
      const applyBtn = document.getElementById('applyFilterBtn');
      const resetBtn = document.getElementById('resetFilterBtn');
      
      if (applyBtn) {
        applyBtn.addEventListener('click', filterReportByDate);
        console.log('✅ Apply Filter button listener attached');
      }
      
      if (resetBtn) {
        resetBtn.addEventListener('click', resetReportFilter);
        console.log('✅ Reset button listener attached');
      }
    });
  </script>
</body>
</html>
<?php $conn->close(); ?>