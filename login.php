<?php
// login.php  (self-contained PHP version of the original HTML)

session_start();                     // needed for future session handling
$error = '';                         // will hold the error message (if any)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // -----  YOUR AUTHENTICATION LOGIC HERE  -----
    // For demo purposes we keep the same hard-coded credentials:
    if ($username === 'admin' && $password === 'admin123') {
        // Success → store something in the session (optional)
        $_SESSION['username'] = $username;
        // Redirect to the protected dashboard
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Invalid username or password!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ----  ALL ORIGINAL CSS (unchanged)  ---- */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            height: 117vh;
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #000;
            background-image: url('image%20pic.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,.5);
            z-index: 1;
        }
        .login-card {
            position: fixed;
            bottom: 5%;
            left: 5%;
            width: 320px;
            background: rgba(255,255,255,.97);
            border-radius: 18px;
            box-shadow: 0 12px 35px rgba(0,0,0,.3);
            padding: 40px 25px 35px 25px;
            text-align: center;
            z-index: 10;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: all .3s ease;
        }
        .login-card:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(0,0,0,.35); }
        .login-card img.logo { width:70px; height:70px; object-fit:contain; margin-bottom:18px; border-radius:14px; border:2.5px solid #6a11cb; box-shadow:0 3px 12px rgba(0,0,0,.2); }
        .login-card h3 { font-weight:700; color:#222; margin-bottom:25px; font-size:22px; }
        .form-control { border-radius:10px; height:44px; margin-bottom:14px; border:1.5px solid #ccc; font-size:14.5px; padding-left:14px; transition:all .3s ease; }
        .form-control:focus { border-color:#6a11cb; box-shadow:0 0 0 3px rgba(106,17,203,.2); outline:none; }
        .btn-primary { width:100%; height:44px; border-radius:10px; background:linear-gradient(90deg,#6a11cb,#2575fc); border:none; font-weight:600; font-size:15px; color:white; cursor:pointer; transition:all .3s ease; }
        .btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(37,117,252,.4); }
        .error-text { color:#e74c3c; font-size:13.5px; margin-bottom:10px; font-weight:500; }
        .footer-text { margin-top:16px; font-size:12px; color:#666; }
        @keyframes shake {
            0%,100%{transform:translateX(0)}
            10%,30%,50%,70%,90%{transform:translateX(-7px)}
            20%,40%,60%,80%{transform:translateX(7px)}
        }
    </style>
</head>
<body>

<div class="login-card">
    <img src="Hexalogo1.png" alt="Hexa Admin Logo" class="logo">

    <h3>Admin Login</h3>

    <?php if ($error): ?>
        <span class="errortext - login.php:94" style="display:block;"><?php echo htmlspecialchars($error); ?></span>
        <script>
            // shake animation when PHP sets an error
            const card = document.querySelector('.login-card');
            card.style.animation = 'shake 0.5s ease';
            setTimeout(() => card.style.animation = '', 500);
        </script>
    <?php else: ?>
        <span class="error-text" style="display:none;"></span>
    <?php endif; ?>

    <form method="post" action="">
        <input type="text" name="username" class="form-control" placeholder="Username" autocomplete="off" required>
        <input type="password" name="password" class="form-control" placeholder="Password" autocomplete="off" required>

        <button type="submit" class="btn btn-primary">Login</button>
    </form>

    <div class="footer-text">© 2025 Hexa. All rights reserved.</div>
</div>

</body>
</html>