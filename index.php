<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

$userData = ['total_actions' => 0, 'phone' => '', 'name' => ''];
if (isset($_SESSION['user_id'])) {
    $db_file = __DIR__ . '/database.sqlite';
    try {
        $pdo = new PDO("sqlite:$db_file");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $userData = $u;
            $_SESSION['status'] = $u['status'];
            $_SESSION['user_name'] = $u['name'];
            $_SESSION['def_price'] = $u['default_price'];
            $_SESSION['def_letter'] = $u['default_letter'];
        }
    } catch (Exception $e) {
        error_log('DB error in index.php: ' . $e->getMessage());
    }
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

$jsData = [
    'user' => [
        'name' => e($_SESSION['user_name'] ?? ''),
        'phone' => e($userData['phone'] ?? ''),
        'total_actions' => (int)($userData['total_actions'] ?? 0),
        'def_price' => (int)($_SESSION['def_price'] ?? 80),
        'def_letter' => e($_SESSION['def_letter'] ?? ''),
        'status' => (int)($_SESSION['status'] ?? 0)
    ],
    'isAuthenticated' => isset($_SESSION['user_id']),
    'isAdmin' => ($_SESSION['status'] ?? 0) == 4
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Proezd.kz</title>
    <!-- Подключаем библиотеку для сканера QR-кода -->
    <script src="https://rawgit.com/schmich/instascan-builds/master/instascan.min.js"></script>
    <style>
        :root { --bg: #f2f2f7; --green: #4caf50; --gray: #8e8e93; --radius: 16px; }
        * { -webkit-touch-callout: none; -webkit-user-select: none; box-sizing: border-box; outline: none; -webkit-tap-highlight-color: transparent; }
        body { margin: 0; background: var(--bg); font-family: -apple-system, sans-serif; height: 100vh; overflow: hidden; position: relative; }
        .screen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; display: none; flex-direction: column; align-items: center; padding: 12px; background: var(--bg); }
        .active-screen { display: flex; }
        
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(76, 175, 80, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(76, 175, 80, 0); }
        }

        #success-screen { z-index: 10001; background: white; justify-content: center; }
        .success-checkmark { width: 80px; height: 80px; margin: 0 auto 20px; }
        .check-icon { width: 80px; height: 80px; border-radius: 50%; display: block; stroke-width: 2; stroke: #fff; box-shadow: inset 0px 0px 0px var(--green); animation: fill .4s ease-in-out .4s forwards, scale .3s ease-in-out .9s both; }
        .check-circle { stroke-dasharray: 166; stroke-dashoffset: 166; stroke-width: 2; stroke-miterlimit: 10; stroke: var(--green); fill: none; animation: stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards; }
        .check-path { transform-origin: 50% 50%; stroke-dasharray: 48; stroke-dashoffset: 48; animation: stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards; }
        @keyframes stroke { 100% { stroke-dashoffset: 0; } }
        @keyframes fill { 100% { box-shadow: inset 0px 0px 0px 40px var(--green); } }
        
        .container { width: 100%; max-width: 420px; display: flex; flex-direction: column; height: 100%; }
        .ticket-list { width: 100%; overflow-y: auto; flex-grow: 1; display: flex; flex-direction: column; gap: 10px; padding-bottom: 110px; }
        .ticket-item { background: white; padding: 16px; border-radius: 14px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 2px rgba(0,0,0,0.05); cursor: pointer; }
        .ticket-info-left { display: flex; flex-direction: column; gap: 4px; flex: 1; }
        .ticket-date-label { font-size: 11px; color: var(--gray); font-weight: 400; }
        .tabs { display: flex; gap: 20px; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 10px; width: 100%; }
        .tab { font-weight: 700; color: var(--gray); cursor: pointer; }
        .tab.active { color: var(--green); }
        .white-card { background: white; border-radius: var(--radius); width: 100%; margin-bottom: 8px; overflow: hidden; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #f2f2f7; }
        .info-cell { padding: 12px 15px; border-right: 1px solid #f2f2f7; min-height: 62px; display: flex; flex-direction: column; justify-content: center; }
        .info-cell:last-child { border-right: none; }
        .label { font-size: 10px; color: var(--gray); text-transform: uppercase; }
        .value { font-size: 14px; font-weight: 700; color: #000; word-break: break-all; line-height: 1.2; }
        .timer { font-size: 48px; font-weight: 800; text-align: center; margin: 8px 0; letter-spacing: -1px; }
        
        .status-icon { width: 60px; height: 60px; background: var(--green); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 30px; margin: 0 auto; animation: pulse 2s infinite; }
        
        .action-buttons {
            position: fixed;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 380px;
            display: flex;
            gap: 8px;
            justify-content: space-between;
            z-index: 1000;
        }
        .action-btn {
            flex: 1;
            background: var(--green);
            color: white;
            padding: 14px 0;
            border-radius: 16px;
            text-align: center;
            font-weight: 700;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(76,175,80,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .action-btn.small {
            flex: 0.5;
        }
        .buy-btn-main {
            flex: 2;
            background: var(--green);
            color: white;
            padding: 14px 0;
            border-radius: 16px;
            text-align: center;
            font-weight: 700;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(76,175,80,0.3);
        }
        
        input, select, textarea { width: 100%; padding: 14px; margin-bottom: 10px; border-radius: 12px; border: 1px solid #ddd; font-size: 16px; }
        #loader { z-index: 10000; background: white; justify-content: center; }
        .spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid var(--green); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        .admin-user-item, .route-item { background: white; padding: 12px 15px; border-radius: 14px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #eee; }
        .status-select { width: auto !important; margin: 0 !important; padding: 8px !important; font-size: 13px !important; border-radius: 10px !important; background: #f8f8f8; }
        .secondary-btn { background: #e5e5ea; color: #000; padding: 14px; border-radius: 12px; margin-top: 10px; text-align: center; font-weight: bold; width: 100%; cursor: pointer; }
        .admin-btn { background: #000; color: white; padding: 14px; border-radius: 12px; margin-top: 10px; text-align: center; font-weight: bold; width: 100%; cursor: pointer; }
        .danger-btn { background: #ff3b30; color: white; border: none; padding: 12px; border-radius: 12px; margin-top: 15px; font-weight: bold; width: 100%; cursor: pointer; }
        .success-btn { background: #4caf50; color: white; border: none; padding: 12px; border-radius: 12px; margin-top: 15px; font-weight: bold; width: 100%; cursor: pointer; }

        .search-container { width: 100%; margin-bottom: 15px; display: none; }
        #search-input { 
            width: 100%; 
            padding: 14px 14px 14px 45px; 
            border-radius: 12px; 
            border: 1px solid #ddd; 
            font-size: 16px; 
            background: white;
        }
        .right-column { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }

        #fullscreen-trigger {
            position: fixed;
            top: 0;
            left: 0;
            width: 60px;
            height: 60px;
            background: transparent;
            z-index: 20000;
            cursor: pointer;
        }

        #scanner-modal {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 30000;
            display: none;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        #scanner-modal video {
            width: 90%;
            max-width: 500px;
            border-radius: 20px;
        }
        #scanner-modal .close-btn {
            margin-top: 20px;
            background: red;
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 30px;
            font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>
<body>

<div id="fullscreen-trigger" ondblclick="toggleFullScreen()"></div>

<!-- Модалка сканера QR -->
<div id="scanner-modal">
    <video id="scanner-video"></video>
    <button class="close-btn" onclick="closeScanner()">Закрыть</button>
</div>

<div id="app-data" style="display:none;" data-json='<?php echo json_encode($jsData, JSON_HEX_TAG); ?>'></div>

<div id="loader" class="screen"><div class="spinner"></div></div>

<div id="success-screen" class="screen">
    <div class="success-checkmark">
        <svg class="check-icon" viewBox="0 0 52 52">
            <circle class="check-circle" cx="26" cy="26" r="25" fill="none"/>
            <path class="check-path" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
        </svg>
    </div>
    <h2 style="color:var(--green)">Оплачено</h2>
</div>

<?php if(!isset($_SESSION['user_id']) || $_SESSION['status'] == 0): ?>
    <!-- Экран входа -->
    <div class="screen active-screen" style="justify-content: center; text-align: center;">
        <div style="background:white; padding:30px; border-radius:20px; width:100%; max-width:340px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
            <?php if(isset($_SESSION['user_id'])): ?>
                <div style="font-size:60px;">🔒</div>
                <h2>Доступ закрыт</h2>
                <p style="color:gray;">Ожидайте активации.</p>
                <button onclick="location.href='auth.php?action=logout'" class="danger-btn">Выйти</button>
            <?php else: ?>
                <div id="login-form">
                    <h2>Proezd.kz</h2>
                    <input type="text" id="l-phone" placeholder="Телефон" value="+7">
                    <input type="password" id="l-pass" placeholder="Пароль">
                    <button class="buy-btn-main" style="width:100%; margin-bottom:15px;" onclick="handleAuth()">Войти</button>
                    <div style="color:var(--green); font-weight:600; cursor:pointer;" onclick="toggleAuth(true)">Регистрация</div>
                </div>
                <div id="reg-form" style="display:none;">
                    <h2>Регистрация</h2>
                    <input type="text" id="r-name" placeholder="Имя">
                    <input type="text" id="r-phone" placeholder="Телефон" value="+7">
                    <input type="password" id="r-pass" placeholder="Пароль">
                    <button class="buy-btn-main" style="width:100%; margin-bottom:15px;" onclick="handleReg()">Создать</button>
                    <div style="color:var(--gray); cursor:pointer;" onclick="toggleAuth(false)">Назад</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Основное приложение -->
    <div id="screen-list" class="screen active-screen">
        <div class="container">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0;">
                <b style="font-size:24px;">Мои билеты</b>
                <div onclick="showScreen('screen-profile')" style="cursor:pointer;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#111" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                </div>
            </div>
            <div class="tabs">
                <span id="tab-act" class="tab active" onclick="switchTab('active')">Активные</span>
                <span id="tab-his" class="tab" onclick="switchTab('history')">История</span>
            </div>
            
            <div id="search-container" class="search-container">
                <div style="position:relative;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--gray);">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input id="search-input" type="text" placeholder="Поиск по маршруту, госномеру, дате или коду...">
                </div>
            </div>
            
            <div id="list-container" class="ticket-list"></div>
        </div>
        
        <div class="action-buttons">
            <button class="action-btn small" onclick="openScanner()">📷</button>
            <button class="buy-btn-main" onclick="startPurchase()">Купить билет</button>
            <button class="action-btn small" onclick="searchByCode()">🔍</button>
        </div>
    </div>

    <div id="screen-ticket" class="screen">
        <div class="container" style="overflow-y:auto;">
            <div class="white-card" style="padding:20px; text-align:center;">
                <div style="display:flex; justify-content:space-between; font-size:22px; color:#000; margin-bottom:15px;">
                    <span style="text-align:left;">Onay. Оплата проезда по QR-коду или коду транспорта</span>
                    <b style="font-size:24px; padding-left:10px; cursor:pointer;" onclick="location.reload()">✕</b>
                </div>
                <div id="v-icon" class="status-icon" onclick="secretRefresh()">✓</div>
                <div id="v-status" style="font-weight:700; margin-top:10px;">Билет активен</div>
                <div id="v-timer" class="timer">02:00:00</div>
            </div>
            <div class="white-card">
                <div class="info-grid">
                    <div class="info-cell"><span class="label">Дата оплаты</span><span class="value" id="v-date"></span></div>
                    <div class="info-cell"><span class="label">Время оплаты</span><span class="value" id="v-time"></span></div>
                </div>
                <div class="info-grid">
                    <div class="info-cell" onclick="manualEdit('price')"><span class="label">Стоимость</span><span class="value" id="v-price"></span></div>
                    <div class="info-cell" onclick="manualEdit('route')"><span class="label">Номер маршрута</span><span class="value" id="v-route"></span></div>
                </div>
                <div class="info-grid">
                    <div class="info-cell" onclick="manualEdit('gos')"><span class="label">Госномер</span><span class="value" id="v-gos"></span></div>
                    <div class="info-cell" onclick="manualEdit('code')"><span class="label">Билет</span><span class="value" id="v-code"></span></div>
                </div>
            </div>
            <div class="white-card" style="text-align:center; padding:20px;"><img id="v-qr" src="" style="width:150px;"></div>
        </div>
    </div>

    <div id="screen-profile" class="screen">
        <div class="container">
            <div style="display:flex; justify-content:space-between; padding:10px 0;"><b>Профиль</b><b onclick="showScreen('screen-list')" style="cursor:pointer;">✕</b></div>
            <div class="white-card" style="padding:20px; text-align:center;">
                <div style="font-size:50px;">👤</div>
                <h3 style="margin:5px 0;"><?php echo e($_SESSION['user_name'] ?? ''); ?></h3>
                <p style="color:gray; margin:0 0 15px 0;"><?php echo e($userData['phone'] ?? ''); ?></p>
                <div style="background: #f2f2f7; padding: 12px; border-radius: 12px; margin-bottom: 15px;">
                    <span style="font-size: 10px; color: var(--gray); text-transform: uppercase;">Всего поездок</span><br>
                    <b style="font-size: 22px; color: var(--green);"><?php echo (int)($userData['total_actions'] ?? 0); ?></b>
                </div>
                <div id="profile-settings" style="display:none; text-align: left; border-top: 1px solid #eee; padding-top:15px;">
                    <p><b>Цена (₸):</b> <input type="number" id="p-price" value="<?php echo (int)($_SESSION['def_price'] ?? 80); ?>"></p>
                    <p><b>Буква:</b> <input type="text" id="p-letter" value="<?php echo e($_SESSION['def_letter'] ?? ''); ?>" style="text-transform:uppercase;"></p>
                    <button onclick="saveSettings()" style="background:var(--green); color:white; border:none; padding:12px; border-radius:12px; width:100%; font-weight:bold; cursor:pointer;">Сохранить</button>
                    <button onclick="clearHistory()" class="danger-btn">🗑 Очистить историю</button>
                </div>
                <div class="secondary-btn" id="set-btn" onclick="document.getElementById('profile-settings').style.display='block'; this.style.display='none';">Настройки</div>
                <?php if(($_SESSION['status'] ?? 0) == 4): ?>
                    <div class="admin-btn" onclick="openAdminPanel()">⚙️ Админка</div>
                    <div class="admin-btn" style="background:#2196F3;" onclick="openRoutesPanel()">🚌 Маршруты</div>
                <?php endif; ?>
            </div>
            <button class="danger-btn" style="margin-top:10px;" onclick="location.href='auth.php?action=logout'">Выйти</button>
        </div>
    </div>

    <!-- Админка: пользователи -->
    <div id="screen-admin" class="screen">
        <div class="container">
            <div style="display:flex; justify-content:space-between; padding:10px 0;"><b>Пользователи</b><b onclick="showScreen('screen-profile')" style="cursor:pointer;">✕</b></div>
            <div id="admin-user-list" style="overflow-y:auto; flex-grow:1;"></div>
        </div>
    </div>

    <!-- Админка: маршруты -->
    <div id="screen-routes" class="screen">
        <div class="container">
            <div style="display:flex; justify-content:space-between; padding:10px 0;">
                <b>Маршруты</b>
                <div>
                    <button onclick="showAddRouteForm()" style="background:var(--green); color:white; border:none; padding:8px 15px; border-radius:20px; font-weight:bold; cursor:pointer;">➕ Добавить</button>
                    <b onclick="showScreen('screen-profile')" style="cursor:pointer; margin-left:15px;">✕</b>
                </div>
            </div>
            <div id="routes-list" style="overflow-y:auto; flex-grow:1;"></div>
        </div>
    </div>

    <!-- Форма добавления/редактирования маршрута -->
    <div id="route-form-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:40000;">
        <div style="background:white; padding:20px; border-radius:20px; width:90%; max-width:400px;">
            <h3 id="route-form-title">Добавить маршрут</h3>
            <input type="hidden" id="route-id">
            <input type="text" id="route-code" placeholder="Код (например 060605)" style="margin-bottom:10px;">
            <input type="text" id="route-route" placeholder="Маршрут (например 3E)" style="margin-bottom:10px;">
            <input type="text" id="route-gos" placeholder="Госномер (например 905BL09)" style="margin-bottom:10px;">
            <input type="number" id="route-price" placeholder="Цена (необязательно)" style="margin-bottom:10px;">
            <div style="display:flex; gap:10px;">
                <button onclick="saveRoute()" class="success-btn" style="margin:0;">Сохранить</button>
                <button onclick="closeRouteForm()" class="danger-btn" style="margin:0;">Отмена</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function toggleFullScreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen();
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }
}

let appData = {};
try {
    const dataDiv = document.getElementById('app-data');
    if (dataDiv) {
        appData = JSON.parse(dataDiv.dataset.json);
    }
} catch (e) {}

const userData = appData.user || {};
const isAuthenticated = appData.isAuthenticated;
const isAdmin = appData.isAdmin;

let tickets = [];
let curIdx = -1;
let currentTab = 'active';
let clickCounters = {};
let searchQuery = '';
let historyClickCounters = {};
let historyClickTimers = {};
let secretTaps = 0;
let secretTimer;

let scanner = null;

async function loadTickets() {
    let r = await fetch('auth.php?action=get_tickets');
    let data = await r.json();
    if(Array.isArray(data)) {
        tickets = data.map(t => ({
            db_id: t.id, route: t.route, gos: t.gos, price: t.price, 
            date: t.date_pay, time: t.time_pay, code: t.ticket_code, end: parseInt(t.end_time)
        }));
        render();
    }
}
<?php if(isset($_SESSION['user_id']) && $_SESSION['status'] != 0): ?> loadTickets(); <?php endif; ?>

function render() {
    let cont = document.getElementById('list-container');
    if(!cont) return;
    cont.innerHTML = '';
    let now = Date.now();
    let filtered = tickets.filter(t => currentTab === 'active' ? now < t.end : now >= t.end);
    
    if (currentTab === 'history' && searchQuery) {
        const q = searchQuery.toLowerCase();
        filtered = filtered.filter(t => 
            t.route.toLowerCase().includes(q) ||
            t.gos.toLowerCase().includes(q) ||
            t.date.toLowerCase().includes(q) ||
            t.code.toLowerCase().includes(q)
        );
    }
    
    filtered.forEach(t => {
        let i = tickets.indexOf(t);
        let div = document.createElement('div');
        div.className = 'ticket-item';
        div.innerHTML = `
            <div class="ticket-info-left">
                <b>Маршрут ${t.route}</b>
                <small style="color:gray;">${t.gos}</small>
                <span class="ticket-date-label">${t.date} ${t.time}</span>
            </div>
            <div class="right-column">
                <b>${t.price}</b>
            </div>`;
        
        let timer;
        const start = () => timer = setTimeout(() => confirmDelete(t.db_id), 1000);
        const cancel = () => clearTimeout(timer);
        div.addEventListener('touchstart', start);
        div.addEventListener('touchend', cancel);
        div.addEventListener('mousedown', start);
        div.addEventListener('mouseup', cancel);
        
        if (currentTab === 'active') {
            div.addEventListener('click', () => { if(timer) clearTimeout(timer); curIdx = i; openTicket(); });
        } else {
            div.addEventListener('click', () => { if(timer) clearTimeout(timer); handleHistoryClick(i); });
        }
        cont.appendChild(div);
    });
}

function handleHistoryClick(idx) {
    if (!historyClickCounters[idx]) historyClickCounters[idx] = 0;
    historyClickCounters[idx]++;
    clearTimeout(historyClickTimers[idx]);
    historyClickTimers[idx] = setTimeout(() => {
        if (historyClickCounters[idx] === 3) {
            renewTicket(idx);
        } else {
            curIdx = idx;
            openTicket();
        }
        historyClickCounters[idx] = 0;
    }, 400);
}

async function renewTicket(idx) {
    const old = tickets[idx];
    document.getElementById('loader').classList.add('active-screen');
    let now = new Date();
    
    let endTimeMs = Date.now() + 7200000; 
    
    let newT = {
        route: old.route,
        gos: old.gos,
        price: old.price,
        date: now.toLocaleDateString('ru-RU'),
        time: now.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}),
        code: Math.random().toString(36).substring(2,8).toUpperCase(), 
        end: endTimeMs
    };
    let f = new FormData();
    for(let k in newT) f.append(k, newT[k]);
    await fetch('auth.php?action=save_ticket', {method:'POST', body:f});
    let r_upd = await fetch('auth.php?action=get_tickets');
    tickets = (await r_upd.json()).map(item => ({
        db_id: item.id, route: item.route, gos: item.gos, price: item.price, 
        date: item.date_pay, time: item.time_pay, code: item.ticket_code, end: parseInt(item.end_time)
    }));
    document.getElementById('loader').classList.remove('active-screen');
    document.getElementById('success-screen').classList.add('active-screen');
    setTimeout(() => {
        document.getElementById('success-screen').classList.remove('active-screen');
        curIdx = 0;
        openTicket();
    }, 1800);
}

async function confirmDelete(id) {
    if(confirm("Удалить этот билет?")) {
        let f = new FormData(); f.append('id', id);
        await fetch('auth.php?action=delete_ticket', {method:'POST', body:f});
        tickets = tickets.filter(t => t.db_id != id);
        render();
    }
}

function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active-screen'));
    document.getElementById(id).classList.add('active-screen');
}

function switchTab(t) {
    currentTab = t;
    document.getElementById('tab-act').classList.toggle('active', t === 'active');
    document.getElementById('tab-his').classList.toggle('active', t === 'history');
    const searchCont = document.getElementById('search-container');
    searchCont.style.display = (t === 'history') ? 'block' : 'none';
    if (t === 'active') searchQuery = '';
    render();
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            searchQuery = searchInput.value.trim();
            render();
        });
    }
});

function updateTimerDisplay() {
    if(curIdx === -1) return;
    let diff = tickets[curIdx].end - Date.now();
    let timerEl = document.getElementById('v-timer');
    if(diff <= 0) {
        timerEl.innerText = "00:00:00";
        document.getElementById('v-icon').style.background = "gray";
        document.getElementById('v-icon').style.animation = "none";
        document.getElementById('v-status').innerText = "Билет истек";
    } else {
        let hours = Math.floor(diff / (1000 * 60 * 60));
        let mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        let secs = Math.floor((diff % (1000 * 60)) / 1000);
        timerEl.innerText = String(hours).padStart(2, '0') + ":" + String(mins).padStart(2, '0') + ":" + String(secs).padStart(2, '0');
        document.getElementById('v-icon').style.background = "var(--green)";
        document.getElementById('v-icon').style.animation = "pulse 2s infinite";
        document.getElementById('v-status').innerText = "Билет активен";
    }
}

function openTicket() {
    let t = tickets[curIdx];
    document.getElementById('v-date').innerText = t.date;
    document.getElementById('v-time').innerText = t.time;
    document.getElementById('v-price').innerText = t.price;
    document.getElementById('v-route').innerText = t.route;
    document.getElementById('v-gos').innerText = t.gos;
    document.getElementById('v-code').innerText = t.code;
    document.getElementById('v-qr').src = `https://api.qrserver.com/v1/create-qr-code/?data=ONAY-${t.code}`;
    updateTimerDisplay(); showScreen('screen-ticket');
}

async function secretRefresh() {
    secretTaps++;
    clearTimeout(secretTimer);
    secretTimer = setTimeout(() => secretTaps = 0, 800);
    if (secretTaps === 3) {
        renewTicket(curIdx);
    }
}

async function manualEdit(fld) {
    if (!clickCounters[fld]) clickCounters[fld] = 0;
    clickCounters[fld]++;
    if (clickCounters[fld] === 3) {
        let fieldMap = {'route':'route', 'gos':'gos', 'price':'price', 'code':'ticket_code'};
        let val = prompt("Изменить " + fld + ":");
        if (val) {
            let cleanVal = val.toUpperCase();
            tickets[curIdx][fld] = cleanVal;
            let f = new FormData();
            f.append('id', tickets[curIdx].db_id);
            f.append('field', fieldMap[fld]);
            f.append('value', cleanVal);
            await fetch('auth.php?action=update_ticket_data', {method:'POST', body:f});
            openTicket();
        }
        clickCounters[fld] = 0;
    }
    setTimeout(() => clickCounters[fld] = 0, 1000);
}

function startPurchase() {
    let r = prompt("Маршрут:"); 
    let g = prompt("Госномер:");
    if(!r || !g) return;
    document.getElementById('loader').classList.add('active-screen');
    setTimeout(async () => {
        let now = new Date();
        let endTimeMs = Date.now() + 7200000; 
        
        let t = {
            route: r.toUpperCase() + (userData.def_letter || ''),
            gos: g.toUpperCase(), 
            price: (userData.def_price || 80) + " ₸",
            date: now.toLocaleDateString('ru-RU'),
            time: now.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}),
            code: Math.random().toString(36).substring(2,8).toUpperCase(), 
            end: endTimeMs
        };
        let f = new FormData(); for(let k in t) f.append(k, t[k]);
        await fetch('auth.php?action=save_ticket', {method:'POST', body:f});
        document.getElementById('loader').classList.remove('active-screen');
        document.getElementById('success-screen').classList.add('active-screen');
        setTimeout(async () => {
            let r_upd = await fetch('auth.php?action=get_tickets');
            tickets = (await r_upd.json()).map(item => ({
                db_id: item.id, route: item.route, gos: item.gos, price: item.price, 
                date: item.date_pay, time: item.time_pay, code: item.ticket_code, end: parseInt(item.end_time)
            }));
            curIdx = 0; openTicket();
            document.getElementById('success-screen').classList.remove('active-screen');
        }, 1800);
    }, 1200);
}

function openScanner() {
    const modal = document.getElementById('scanner-modal');
    modal.style.display = 'flex';
    
    if (!scanner) {
        const video = document.getElementById('scanner-video');
        scanner = new Instascan.Scanner({ video: video });
        scanner.addListener('scan', function (content) {
            console.log('Scanned:', content);
            let code = content.trim();
            if (code.includes('/')) {
                code = code.split('/').pop();
            }
            closeScanner();
            processCode(code);
        });
        
        Instascan.Camera.getCameras().then(function (cameras) {
            if (cameras.length > 0) {
                scanner.start(cameras[0]);
            } else {
                alert('Камеры не найдены');
            }
        }).catch(function (e) {
            console.error(e);
            alert('Ошибка доступа к камере');
        });
    } else {
        Instascan.Camera.getCameras().then(function (cameras) {
            if (cameras.length > 0) scanner.start(cameras[0]);
        });
    }
}

function closeScanner() {
    const modal = document.getElementById('scanner-modal');
    modal.style.display = 'none';
    if (scanner) {
        scanner.stop();
    }
}

function searchByCode() {
    let code = prompt("Введите код из QR или номер:");
    if (code) {
        processCode(code.trim());
    }
}

async function processCode(code) {
    document.getElementById('loader').classList.add('active-screen');
    
    let formData = new FormData();
    formData.append('code', code);
    
    let response = await fetch('auth.php?action=create_ticket_by_code', { method: 'POST', body: formData });
    let result = await response.json();
    
    document.getElementById('loader').classList.remove('active-screen');
    
    if (result.status === 'success') {
        let r = await fetch('auth.php?action=get_tickets');
        let data = await r.json();
        if(Array.isArray(data)) {
            tickets = data.map(t => ({
                db_id: t.id, route: t.route, gos: t.gos, price: t.price, 
                date: t.date_pay, time: t.time_pay, code: t.ticket_code, end: parseInt(t.end_time)
            }));
            
            if (tickets.length > 0) {
                tickets[0].end = Date.now() + 7200000;
            }
            
            render();
        }
        
        document.getElementById('success-screen').classList.add('active-screen');
        setTimeout(() => {
            document.getElementById('success-screen').classList.remove('active-screen');
            if (tickets.length > 0) {
                curIdx = 0; 
                openTicket();
            }
        }, 1500);
    } else {
        alert('Ошибка: ' + (result.message || 'Код не найден'));
    }
}

async function openAdminPanel() {
    showScreen('screen-admin');
    let list = document.getElementById('admin-user-list');
    list.innerHTML = 'Загрузка...';
    let r = await fetch('auth.php?action=get_all_users');
    let users = await r.json();
    list.innerHTML = '';
    users.forEach(u => {
        let div = document.createElement('div');
        div.className = 'admin-user-item';
        div.innerHTML = `
            <div style="display:flex; flex-direction:column;">
                <b>${u.name} (<span id="cnt-${u.id}">${u.total_actions || 0}</span>) 
                <span onclick="resetCounter(${u.id})" style="cursor:pointer;">🔄</span>
                <span onclick="deleteUser(${u.id})" style="cursor:pointer; color:red; margin-left:10px;">🗑️</span></b>
                <small>${u.phone}</small>
            </div>
            <select class="status-select" onchange="changeStatus(${u.id}, this.value)">
                <option value="0" ${u.status == 0 ? 'selected' : ''}>Блок 🔴</option>
                <option value="1" ${u.status == 1 ? 'selected' : ''}>Актив 🟢</option>
                <option value="4" ${u.status == 4 ? 'selected' : ''}>Админ ⭐</option>
            </select>`;
        list.appendChild(div);
    });
}

async function deleteUser(id) {
    if (confirm('Вы уверены, что хотите удалить этого пользователя? Это также удалит все его билеты.')) {
        let f = new FormData(); f.append('id', id);
        await fetch('auth.php?action=delete_user', { method: 'POST', body: f });
        openAdminPanel(); 
    }
}

async function resetCounter(id) {
    if(confirm("Обнулить поездки?")) {
        let f = new FormData(); f.append('id', id);
        await fetch('auth.php?action=reset_user_counter', {method:'POST', body:f});
        document.getElementById('cnt-'+id).innerText = '0';
    }
}

async function changeStatus(id, stat) {
    let f = new FormData(); f.append('id', id); f.append('status', stat);
    await fetch('auth.php?action=update_user_status', {method:'POST', body:f});
}

let currentRouteId = null;

function openRoutesPanel() {
    showScreen('screen-routes');
    loadRoutes();
}

async function loadRoutes() {
    let list = document.getElementById('routes-list');
    list.innerHTML = 'Загрузка...';
    let r = await fetch('auth.php?action=get_routes');
    let routes = await r.json();
    list.innerHTML = '';
    routes.forEach(route => {
        let div = document.createElement('div');
        div.className = 'route-item';
        div.innerHTML = `
            <div>
                <b>${route.code}</b> — ${route.route} (${route.gos})<br>
                <small>Цена: ${route.price ? route.price + ' ₸' : 'по умолчанию'}</small>
            </div>
            <div>
                <span onclick="editRoute(${route.id}, '${route.code}', '${route.route}', '${route.gos}', ${route.price})" style="cursor:pointer; margin-right:10px;">✏️</span>
                <span onclick="deleteRoute(${route.id})" style="cursor:pointer; color:red;">🗑️</span>
            </div>`;
        list.appendChild(div);
    });
}

function showAddRouteForm() {
    currentRouteId = null;
    document.getElementById('route-id').value = '';
    document.getElementById('route-code').value = '';
    document.getElementById('route-route').value = '';
    document.getElementById('route-gos').value = '';
    document.getElementById('route-price').value = '';
    document.getElementById('route-form-title').innerText = 'Добавить маршрут';
    document.getElementById('route-form-modal').style.display = 'flex';
}

function editRoute(id, code, route, gos, price) {
    currentRouteId = id;
    document.getElementById('route-id').value = id;
    document.getElementById('route-code').value = code;
    document.getElementById('route-route').value = route;
    document.getElementById('route-gos').value = gos;
    document.getElementById('route-price').value = price || '';
    document.getElementById('route-form-title').innerText = 'Редактировать маршрут';
    document.getElementById('route-form-modal').style.display = 'flex';
}

function closeRouteForm() {
    document.getElementById('route-form-modal').style.display = 'none';
}

async function saveRoute() {
    let id = document.getElementById('route-id').value;
    let code = document.getElementById('route-code').value.trim();
    let route = document.getElementById('route-route').value.trim();
    let gos = document.getElementById('route-gos').value.trim();
    let price = document.getElementById('route-price').value.trim();
    
    if (!code || !route || !gos) {
        alert('Заполните код, маршрут и госномер');
        return;
    }
    
    let formData = new FormData();
    formData.append('code', code);
    formData.append('route', route);
    formData.append('gos', gos);
    if (price) formData.append('price', price);
    
    let action = id ? 'update_route' : 'add_route';
    if (id) formData.append('id', id);
    
    let response = await fetch('auth.php?action=' + action, { method: 'POST', body: formData });
    let result = await response.json();
    
    if (result.status === 'success') {
        closeRouteForm();
        loadRoutes();
    } else {
        alert(result.message || 'Ошибка');
    }
}

async function deleteRoute(id) {
    if (confirm('Удалить этот маршрут?')) {
        let f = new FormData(); f.append('id', id);
        await fetch('auth.php?action=delete_route', { method: 'POST', body: f });
        loadRoutes();
    }
}

function toggleAuth(isReg) {
    document.getElementById('login-form').style.display = isReg ? 'none' : 'block';
    document.getElementById('reg-form').style.display = isReg ? 'block' : 'none';
}
async function handleAuth() {
    let f = new FormData(); f.append('phone', document.getElementById('l-phone').value); f.append('password', document.getElementById('l-pass').value);
    let r = await fetch('auth.php?action=login', {method:'POST', body:f});
    if((await r.json()).status === 'success') location.reload(); else alert("Ошибка");
}
async function handleReg() {
    let f = new FormData(); f.append('name', document.getElementById('r-name').value); f.append('phone', document.getElementById('r-phone').value); f.append('password', document.getElementById('r-pass').value);
    let r = await fetch('auth.php?action=register', {method:'POST', body:f});
    if((await r.json()).status === 'success') { alert("Ждите активации."); toggleAuth(false); } else alert("Ошибка");
}
async function saveSettings() {
    let f = new FormData(); f.append('price', document.getElementById('p-price').value); f.append('letter', document.getElementById('p-letter').value);
    await fetch('auth.php?action=save_settings', {method:'POST', body:f}); location.reload();
}
async function clearHistory() {
    if(confirm("Очистить историю?")) {
        await fetch('auth.php?action=clear_history');
        tickets = []; render(); showScreen('screen-list');
    }
}
setInterval(updateTimerDisplay, 1000);
</script>
</body>
</html>