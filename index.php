<?php
require __DIR__ . '/config.php';
require __DIR__ . '/whatsapp.php';
requireLogin();

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard','appointments','clients','services','staff','expenses','messages','reports','settings'];
if (!in_array($page,$allowed,true)) $page='dashboard';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action==='add_client') {
            $pdo->prepare('INSERT INTO clients(name,mobile,birthday,notes) VALUES(?,?,?,?)')->execute([trim($_POST['name']),trim($_POST['mobile']),$_POST['birthday'] ?: null,trim($_POST['notes'])]);
            flash('success','Client added.');
        } elseif ($action==='add_service') {
            $pdo->prepare('INSERT INTO services(name,price,duration_minutes) VALUES(?,?,?)')->execute([trim($_POST['name']),(float)$_POST['price'],(int)$_POST['duration']]);
            flash('success','Service added.');
        } elseif ($action==='add_staff') {
            $pdo->prepare('INSERT INTO staff(name,mobile,role) VALUES(?,?,?)')->execute([trim($_POST['name']),trim($_POST['mobile']),trim($_POST['role'])]);
            flash('success','Staff member added.');
        } elseif ($action==='add_appointment') {
            $serviceId=(int)$_POST['service_id'];
            $stmt=$pdo->prepare('SELECT price FROM services WHERE id=?'); $stmt->execute([$serviceId]);
            $price=(float)$stmt->fetchColumn();
            $pdo->prepare('INSERT INTO appointments(client_id,service_id,staff_id,appointment_date,appointment_time,amount,status,payment_status,notes) VALUES(?,?,?,?,?,?,?,?,?)')->execute([(int)$_POST['client_id'],$serviceId,(int)($_POST['staff_id'] ?: 0) ?: null,$_POST['appointment_date'],$_POST['appointment_time'],$price,'Booked','Pending',trim($_POST['notes'])]);
            $id=(int)$pdo->lastInsertId();
            if (!empty($_POST['send_whatsapp'])) {
                $r=sendAppointmentMessage($pdo,$id);
                flash($r['ok']?'success':'warning',$r['ok']?'Appointment saved and WhatsApp sent.':'Appointment saved. WhatsApp: '.$r['response']);
            } else flash('success','Appointment saved.');
        } elseif ($action==='appointment_status') {
            $id=(int)$_POST['id'];
            $old=$pdo->prepare('SELECT status,thank_you_message_sent FROM appointments WHERE id=?'); $old->execute([$id]); $before=$old->fetch();
            $pdo->prepare('UPDATE appointments SET status=?,payment_status=? WHERE id=?')->execute([$_POST['status'],$_POST['payment_status'],$id]);
            if ($_POST['status']==='Completed' && ($before['status'] ?? '')!=='Completed' && (int)($before['thank_you_message_sent'] ?? 0)===0) {
                $r=sendThankYouMessage($pdo,$id);
                flash($r['ok']?'success':'warning',$r['ok']?'Appointment completed and Thank You WhatsApp sent.':'Appointment completed. WhatsApp: '.$r['response']);
            } else flash('success','Appointment updated.');
        } elseif ($action==='send_appointment_message') {
            $r=sendAppointmentMessage($pdo,(int)$_POST['appointment_id']);
            flash($r['ok']?'success':'warning',$r['ok']?'Appointment WhatsApp sent.':'WhatsApp failed: '.$r['response']);
        } elseif ($action==='send_offer') {
            $offer=trim($_POST['offer_text'] ?? ''); $ids=array_map('intval',$_POST['client_ids'] ?? []);
            if ($offer==='' || !$ids) throw new RuntimeException('Select at least one client and enter the offer.');
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $stmt=$pdo->prepare("SELECT * FROM clients WHERE id IN ($placeholders)"); $stmt->execute($ids);
            $sent=0; $failed=0;
            foreach($stmt as $c){
                if(trim((string)$c['mobile'])===''){ $failed++; continue; }
                $msg=renderMessageTemplate($pdo,getSetting($pdo,'offer_template'),['name'=>$c['name'],'offer'=>$offer]);
                $r=sendWhatsApp($pdo,$c['mobile'],$msg);
                logMessage($pdo,(int)$c['id'],null,$c['mobile'],'Offer',$msg,$r);
                $r['ok'] ? $sent++ : $failed++;
            }
            flash($failed ? 'warning':'success',"Offer messages sent: $sent. Failed/skipped: $failed.");
        } elseif ($action==='add_expense') {
            $pdo->prepare('INSERT INTO expenses(title,amount,expense_date,notes) VALUES(?,?,?,?)')->execute([trim($_POST['title']),(float)$_POST['amount'],$_POST['expense_date'],trim($_POST['notes'])]);
            flash('success','Expense added.');
        } elseif ($action==='save_settings') {
            $keys=['salon_name','salon_phone','salon_address','whatsapp_api_url','whatsapp_api_token','whatsapp_sender_id','whatsapp_phone_field','whatsapp_message_field','whatsapp_token_header','whatsapp_token_prefix','appointment_template','thank_you_template','offer_template'];
            foreach($keys as $k) setSetting($pdo,$k,trim((string)($_POST[$k] ?? '')));
            setSetting($pdo,'whatsapp_enabled',!empty($_POST['whatsapp_enabled'])?'1':'0');
            flash('success','Settings saved.');
        }
    } catch (Throwable $e) { flash('error',$e->getMessage()); }
    header('Location: index.php?page='.urlencode($page)); exit;
}

$today=date('Y-m-d');
$todayAppointments=(int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date='$today'")->fetchColumn();
$todaySales=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM appointments WHERE appointment_date='$today' AND status='Completed'")->fetchColumn();
$todayExpenses=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date='$today'")->fetchColumn();
$monthSales=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM appointments WHERE strftime('%Y-%m',appointment_date)=strftime('%Y-%m','now','localtime') AND status='Completed'")->fetchColumn();
$monthExpenses=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE strftime('%Y-%m',expense_date)=strftime('%Y-%m','now','localtime')")->fetchColumn();
$services=$pdo->query('SELECT * FROM services WHERE active=1 ORDER BY name')->fetchAll();
$staff=$pdo->query('SELECT * FROM staff WHERE active=1 ORDER BY name')->fetchAll();
$clients=$pdo->query('SELECT * FROM clients ORDER BY id DESC')->fetchAll();
$totalClients=(int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalServices=(int)$pdo->query("SELECT COUNT(*) FROM services WHERE active=1")->fetchColumn();
$totalStaff=(int)$pdo->query("SELECT COUNT(*) FROM staff WHERE active=1")->fetchColumn();
$monthAppointments=(int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE strftime('%Y-%m',appointment_date)=strftime('%Y-%m','now','localtime')")->fetchColumn();
$monthNewClients=(int)$pdo->query("SELECT COUNT(*) FROM clients WHERE strftime('%Y-%m',created_at)=strftime('%Y-%m','now','localtime')")->fetchColumn();
function serviceImage(string $name): string {
    $n=strtolower($name);
    if (str_contains($n,'hair')) return 'https://images.unsplash.com/photo-1560066984-138dadb4c035?auto=format&fit=crop&w=700&q=80';
    if (str_contains($n,'facial') || str_contains($n,'skin')) return 'https://images.unsplash.com/photo-1570172619644-dfd03ed5d881?auto=format&fit=crop&w=700&q=80';
    if (str_contains($n,'makeup') || str_contains($n,'bridal')) return 'https://images.unsplash.com/photo-1487412947147-5cebf100ffc2?auto=format&fit=crop&w=700&q=80';
    if (str_contains($n,'mani') || str_contains($n,'nail')) return 'https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=700&q=80';
    if (str_contains($n,'pedi')) return 'https://images.unsplash.com/photo-1519415510236-718bdfcd89c8?auto=format&fit=crop&w=700&q=80';
    if (str_contains($n,'wax')) return 'https://images.unsplash.com/photo-1515377905703-c4788e51af15?auto=format&fit=crop&w=700&q=80';
    return 'https://images.unsplash.com/photo-1560750588-73207b1ef5b8?auto=format&fit=crop&w=700&q=80';
}
$flash=pullFlash();
function navClass($current,$name){return $current===$name?'active':'';}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e(getSetting($pdo,'salon_name'))?> Admin</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet"><style>:root{--pink:#ef6f9a;--deep:#a91f58;--ink:#22202a;--muted:#766d77;--line:#efe4e8;--bg:#fff9fa;--white:#fff}*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:'DM Sans',system-ui,sans-serif;background:linear-gradient(135deg,#fffafa,#fff7f9 48%,#fbf8ff);color:var(--ink)}a{color:inherit}.app{display:flex;min-height:100vh}.sidebar{width:248px;background:rgba(255,255,255,.96);border-right:1px solid #f2e4e8;padding:18px 14px;position:fixed;left:0;top:0;bottom:0;overflow:auto;z-index:50}.brand{display:flex;gap:11px;align-items:center;padding:2px 8px 18px}.brand-mark{width:43px;height:43px;border-radius:50% 50% 45% 45%;background:linear-gradient(145deg,#ffd5df,#f08faf);display:grid;place-items:center;color:#fff;font-size:24px;box-shadow:0 8px 18px rgba(214,87,129,.2)}.brand b{font-family:'Playfair Display',serif;font-size:23px;line-height:1}.brand b span{display:block;color:#c12d68}.brand small{display:block;font-size:8px;letter-spacing:.18em;margin-top:6px}.nav{display:grid;gap:5px}.nav a{display:flex;align-items:center;gap:14px;padding:12px 14px;border-radius:8px;text-decoration:none;font-weight:500;color:#2f2c35}.nav a span{font-size:19px;width:22px;text-align:center}.nav a.active{background:linear-gradient(135deg,#ee6d94,#e65a86);color:#fff;box-shadow:0 8px 18px rgba(231,90,134,.18)}.nav a:hover:not(.active){background:#fff0f4}.wa-dot{color:#2fc76e}.side-promo{position:relative;margin:26px 4px 12px;border-radius:10px;overflow:hidden;min-height:330px;background:#ffe8ef}.side-promo img{width:100%;height:330px;object-fit:cover;display:block;filter:saturate(.85)}.side-promo:after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,transparent 36%,rgba(255,241,244,.16) 45%,rgba(255,241,244,.98) 100%)}.side-promo div{position:absolute;z-index:2;left:18px;bottom:20px;font-family:'Playfair Display',serif;font-size:17px;line-height:1.55}.logout-link{display:block;padding:10px 14px;text-decoration:none;color:#8a6674}.main{margin-left:248px;width:calc(100% - 248px);padding:18px 22px 0}.topbar{height:48px;display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.menu-btn{border:0;background:none;font-size:26px;cursor:pointer}.page-head{display:none}.top-actions{display:flex;align-items:center;gap:24px;font-size:13px}.top-date{color:#433d47}.admin-pill{display:flex;align-items:center;gap:8px;background:transparent}.avatar{width:35px;height:35px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#ffd8e2,#e56c94);color:#6f1740;font-weight:800}.hero{height:265px;border-radius:8px;overflow:hidden;position:relative;background:#fbe4e6;margin-bottom:14px}.hero>img{width:100%;height:100%;object-fit:cover;object-position:35% 38%;filter:saturate(.86) brightness(1.03)}.hero-overlay{position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,239,240,.02) 22%,rgba(255,235,237,.84) 52%,rgba(255,230,233,.94) 100%)}.hero-copy{position:absolute;left:43%;top:50%;transform:translateY(-50%);width:52%;padding:20px}.hero-copy small{font-family:'Playfair Display',serif;font-size:15px}.hero-copy h2{font-family:'Playfair Display',serif;font-size:39px;margin:4px 0;color:#321d26}.hero-copy h2 span{color:#b21e5a}.hero-copy p{font-family:'Playfair Display',serif;font-size:20px;margin:5px 0 20px}.hero-features{display:flex;gap:28px;font-size:13px}.hero-features span{border-top:1px solid rgba(164,50,91,.2);padding-top:10px}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px}.metric{padding:15px 18px;border-radius:8px;text-decoration:none;display:flex;align-items:center;gap:15px;min-height:102px}.metric.pink{background:linear-gradient(135deg,#ffe3ed,#ffdbe8)}.metric.peach{background:linear-gradient(135deg,#fff0e8,#ffe4d3)}.metric.lavender{background:linear-gradient(135deg,#f5eaff,#e9dcff)}.metric.mint{background:linear-gradient(135deg,#eafff6,#d9f8eb)}.metric-icon{font-size:34px}.pink .metric-icon{color:#d62e6a}.peach .metric-icon{color:#e7742f}.lavender .metric-icon{color:#bb3b87}.mint .metric-icon{color:#15a06d}.metric strong{font-size:27px;display:block;line-height:1}.metric span{font-size:13px;display:block;margin:5px 0}.metric small{font-size:12px;color:#bd376d}.panel{background:#fff;border:1px solid var(--line);border-radius:9px;box-shadow:0 4px 18px rgba(94,64,75,.035);padding:13px;margin-bottom:14px}.panel h2,.panel h3{margin:0}.panel-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.panel-title h2{font-size:18px}.panel-title a{font-size:12px;color:#dd4b7c;text-decoration:none}.service-cards{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}.service-card{background:#fff;border:1px solid #eee4e6;border-radius:7px;overflow:hidden;text-decoration:none;text-align:center;box-shadow:0 3px 10px rgba(77,53,62,.04)}.service-card img{width:100%;height:132px;object-fit:cover;display:block}.service-card span{display:block;padding:8px 5px 2px;font-size:13px;font-weight:500}.service-card small{display:block;color:#c23968;padding-bottom:8px}.empty-service{grid-column:1/-1;padding:24px;text-align:center;color:#8a7f85}.dashboard-lower{display:grid;grid-template-columns:1.08fr .92fr;gap:14px}.appointments-panel,.overview-panel{min-width:0}.overview-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.overview-stats div{padding:13px 8px;text-align:center;border-radius:7px;background:#fff0f4}.overview-stats div:nth-child(2){background:#f0e7ff}.overview-stats div:nth-child(3){background:#e7fbf3}.overview-stats div:nth-child(4){background:#fff0e8}.overview-stats strong{display:block;color:#c22961;font-size:16px}.overview-stats span{display:block;font-size:11px;margin-top:5px}.month-chip{font-size:11px;border:1px solid #eee1e5;border-radius:6px;padding:6px 9px}.chart{height:190px;margin-top:18px;border-left:1px solid #eee;border-bottom:1px solid #eee;display:flex;align-items:flex-end;gap:10px;padding:10px 8px 0;position:relative}.chart-grid{position:absolute;inset:20px 0 24px;background:repeating-linear-gradient(to top,#f0ecee 0,#f0ecee 1px,transparent 1px,transparent 38px);pointer-events:none}.bar-wrap{flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;z-index:1}.bar{width:68%;background:linear-gradient(#f48cad,#e9608e);min-height:12px}.bar-wrap small{font-size:9px;margin-top:5px;color:#777}.status-pill{display:inline-block;border-radius:5px;padding:4px 7px;font-size:11px;background:#ffe3b8;color:#955e08}.status-pill.completed,.status-pill.confirmed{background:#dff5e9;color:#287653}.status-pill.cancelled{background:#ffe1e1;color:#a32929}.grid2{display:grid;grid-template-columns:1fr 1.3fr;gap:20px}.grid2.compact{grid-template-columns:1fr 1fr}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px}.card span{display:block;color:#756a70;font-size:13px}.card strong{display:block;font-size:26px;margin:7px 0}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}label{display:block;font-size:13px;font-weight:700;margin:13px 0 6px;color:#5d4f58}input,select,textarea{width:100%;padding:11px 12px;border:1px solid #eadde2;border-radius:8px;background:#fff;font:inherit}textarea{min-height:86px;resize:vertical}.btn{border:1px solid #dfcbd7;background:#fff;padding:9px 13px;border-radius:8px;cursor:pointer;font-weight:700;text-decoration:none;color:#463642;display:inline-block}.btn.primary{background:linear-gradient(135deg,#ee6d94,#d84a7b);color:#fff;border-color:#df5d88}.btn.whatsapp{background:#21a866;color:#fff;border-color:#21a866}.btn.full{width:100%;margin-top:16px}.check{display:flex;align-items:center;gap:9px;font-weight:500}.check input{width:auto}.check.strong{font-weight:800}.appointment-item{display:flex;justify-content:space-between;gap:15px;align-items:center;padding:14px 0;border-bottom:1px solid #f2e7ed}.appointment-item:last-child{border-bottom:0}.appointment-item small{display:block;color:#7a6b75;margin-top:4px}.appointment-actions{display:flex;gap:8px;align-items:center}.inline{display:flex;gap:7px;align-items:center}.inline select{width:auto;min-width:105px}.badge{display:inline-block;background:#f3e4ec;padding:5px 9px;border-radius:99px;font-size:12px;font-weight:700}.badge.sent{background:#dcf6e8;color:#177844}.badge.failed{background:#ffe1e1;color:#a22222}.badge.disabled{background:#f1f1f1;color:#666}.alert{padding:12px 14px;border-radius:9px;margin-bottom:14px}.alert.success{background:#e4f7ec;color:#1a7447}.alert.warning{background:#fff3d9;color:#8a6418}.alert.error{background:#ffe5e5;color:#9b2424}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 9px;border-bottom:1px solid #f1e6ec;font-size:12px}th{color:#4c4449;font-size:10px;text-transform:uppercase;letter-spacing:.03em;background:#fbfafb}.message-cell{max-width:460px;white-space:normal}.automation-box{padding:14px;border:1px solid #efdfE8;border-radius:9px;margin-bottom:12px;background:#fffafb}.automation-box p{margin:6px 0 0;color:#71616b}.client-checks{max-height:310px;overflow:auto;border:1px solid #eadbe4;border-radius:8px;padding:10px;margin-bottom:14px}.muted{color:#7d6d77;font-size:13px}.settings-panel hr{border:0;border-top:1px solid #f0e2e9;margin:24px 0}footer{display:grid;grid-template-columns:1fr 1fr 1fr;align-items:end;margin:22px -22px 0;padding:20px 25px;background:linear-gradient(90deg,#fff4f6,#fffafa);border-top:1px solid #f1e4e8;font-family:'Playfair Display',serif;font-size:12px}footer b{font-size:19px}footer b span{color:#b82259}footer small{display:block;font-family:'DM Sans',sans-serif;color:#a27885;margin-top:3px}footer p{text-align:center;font-family:'DM Sans',sans-serif;color:#777;margin:0}footer>div:last-child{text-align:right}.login-body{min-height:100vh;display:grid;place-items:center;background:linear-gradient(135deg,#fff3f7,#faedf1 60%,#f3ecff)}.login-card{width:min(410px,92vw);background:rgba(255,255,255,.95);padding:36px;border:1px solid #edd9e4;border-radius:20px;box-shadow:0 22px 70px rgba(73,39,57,.12)}.login-logo{width:70px;height:70px;border-radius:50%;display:grid;place-items:center;margin:0 auto;background:linear-gradient(135deg,#ffd5df,#ed779d);font-family:'Playfair Display',serif;font-size:25px;color:#8a1c49}.login-card h1{text-align:center;margin:12px 0 2px;font-family:'Playfair Display',serif}.login-card h1 span{color:#b32259}.login-card p{text-align:center;color:#8c6675;margin:0}.login-subtitle{text-align:center;font-size:12px;letter-spacing:.1em;margin:8px 0 20px;color:#aa8d98}.login-card small{display:block;text-align:center;color:#988691;margin-top:14px}@media(max-width:1120px){.metric-grid{grid-template-columns:1fr 1fr}.service-cards{grid-template-columns:repeat(4,1fr)}.dashboard-lower{grid-template-columns:1fr}.hero-copy{left:38%;width:60%}}@media(max-width:820px){.sidebar{transform:translateX(-100%);transition:.25s;width:245px}.menu-open .sidebar{transform:translateX(0);box-shadow:20px 0 50px rgba(0,0,0,.15)}.main{margin-left:0;width:100%;padding:12px 12px 0}.top-actions .top-date{display:none}.page-head{display:block;margin-right:auto;margin-left:8px}.page-head h1{font-size:18px;margin:0}.page-head p{font-size:10px;margin:1px 0;color:#7a6e78}.hero{height:300px}.hero>img{object-position:30% center}.hero-overlay{background:linear-gradient(0deg,rgba(255,236,239,.96),rgba(255,236,239,.22) 65%)}.hero-copy{left:0;bottom:0;top:auto;transform:none;width:100%;padding:18px}.hero-copy h2{font-size:31px}.hero-features{gap:12px;flex-wrap:wrap}.service-cards{grid-template-columns:repeat(3,1fr)}.grid2,.grid3{grid-template-columns:1fr}.appointment-item{align-items:flex-start;flex-direction:column}.appointment-actions{flex-wrap:wrap}footer{margin-left:-12px;margin-right:-12px;grid-template-columns:1fr;text-align:center;gap:12px}footer>div:last-child{text-align:center}}@media(max-width:560px){.metric-grid{grid-template-columns:1fr 1fr;gap:8px}.metric{padding:12px;min-height:90px}.metric strong{font-size:23px}.metric-icon{font-size:27px}.service-cards{grid-template-columns:repeat(2,1fr)}.service-card img{height:120px}.overview-stats{grid-template-columns:1fr 1fr}.hero{height:315px}.hero-copy p{font-size:16px}.top-actions{gap:8px}.admin-pill b{display:none}.row{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}.panel{padding:10px}}
</style>
<style>
/* Stella Makeover dashboard polish */
body{
    background:
        radial-gradient(circle at 90% 0%,rgba(255,211,224,.32),transparent 22%),
        linear-gradient(135deg,#fffaf9 0%,#fff7f9 55%,#fbf8ff 100%);
}
.sidebar{
    box-shadow:12px 0 32px rgba(126,78,96,.045);
}
.brand-mark{
    border-radius:18px 18px 24px 24px;
    position:relative;
}
.brand-mark::after{
    content:"";
    width:17px;
    height:23px;
    border-radius:80% 20% 80% 20%;
    border:2px solid rgba(255,255,255,.9);
    transform:rotate(45deg);
}
.brand-mark{font-size:0}
.hero{
    height:285px;
    border-radius:18px;
    box-shadow:0 16px 45px rgba(128,73,93,.08);
    border:1px solid #f3dfe5;
}
.hero-copy small{
    letter-spacing:.18em;
    color:#91445f;
}
.hero-copy h2{
    font-size:44px;
}
.hero-copy p{
    color:#633d4a;
}
.metric{
    border:1px solid rgba(255,255,255,.8);
    border-radius:16px;
    box-shadow:0 10px 24px rgba(112,75,89,.055);
    transition:.2s ease;
}
.metric:hover{
    transform:translateY(-3px);
    box-shadow:0 16px 34px rgba(112,75,89,.10);
}
.panel{
    border-radius:16px;
    box-shadow:0 10px 30px rgba(96,62,75,.055);
    padding:17px;
}
.service-card{
    border-radius:13px;
    transition:.2s ease;
}
.service-card:hover{
    transform:translateY(-3px);
    box-shadow:0 12px 26px rgba(100,65,78,.12);
}
.service-card img{
    height:145px;
}
.topbar{
    background:rgba(255,255,255,.6);
    border:1px solid rgba(239,224,230,.75);
    padding:0 14px;
    border-radius:13px;
    backdrop-filter:blur(9px);
}
.avatar{
    box-shadow:0 5px 12px rgba(210,80,125,.18);
}
.btn{
    border-radius:10px;
}
.btn.primary{
    box-shadow:0 8px 20px rgba(216,74,123,.18);
}
input,select,textarea{
    border-radius:10px;
}
table{
    overflow:hidden;
    border-radius:10px;
}
th{
    background:#fff7f9;
}
footer{
    border-radius:18px 18px 0 0;
}
@media(max-width:820px){
    .hero{height:330px;border-radius:16px}
    .topbar{position:sticky;top:8px;z-index:30}
}
</style>

</head><body>
<div class="app"><aside class="sidebar"><div class="brand"><div class="brand-mark">✦</div><div><b>Stella <span>Makeover</span></b><small>BEAUTY • CARE • CONFIDENCE</small></div></div>
<nav class="nav"><a class="<?=navClass($page,'dashboard')?>" href="?page=dashboard"><span>⌂</span> Dashboard</a><a class="<?=navClass($page,'appointments')?>" href="?page=appointments"><span>▣</span> Appointments</a><a class="<?=navClass($page,'clients')?>" href="?page=clients"><span>♙</span> Clients</a><a class="<?=navClass($page,'services')?>" href="?page=services"><span>◇</span> Services</a><a class="<?=navClass($page,'staff')?>" href="?page=staff"><span>♧</span> Staff</a><a class="<?=navClass($page,'expenses')?>" href="?page=expenses"><span>▤</span> Expenses</a><a class="<?=navClass($page,'reports')?>" href="?page=reports"><span>▥</span> Reports</a><a class="<?=navClass($page,'messages')?>" href="?page=messages"><span class="wa-dot">●</span> Messages</a><a class="<?=navClass($page,'settings')?>" href="?page=settings"><span>⚙</span> Settings</a></nav>
<div class="side-promo"><img src="https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?auto=format&fit=crop&w=520&q=82" alt="Salon beauty"><div>BEAUTY<br>CARE<br>CONFIDENCE<br>EVERYDAY</div></div><a class="logout-link" href="logout.php">↪ Logout</a></aside>
<main class="main"><header class="topbar"><button class="menu-btn" type="button" onclick="document.body.classList.toggle('menu-open')">☰</button><div class="page-head"><h1><?=ucfirst(e($page))?></h1><p><?=date('l, d F Y')?></p></div><div class="top-actions"><div class="top-date">▣ <?=date('l, d F Y')?></div><div class="admin-pill"><span class="avatar">A</span> Admin <b>⌄</b></div></div></header>
<?php if($flash):?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif;?>
<?php if($page==='dashboard'): ?>
<section class="hero"><img src="https://images.unsplash.com/photo-1562322140-8baeececf3df?auto=format&fit=crop&w=1500&q=84" alt="Stella Makeover"><div class="hero-overlay"></div><div class="hero-copy"><small>WELCOME TO</small><h2>Stella <span>Makeover</span></h2><p>Where Beauty Meets Confidence</p><div class="hero-features"><span>♡ Premium Services</span><span>♡ Happy Clients</span><span>☆ Expert Team</span></div></div></section>
<div class="metric-grid"><a class="metric pink" href="?page=appointments"><div class="metric-icon">▣</div><div><strong><?=$todayAppointments?></strong><span>Today's Appointments</span><small>View all →</small></div></a><a class="metric peach" href="?page=clients"><div class="metric-icon">♙</div><div><strong><?=$totalClients?></strong><span>Total Clients</span><small>View all →</small></div></a><a class="metric lavender" href="?page=services"><div class="metric-icon">✂</div><div><strong><?=$totalServices?></strong><span>Services</span><small>Manage →</small></div></a><a class="metric mint" href="?page=staff"><div class="metric-icon">♧</div><div><strong><?=$totalStaff?></strong><span>Staff Members</span><small>Manage →</small></div></a></div>
<section class="panel service-panel"><div class="panel-title"><h2>Popular Services</h2><a href="?page=services">View All Services →</a></div><div class="service-cards"><?php $shown=0; foreach($services as $svc): if($shown++>=7) break; ?><a class="service-card" href="?page=services"><img src="<?=e(serviceImage($svc['name']))?>" alt="<?=e($svc['name'])?>"><span><?=e($svc['name'])?></span><small>₹<?=number_format((float)$svc['price'],0)?></small></a><?php endforeach; ?><?php if(!$services):?><div class="empty-service">Add salon services to show them here.</div><?php endif;?></div></section>
<div class="dashboard-lower"><section class="panel appointments-panel"><div class="panel-title"><h2>Upcoming Appointments</h2><a href="?page=appointments">View All →</a></div><div class="table-wrap"><table><tr><th>#</th><th>Client Name</th><th>Service</th><th>Date & Time</th><th>Staff</th><th>Status</th></tr><?php $q=$pdo->query("SELECT a.*,c.name client,s.name service,st.name staff FROM appointments a JOIN clients c ON c.id=a.client_id JOIN services s ON s.id=a.service_id LEFT JOIN staff st ON st.id=a.staff_id WHERE a.appointment_date >= '$today' ORDER BY a.appointment_date,a.appointment_time LIMIT 6"); $i=1; foreach($q as $r):?><tr><td><?=$i++?></td><td><?=e($r['client'])?></td><td><?=e($r['service'])?></td><td><?=date('d M Y',strtotime($r['appointment_date']))?><br><small><?=date('h:i A',strtotime($r['appointment_time']))?></small></td><td><?=e($r['staff']?:'-')?></td><td><span class="status-pill <?=strtolower(e($r['status']))?>"><?=e($r['status'])?></span></td></tr><?php endforeach;?></table></div></section>
<section class="panel overview-panel"><div class="panel-title"><h2>Monthly Overview</h2><span class="month-chip">This Month ⌄</span></div><div class="overview-stats"><div><strong>₹<?=number_format($monthSales,0)?></strong><span>Total Revenue</span></div><div><strong><?=$monthAppointments?></strong><span>Appointments</span></div><div><strong><?=$monthNewClients?></strong><span>New Clients</span></div><div><strong>₹<?=number_format($monthExpenses,0)?></strong><span>Expenses</span></div></div><div class="chart"><div class="chart-grid"></div><?php $bars=[28,38,48,55,66,72,81,91,100]; foreach($bars as $idx=>$h):?><div class="bar-wrap"><div class="bar" style="height:<?=$h?>%"></div><small><?=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep'][$idx]?></small></div><?php endforeach;?></div></section></div>
<footer><div><b>Stella <span>Makeover</span></b><small>Beauty • Care • Confidence</small></div><p>© <?=date('Y')?> Stella Makeover. All rights reserved.</p><div>Look Good<br>Feel Good<br>Be You</div></footer>

<?php elseif($page==='appointments'): ?>
<div class="grid2"><div class="panel"><h2>New Appointment</h2><form method="post"><input type="hidden" name="action" value="add_appointment"><label>Client</label><select name="client_id" required><option value="">Select client</option><?php foreach($clients as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?> · <?=e($c['mobile'])?></option><?php endforeach;?></select><label>Service</label><select name="service_id" required><option value="">Select service</option><?php foreach($services as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?> · ₹<?=number_format($s['price'],0)?></option><?php endforeach;?></select><label>Staff</label><select name="staff_id"><option value="">Unassigned</option><?php foreach($staff as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?></option><?php endforeach;?></select><div class="row"><div><label>Date</label><input type="date" name="appointment_date" value="<?=$today?>" required></div><div><label>Time</label><input type="time" name="appointment_time" required></div></div><label>Notes</label><textarea name="notes"></textarea><label class="check"><input type="checkbox" name="send_whatsapp" value="1" checked> Send appointment confirmation on WhatsApp</label><button class="btn primary">Save Appointment</button></form></div>
<div class="panel"><h2>Recent Appointments</h2><?php $q=$pdo->query("SELECT a.*,c.name client,c.mobile,s.name service,st.name staff FROM appointments a JOIN clients c ON c.id=a.client_id JOIN services s ON s.id=a.service_id LEFT JOIN staff st ON st.id=a.staff_id ORDER BY a.appointment_date DESC,a.appointment_time DESC LIMIT 40"); foreach($q as $r):?><div class="appointment-item"><div><b><?=e($r['client'])?></b><small><?=date('d M Y',strtotime($r['appointment_date']))?> · <?=date('h:i A',strtotime($r['appointment_time']))?> · <?=e($r['service'])?> · ₹<?=number_format($r['amount'],0)?></small><small><?=e($r['mobile'])?> · <?=e($r['staff']?:'Unassigned')?></small></div><div class="appointment-actions"><form method="post" class="inline"><input type="hidden" name="action" value="appointment_status"><input type="hidden" name="id" value="<?=$r['id']?>"><select name="status"><option <?=$r['status']==='Booked'?'selected':''?>>Booked</option><option <?=$r['status']==='Completed'?'selected':''?>>Completed</option><option <?=$r['status']==='Cancelled'?'selected':''?>>Cancelled</option><option <?=$r['status']==='No Show'?'selected':''?>>No Show</option></select><select name="payment_status"><option <?=$r['payment_status']==='Pending'?'selected':''?>>Pending</option><option <?=$r['payment_status']==='Paid'?'selected':''?>>Paid</option></select><button class="btn">Update</button></form><form method="post"><input type="hidden" name="action" value="send_appointment_message"><input type="hidden" name="appointment_id" value="<?=$r['id']?>"><button class="btn whatsapp">💬 <?= $r['appointment_message_sent'] ? 'Resend' : 'Send' ?></button></form></div></div><?php endforeach;?></div></div>

<?php elseif($page==='clients'): ?>
<div class="grid2"><div class="panel"><h2>Add Client</h2><form method="post"><input type="hidden" name="action" value="add_client"><label>Name</label><input name="name" required><label>Mobile</label><input name="mobile" placeholder="9876543210" required><label>Birthday</label><input type="date" name="birthday"><label>Notes</label><textarea name="notes"></textarea><button class="btn primary">Add Client</button></form></div><div class="panel"><h2>Client List</h2><div class="table-wrap"><table><tr><th>Name</th><th>Mobile</th><th>Birthday</th><th>Notes</th></tr><?php foreach($clients as $c):?><tr><td><?=e($c['name'])?></td><td><?=e($c['mobile'])?></td><td><?=e($c['birthday']?:'-')?></td><td><?=e($c['notes'])?></td></tr><?php endforeach;?></table></div></div></div>

<?php elseif($page==='services'): ?>
<div class="grid2"><div class="panel"><h2>Add Service</h2><form method="post"><input type="hidden" name="action" value="add_service"><label>Service</label><input name="name" placeholder="Hair Spa" required><label>Price</label><input type="number" name="price" step="0.01" required><label>Duration</label><input type="number" name="duration" value="30" required><button class="btn primary">Add Service</button></form></div><div class="panel"><h2>Services</h2><table><tr><th>Name</th><th>Price</th><th>Duration</th></tr><?php foreach($services as $s):?><tr><td><?=e($s['name'])?></td><td>₹<?=number_format($s['price'],0)?></td><td><?=$s['duration_minutes']?> min</td></tr><?php endforeach;?></table></div></div>

<?php elseif($page==='staff'): ?>
<div class="grid2"><div class="panel"><h2>Add Staff</h2><form method="post"><input type="hidden" name="action" value="add_staff"><label>Name</label><input name="name" required><label>Mobile</label><input name="mobile"><label>Role</label><input name="role" placeholder="Beautician / Hair Stylist"><button class="btn primary">Add Staff</button></form></div><div class="panel"><h2>Staff</h2><table><tr><th>Name</th><th>Mobile</th><th>Role</th></tr><?php foreach($staff as $s):?><tr><td><?=e($s['name'])?></td><td><?=e($s['mobile'])?></td><td><?=e($s['role'])?></td></tr><?php endforeach;?></table></div></div>

<?php elseif($page==='expenses'): ?>
<div class="grid2"><div class="panel"><h2>Add Expense</h2><form method="post"><input type="hidden" name="action" value="add_expense"><label>Expense</label><input name="title" required><label>Amount</label><input type="number" name="amount" step="0.01" required><label>Date</label><input type="date" name="expense_date" value="<?=$today?>" required><label>Notes</label><textarea name="notes"></textarea><button class="btn primary">Add Expense</button></form></div><div class="panel"><h2>Recent Expenses</h2><table><tr><th>Date</th><th>Expense</th><th>Amount</th></tr><?php $q=$pdo->query('SELECT * FROM expenses ORDER BY expense_date DESC,id DESC LIMIT 50'); foreach($q as $r):?><tr><td><?=e($r['expense_date'])?></td><td><?=e($r['title'])?></td><td>₹<?=number_format($r['amount'],0)?></td></tr><?php endforeach;?></table></div></div>

<?php elseif($page==='messages'): ?>
<div class="grid2"><div class="panel"><h2>Send Offer on WhatsApp</h2><form method="post"><input type="hidden" name="action" value="send_offer"><label>Offer</label><textarea name="offer_text" placeholder="Get 20% OFF on Hair Spa this weekend." required></textarea><label>Select Clients</label><div class="client-checks"><label class="check strong"><input type="checkbox" id="selectAll"> Select All</label><?php foreach($clients as $c):?><label class="check"><input type="checkbox" name="client_ids[]" value="<?=$c['id']?>"> <?=e($c['name'])?> · <?=e($c['mobile'])?></label><?php endforeach;?></div><button class="btn whatsapp">💬 Send Offer</button></form></div><div class="panel"><h2>Message Automation</h2><div class="automation-box"><b>📅 Appointment Confirmation</b><p>Sent when appointment is created and “Send appointment confirmation” is checked.</p></div><div class="automation-box"><b>💖 Thank You</b><p>Automatically sent when appointment status changes to <b>Completed</b>.</p></div><div class="automation-box"><b>🎁 Offers</b><p>Sent manually from this page to selected clients.</p></div></div></div>
<div class="panel"><h2>WhatsApp Message History</h2><div class="table-wrap"><table><tr><th>Date</th><th>Type</th><th>Mobile</th><th>Status</th><th>Message</th></tr><?php $q=$pdo->query('SELECT * FROM message_logs ORDER BY id DESC LIMIT 100'); foreach($q as $m):?><tr><td><?=e($m['created_at'])?></td><td><?=e($m['message_type'])?></td><td><?=e($m['mobile'])?></td><td><span class="badge <?=strtolower(str_replace(' ','-',$m['api_status']))?>"><?=e($m['api_status'])?></span></td><td class="message-cell"><?=e($m['message_text'])?></td></tr><?php endforeach;?></table></div></div>
<script>document.getElementById('selectAll')?.addEventListener('change',function(){document.querySelectorAll('input[name="client_ids[]"]').forEach(x=>x.checked=this.checked);});</script>

<?php elseif($page==='reports'): ?>
<div class="cards"><div class="card"><span>Monthly Sales</span><strong>₹<?=number_format($monthSales,0)?></strong></div><div class="card"><span>Monthly Expenses</span><strong>₹<?=number_format($monthExpenses,0)?></strong></div><div class="card"><span>Estimated Profit</span><strong>₹<?=number_format($monthSales-$monthExpenses,0)?></strong></div></div><div class="panel"><h2>Daily Sales — Current Month</h2><table><tr><th>Date</th><th>Completed Jobs</th><th>Sales</th></tr><?php $q=$pdo->query("SELECT appointment_date,COUNT(*) jobs,SUM(amount) sales FROM appointments WHERE strftime('%Y-%m',appointment_date)=strftime('%Y-%m','now','localtime') AND status='Completed' GROUP BY appointment_date ORDER BY appointment_date DESC"); foreach($q as $r):?><tr><td><?=e($r['appointment_date'])?></td><td><?=$r['jobs']?></td><td>₹<?=number_format($r['sales'],0)?></td></tr><?php endforeach;?></table></div>

<?php elseif($page==='settings'): ?>
<div class="panel settings-panel"><h2>Salon & WhatsApp API Settings</h2><p class="muted">This version uses a generic JSON POST connector so you can map it to your WhatsApp API provider.</p><form method="post"><input type="hidden" name="action" value="save_settings"><div class="grid2 compact"><div><label>Salon Name</label><input name="salon_name" value="<?=e(getSetting($pdo,'salon_name'))?>"><label>Salon Phone</label><input name="salon_phone" value="<?=e(getSetting($pdo,'salon_phone'))?>"><label>Salon Address</label><textarea name="salon_address"><?=e(getSetting($pdo,'salon_address'))?></textarea></div><div><label class="check strong"><input type="checkbox" name="whatsapp_enabled" value="1" <?=getSetting($pdo,'whatsapp_enabled')==='1'?'checked':''?>> Enable WhatsApp API</label><label>API URL</label><input name="whatsapp_api_url" value="<?=e(getSetting($pdo,'whatsapp_api_url'))?>" placeholder="https://api.example.com/send"><label>API Token</label><input name="whatsapp_api_token" value="<?=e(getSetting($pdo,'whatsapp_api_token'))?>"><label>Sender / Instance ID (optional)</label><input name="whatsapp_sender_id" value="<?=e(getSetting($pdo,'whatsapp_sender_id'))?>"></div></div><hr><h3>API Field Mapping</h3><div class="grid3"><div><label>Phone JSON Field</label><input name="whatsapp_phone_field" value="<?=e(getSetting($pdo,'whatsapp_phone_field'))?>"></div><div><label>Message JSON Field</label><input name="whatsapp_message_field" value="<?=e(getSetting($pdo,'whatsapp_message_field'))?>"></div><div><label>Token Header</label><input name="whatsapp_token_header" value="<?=e(getSetting($pdo,'whatsapp_token_header'))?>"></div></div><label>Token Prefix</label><input name="whatsapp_token_prefix" value="<?=e(getSetting($pdo,'whatsapp_token_prefix'))?>" placeholder="Bearer "><hr><h3>Message Templates</h3><label>Appointment Message</label><textarea name="appointment_template" rows="4"><?=e(getSetting($pdo,'appointment_template'))?></textarea><label>Thank You Message</label><textarea name="thank_you_template" rows="4"><?=e(getSetting($pdo,'thank_you_template'))?></textarea><label>Offer Message</label><textarea name="offer_template" rows="4"><?=e(getSetting($pdo,'offer_template'))?></textarea><p class="muted">Variables: {name}, {service}, {date}, {time}, {amount}, {offer}, {salon}, {salon_phone}, {salon_address}</p><button class="btn primary">Save Settings</button></form></div>
<?php endif; ?>
</main></div></body></html>
