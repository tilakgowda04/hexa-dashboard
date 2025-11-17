<?php
// ==================== DATABASE CONFIGURATION (UPDATE THESE) ====================
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'Hal0o(0m@72427242');
define('DB_NAME', 'partners');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch users
$sql = "SELECT user_id, username, password, fullname, user_type FROM partner_user ORDER BY user_id";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h2 {
            margin-bottom: 20px;
            color: #333;
        }
        /* Dropdown */
        .dropdown {
            position: relative;
            display: inline-block;
            margin-bottom: 15px;
        }
        .dropbtn {
            background-color: #4CAF50;
            color: white;
            padding: 10px;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 180px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            z-index: 1;
            border-radius: 4px;
        }
        .dropdown-content label {
            display: block;
            padding: 8px 12px;
            cursor: pointer;
        }
        .dropdown:hover .dropdown-content {
            display: block;
        }
        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
        .options button {
            padding: 5px 10px;
            margin: 2px;
            border: none;
            cursor: pointer;
            border-radius: 3px;
        }
        .btn-edit { background-color: #2196F3; color: white; }
        .btn-copy { background-color: #FF9800; color: white; }
        .btn-delete { background-color: #f44336; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h2>User Management System</h2>
        
        <!-- Column Toggle Dropdown -->
        <div class="dropdown">
            <button class="dropbtn">☰ Toggle Columns</button>
            <div class="dropdown-content">
                <label><input type="checkbox" id="toggle_id" checked> User ID</label>
                <label><input type="checkbox" id="toggle_username" checked> Username</label>
                <label><input type="checkbox" id="toggle_password" checked> Password</label>
                <label><input type="checkbox" id="toggle_fullname" checked> Full Name</label>
                <label><input type="checkbox" id="toggle_usertype" checked> User Type</label>
            </div>
        </div>

        <!-- Users Table -->
        <table id="userTable">
            <thead>
                <tr>
                    <th class="col-id">User ID</th>
                    <th class="col-username">Username</th>
                    <th class="col-password">Password</th>
                    <th class="col-fullname">Full Name</th>
                    <th class="col-usertype">User Type</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        echo "<tr> - dash.php:134";
                        echo "<td class='colid'> - dash.php:135" . $row['user_id'] . "</td>";
                        echo "<td class='colusername'> - dash.php:136" . htmlspecialchars($row['username']) . "</td>";
                        echo "<td class='colpassword'> - dash.php:137" . htmlspecialchars($row['password']) . "</td>";
                        echo "<td class='colfullname'> - dash.php:138" . htmlspecialchars($row['fullname']) . "</td>";
                        echo "<td class='colusertype'> - dash.php:139" . htmlspecialchars($row['user_type']) . "</td>";
                        echo "<td class='options - dash.php:140'>
                                <button class='btn-edit' onclick='editUser(" . $row['user_id'] . ")'>Edit</button>
                                <button class='btn-copy' onclick='copyRow(this)'>Copy</button>
                                <button class='btn-delete' onclick='deleteUser(" . $row['user_id'] . ")'>Delete</button>
                              </td>";
                        echo "</tr> - dash.php:145";
                    }
                } else {
                    echo "<tr><td colspan='6'>No users found</td></tr> - dash.php:148";
                }
                ?>
            </tbody>
        </table>
    </div>

    <script>
        // Column visibility toggle
        document.querySelectorAll('.dropdown-content input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const colClass = this.id.replace('toggle_', 'col-');
                const cells = document.querySelectorAll('.' + colClass);
                cells.forEach(cell => {
                    cell.style.display = this.checked ? '' : 'none';
                });
            });
        });

        // Copy row data
        function copyRow(button) {
            const row = button.closest('tr');
            const rowData = Array.from(row.cells).slice(0, -1).map(cell => cell.textContent);
            const textToCopy = rowData.join('\t');
            
            navigator.clipboard.writeText(textToCopy).then(() => {
                alert('Row data copied to clipboard!');
            });
        }

        // Edit user (redirect to edit page)
        function editUser(userId) {
            window.location.href = 'edit_user.php?id=' + userId;
        }

        // Delete user with confirmation
        function deleteUser(userId) {
            if(confirm('Are you sure you want to delete this user?')) {
                window.location.href = 'delete_user.php?id=' + userId;
            }
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>

<!-- 
=== SQL TABLE CREATION (Run in phpMyAdmin) ===
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    user_type VARCHAR(50) NOT NULL
);

INSERT INTO users (username, password, fullname, user_type) VALUES
('admin', 'haloocom', 'Haloocom', 'admin'),
('sauravi', '12345', 'sauravi', 'preferred partner'),
('sandeep', 'sandeep', 'sandeep', 'haloo partner'),
('Anu', 'anupama', 'Anupama', 'preferred partner'),
('adarsh', 'adarsh', 'Adarsh', 'admin');
-->