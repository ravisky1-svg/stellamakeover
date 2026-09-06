<?php
require __DIR__ . '/config.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_username'] = $admin['username'];

        header('Location: index.php');
        exit;
    }

    $error = 'Invalid username or password.';
}

$salonName = getSetting($pdo, 'salon_name', 'Stella Makeover');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($salonName) ?> | Admin Login</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

    <style>
        :root{
            --pink:#ed4f82;
            --pink-dark:#c82662;
            --pink-soft:#ffe7ef;
            --ink:#1d2435;
            --muted:#777081;
            --white:#ffffff;
        }
        *{box-sizing:border-box}
        html,body{margin:0;min-height:100%}
        body{
            min-height:100vh;
            font-family:"DM Sans",sans-serif;
            color:var(--ink);
            background:linear-gradient(135deg,#fff8f8 0%,#fff2f5 50%,#fbf4ff 100%);
        }
        .login-shell{
            min-height:100vh;
            display:grid;
            grid-template-columns:minmax(0,1.28fr) minmax(470px,.72fr);
        }
        .visual-panel{
            position:relative;
            overflow:hidden;
            min-height:100vh;
            background:#fbe7e8;
        }
        .visual-panel::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(90deg,rgba(255,249,247,.97) 0%,rgba(255,246,245,.78) 33%,rgba(255,238,240,.12) 63%,rgba(255,238,240,.06) 100%);
            z-index:1;
        }
        .hero-photo{
            position:absolute;
            inset:0;
            width:100%;
            height:100%;
            object-fit:cover;
            object-position:center;
            filter:saturate(.82) brightness(1.03);
        }
        .visual-content{
            position:relative;
            z-index:2;
            min-height:100vh;
            display:flex;
            flex-direction:column;
            padding:48px 54px 42px;
            max-width:680px;
        }
        .brand{display:flex;align-items:center;gap:18px}
        .lotus,.mini-lotus{position:relative;flex:0 0 auto}
        .lotus{width:104px;height:104px}
        .mini-lotus{width:72px;height:72px;margin:0 auto 9px}
        .lotus span,.mini-lotus span{
            position:absolute;
            left:50%;
            bottom:13px;
            width:31px;
            height:64px;
            border-radius:80% 20% 80% 20%;
            background:linear-gradient(180deg,#f58aa8,#dc527c);
            transform-origin:50% 100%;
        }
        .mini-lotus span{bottom:8px;width:23px;height:45px}
        .lotus span:nth-child(1),.mini-lotus span:nth-child(1){transform:translateX(-50%) rotate(0deg)}
        .lotus span:nth-child(2),.mini-lotus span:nth-child(2){transform:translateX(-50%) rotate(34deg)}
        .lotus span:nth-child(3),.mini-lotus span:nth-child(3){transform:translateX(-50%) rotate(-34deg)}
        .lotus span:nth-child(4),.mini-lotus span:nth-child(4){transform:translateX(-50%) rotate(66deg);opacity:.77}
        .lotus span:nth-child(5),.mini-lotus span:nth-child(5){transform:translateX(-50%) rotate(-66deg);opacity:.77}
        .brand-copy h1{
            margin:0;
            font-family:"Playfair Display",serif;
            font-size:48px;
            line-height:.98;
            letter-spacing:-1.4px;
        }
        .brand-copy h1 span{display:block;color:var(--pink-dark)}
        .brand-copy p{
            margin:14px 0 0;
            letter-spacing:.22em;
            text-transform:uppercase;
            font-size:13px;
            font-weight:600;
        }
        .visual-message{margin-top:46px;max-width:430px}
        .visual-message h2{
            margin:0;
            font-family:"Playfair Display",serif;
            font-size:51px;
            line-height:1.1;
            letter-spacing:-1px;
        }
        .visual-message h2 em{color:var(--pink-dark)}
        .underline{
            width:78px;height:5px;border-radius:9px;background:var(--pink);margin:24px 0
        }
        .visual-message>p{
            margin:0;
            max-width:400px;
            color:#65616b;
            font-size:17px;
            line-height:1.65;
        }
        .benefits{display:grid;gap:17px;margin-top:28px;width:min(390px,100%)}
        .benefit{display:flex;align-items:center;gap:14px}
        .benefit-icon{
            width:52px;height:52px;border-radius:50%;
            display:grid;place-items:center;
            background:rgba(255,231,239,.92);
            color:var(--pink-dark);
            font-size:24px;
        }
        .benefit b{
            display:block;
            font-family:"Playfair Display",serif;
            font-size:17px
        }
        .benefit small{display:block;color:#7f7881;margin-top:3px;font-size:13px}
        .signature{
            margin-top:auto;
            padding-top:30px;
            font-family:"Playfair Display",serif;
            color:#95556a;
            font-size:26px;
            line-height:1.24;
            font-style:italic;
        }
        .login-panel{
            min-height:100vh;
            padding:32px 38px;
            display:flex;
            flex-direction:column;
            background:
                radial-gradient(circle at 15% 5%,rgba(255,207,221,.52),transparent 23%),
                linear-gradient(180deg,#fff4f7,#fff9fa 40%,#fff);
        }
        .admin-only{
            align-self:flex-end;
            display:flex;align-items:center;gap:8px;
            font-size:13px;color:#575466;margin-bottom:22px;
        }
        .login-card{
            margin:auto 0;
            background:rgba(255,255,255,.96);
            border:1px solid rgba(230,216,224,.9);
            border-radius:24px;
            padding:34px 32px 28px;
            box-shadow:0 28px 80px rgba(116,71,91,.10);
        }
        .card-brand{text-align:center;margin-bottom:20px}
        .card-brand h3{
            margin:0;
            font-family:"Playfair Display",serif;
            font-size:31px;
            line-height:1;
        }
        .card-brand h3 span{color:var(--pink-dark)}
        .card-brand small{
            display:block;margin-top:8px;
            text-transform:uppercase;
            letter-spacing:.22em;
            font-size:9px;
            font-weight:600;
        }
        .welcome{text-align:center;margin:27px 0 22px}
        .welcome h2{
            margin:0;
            font-family:"Playfair Display",serif;
            font-size:32px
        }
        .welcome p{margin:7px 0 0;color:var(--muted);font-size:14px}
        .alert{
            padding:12px 14px;border-radius:12px;margin-bottom:15px;
            background:#ffe4e8;color:#9d2746;border:1px solid #f4cbd5;font-size:13px;
        }
        .field{margin-bottom:14px}
        .input-wrap{
            height:62px;
            display:flex;
            align-items:center;
            border:1px solid #dddce6;
            border-radius:12px;
            background:#fff;
            overflow:hidden;
            transition:.2s ease;
        }
        .input-wrap:focus-within{
            border-color:#ef84a6;
            box-shadow:0 0 0 4px rgba(237,79,130,.08)
        }
        .input-icon{
            width:54px;
            display:grid;
            place-items:center;
            color:#34374b;
            font-size:22px;
            flex:0 0 auto
        }
        .field-copy{flex:1;min-width:0}
        .field-copy label{
            display:block;
            font-size:11px;
            color:#8b8495;
            margin-bottom:2px
        }
        .field-copy input{
            width:100%;
            border:0;
            outline:0;
            padding:0 10px 0 0;
            font:600 15px "DM Sans",sans-serif;
            background:transparent;
            color:#242638
        }
        .eye-btn{
            width:50px;height:100%;
            border:0;background:transparent;
            cursor:pointer;font-size:19px;color:#34374b
        }
        .form-options{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            margin:6px 0 18px;
            font-size:12px
        }
        .remember{
            display:flex;
            align-items:center;
            gap:8px;
            color:#666173
        }
        .remember input{accent-color:var(--pink);width:17px;height:17px}
        .login-btn{
            width:100%;
            height:56px;
            border:0;
            border-radius:11px;
            cursor:pointer;
            font:700 17px "DM Sans",sans-serif;
            color:#fff;
            background:linear-gradient(90deg,#e83e75,#f4729a);
            box-shadow:0 12px 26px rgba(233,74,122,.22);
        }
        .quick-title{
            display:flex;
            align-items:center;
            gap:12px;
            margin:24px 0 14px;
            color:#777285;
            font-size:12px
        }
        .quick-title::before,.quick-title::after{
            content:"";
            height:1px;
            flex:1;
            background:#e7dee4
        }
        .quick-grid{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:10px
        }
        .quick{
            text-align:center;
            padding:13px 7px;
            border-radius:10px;
            font-size:11px;
            color:#373849
        }
        .quick:nth-child(1){background:#fff0f4}
        .quick:nth-child(2){background:#f3efff}
        .quick:nth-child(3){background:#f7efff}
        .quick:nth-child(4){background:#eafaf4}
        .quick span{display:block;font-size:24px;margin-bottom:5px}
        .card-footer{
            display:flex;align-items:center;justify-content:space-between;gap:15px;
            border-top:1px solid #eee3e8;
            padding-top:18px;
            margin-top:22px;
            color:#817a87;
            font-size:10px
        }
        .socials{display:flex;gap:10px;color:var(--pink-dark);font-weight:700}
        .login-footer{text-align:center;color:#9a929b;font-size:10px;margin-top:15px}

        @media(max-width:1050px){
            .login-shell{grid-template-columns:1fr 470px}
            .visual-content{padding:38px 34px}
            .brand-copy h1{font-size:40px}
            .visual-message h2{font-size:42px}
        }
        @media(max-width:850px){
            .login-shell{display:block}
            .visual-panel{display:none}
            .login-panel{min-height:100vh;padding:24px;justify-content:center}
            .admin-only{position:absolute;top:18px;right:24px}
            .login-card{width:min(520px,100%);margin:auto}
        }
        @media(max-width:520px){
            .login-panel{padding:18px}
            .login-card{padding:28px 20px 22px;border-radius:20px}
            .quick-grid{grid-template-columns:repeat(2,1fr)}
            .card-footer{flex-direction:column}
            .welcome h2{font-size:28px}
        }
    </style>
</head>
<body>
<div class="login-shell">

    <section class="visual-panel">
        <img
            class="hero-photo"
            src="https://images.unsplash.com/photo-1562322140-8baeececf3df?auto=format&fit=crop&w=1800&q=88"
            alt="Stella Makeover salon"
        >

        <div class="visual-content">
            <div class="brand">
                <div class="lotus">
                    <span></span><span></span><span></span><span></span><span></span>
                </div>

                <div class="brand-copy">
                    <h1>Stella <span>Makeover</span></h1>
                    <p>Beauty • Care • Confidence</p>
                </div>
            </div>

            <div class="visual-message">
                <h2>Where Beauty<br><em>Meets Confidence</em></h2>
                <div class="underline"></div>
                <p>
                    Professional beauty care services with a personalized touch.
                    Manage your salon effortlessly with the Stella Makeover Admin Panel.
                </p>

                <div class="benefits">
                    <div class="benefit">
                        <div class="benefit-icon">◇</div>
                        <div>
                            <b>Premium Services</b>
                            <small>Delivering exceptional care</small>
                        </div>
                    </div>

                    <div class="benefit">
                        <div class="benefit-icon">♡</div>
                        <div>
                            <b>Happy Clients</b>
                            <small>Building lasting relationships</small>
                        </div>
                    </div>

                    <div class="benefit">
                        <div class="benefit-icon">☆</div>
                        <div>
                            <b>Growing Business</b>
                            <small>Together towards success</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="signature">
                Look Good<br>
                Feel Good<br>
                Be You ♡
            </div>
        </div>
    </section>

    <main class="login-panel">
        <div class="admin-only">🔒 <span>Admin Access Only</span></div>

        <div class="login-card">
            <div class="card-brand">
                <div class="mini-lotus">
                    <span></span><span></span><span></span><span></span><span></span>
                </div>
                <h3>Stella <span>Makeover</span></h3>
                <small>Beauty • Care • Confidence</small>
            </div>

            <div class="welcome">
                <h2>Welcome Back</h2>
                <p>Login to your admin panel</p>
            </div>

            <?php if ($error): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <div class="field">
                    <div class="input-wrap">
                        <div class="input-icon">♙</div>
                        <div class="field-copy">
                            <label for="username">Username</label>
                            <input
                                id="username"
                                name="username"
                                value="<?= e($_POST['username'] ?? 'admin') ?>"
                                autocomplete="username"
                                required
                            >
                        </div>
                    </div>
                </div>

                <div class="field">
                    <div class="input-wrap">
                        <div class="input-icon">🔒</div>
                        <div class="field-copy">
                            <label for="password">Password</label>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                autocomplete="current-password"
                                required
                            >
                        </div>
                        <button class="eye-btn" type="button" id="togglePassword" aria-label="Show password">◉</button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember">
                        <input type="checkbox" checked>
                        <span>Remember me</span>
                    </label>
                    <span>Admin Login</span>
                </div>

                <button class="login-btn" type="submit">Login &nbsp; →</button>
            </form>

            <div class="quick-title">Quick Access</div>

            <div class="quick-grid">
                <div class="quick"><span>▣</span>Appointments</div>
                <div class="quick"><span>♙</span>Clients</div>
                <div class="quick"><span>✂</span>Services</div>
                <div class="quick"><span>▥</span>Reports</div>
            </div>

            <div class="card-footer">
                <span>© <?= date('Y') ?> Stella Makeover. All rights reserved.</span>
                <div class="socials"><span>◎</span><span>f</span><span>◉</span><span>▶</span></div>
            </div>
        </div>

        <div class="login-footer">Secure Admin Management System</div>
    </main>
</div>

<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const password = document.getElementById('password');
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    this.textContent = show ? '◌' : '◉';
});
</script>
</body>
</html>
