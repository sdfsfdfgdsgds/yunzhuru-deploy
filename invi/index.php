<?php
// 参数验证和获取
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';
$valid_sid = '';

if (preg_match('/^\d+$/', $sid)) {
    $valid_sid = intval($sid);
}

if (empty($valid_sid)) {
    exit;
}

// 生成自定义协议链接
$deep_link = "yunzhuru://data/app?superior={$valid_sid}";
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>注册账号</title>
<meta name="description" content="注册账号并下载客户端">

<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: -apple-system, BlinkMacSystemFont, "PingFang SC","Microsoft YaHei", sans-serif;
    background: linear-gradient(180deg,#f4f6fb,#eef1f6);
    min-height: 100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    color:#1f2937;
}

.app-card {
    width:100%;
    max-width:420px;
    background:#fff;
    border-radius:16px;
    box-shadow:0 20px 45px rgba(0,0,0,.08);
    overflow:hidden;
}

.app-header {
    padding:28px 24px 22px;
    border-bottom:1px solid #f0f0f0;
}

.app-header h1 {
    font-size:22px;
    font-weight:700;
    margin-bottom:6px;
}

.app-header p {
    font-size:14px;
    color:#6b7280;
}

.app-content { padding:24px; }

.form-group { margin-bottom:18px; }

label {
    display:block;
    font-size:14px;
    font-weight:600;
    margin-bottom:6px;
}

label.required::after {
    content:" *";
    color:#ef4444;
}

.form-control {
    width:100%;
    height:44px;
    padding:0 12px;
    border-radius:10px;
    border:1.8px solid #e5e7eb;
    font-size:15px;
    transition:.2s;
}

.form-control::placeholder { color:#9ca3af; }

.form-control:focus {
    outline:none;
    border-color:#4f46e5;
    box-shadow:0 0 0 3px rgba(79,70,229,.12);
}

.form-control.error { border-color:#ef4444; }

.error-message {
    display:none;
    margin-top:5px;
    font-size:13px;
    color:#ef4444;
}

.btn {
    width:100%;
    height:46px;
    border:none;
    border-radius:12px;
    font-size:16px;
    font-weight:600;
    cursor:pointer;
    transition:.25s;
}

.btn:disabled {
    opacity:.65;
    cursor:not-allowed;
}

.btn-primary { background:#4f46e5; color:#fff; }
.btn-primary:hover:not(:disabled) { background:#4338ca; }

.btn-secondary {
    background:#f3f4f6;
    color:#374151;
    margin-top:10px;
}
.btn-secondary:hover { background:#e5e7eb; }

.btn-download {
    background:#10b981;
    color:#fff;
    margin-top:12px;
}
.btn-download:hover { background:#059669; }

.section-divider {
    margin:28px 0 16px;
    height:1px;
    background:#f0f0f0;
}

.launch-box { text-align:center; }
.launch-box p {
    font-size:14px;
    color:#6b7280;
    margin-bottom:8px;
}

.success-message,
.error-message-box {
    display:none;
    padding:14px;
    border-radius:10px;
    text-align:center;
    margin-top:16px;
    font-size:14px;
    line-height:1.5;
}

.success-message { background:#ecfdf5; color:#065f46; }
.error-message-box { background:#fef2f2; color:#991b1b; }

@media (max-width:420px){
    .app-card { border-radius:12px; }
}
</style>
</head>

<body>
<div class="app-card">

    <div class="app-header">
        <h1>注册账号</h1>
        <p>注册成功后将自动跳转首页</p>
    </div>

    <div class="app-content">
        <form id="registerForm">
            <div class="form-group">
                <label>昵称（选填）</label>
                <input class="form-control"
                       id="nickname"
                       maxlength="20"
                       placeholder="设置一个昵称（可不填写）">
            </div>

            <div class="form-group">
                <label class="required">账号</label>
                <input class="form-control"
                       id="account"
                       maxlength="20"
                       required
                       placeholder="5-20 位字母或数字">
                <div class="error-message" id="accountError"></div>
            </div>

            <div class="form-group">
                <label class="required">密码</label>
                <input type="password"
                       class="form-control"
                       id="password"
                       maxlength="20"
                       required
                       placeholder="建议包含字母和数字">
                <div class="error-message" id="passwordError"></div>
            </div>

            <div class="form-group">
                <label class="required">确认密码</label>
                <input type="password"
                       class="form-control"
                       id="confirmPassword"
                       maxlength="20"
                       required
                       placeholder="再次输入密码">
                <div class="error-message" id="confirmPasswordError"></div>
            </div>

            <input type="hidden" id="superior" value="<?php echo htmlspecialchars($valid_sid); ?>">

            <button type="submit" class="btn btn-primary" id="submitBtn">立即注册</button>
        </form>

        <div class="success-message" id="successMessage"></div>
        <div class="error-message-box" id="errorMessage"></div>

        <div class="section-divider"></div>

        <div class="launch-box">
            <p>已安装客户端？</p>
            <button class="btn btn-secondary" onclick="openApp()" type="button">
                启动客户端（1.4.0+）
            </button>

            <button class="btn btn-download" type="button"
                    onclick="window.location.href='https://yunzhuru.com'">
                前往首页下载客户端
            </button>
        </div>
    </div>
</div>

<script>
const appLink = "<?php echo $deep_link; ?>";

function openApp() {
    window.location.href = appLink;
    setTimeout(() => {
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = appLink;
        document.body.appendChild(iframe);
        setTimeout(() => document.body.removeChild(iframe), 1200);
    }, 100);
}

// 显示错误
function showFieldError(inputId, msg) {
    const input = document.getElementById(inputId);
    const err = document.getElementById(inputId + "Error");
    if (input) input.classList.add("error");
    if (err) {
        err.textContent = msg;
        err.style.display = "block";
    }
}

function clearFieldError(inputId) {
    const input = document.getElementById(inputId);
    const err = document.getElementById(inputId + "Error");
    if (input) input.classList.remove("error");
    if (err) err.style.display = "none";
}

// 表单验证（沿用你原规则）
function validateForm() {
    let ok = true;
    const account = document.getElementById('account').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    clearFieldError("account");
    clearFieldError("password");
    clearFieldError("confirmPassword");

    if (!account) {
        showFieldError("account", "请输入账号");
        ok = false;
    } else if (account.length < 5 || account.length > 20) {
        showFieldError("account", "账号长度应为 5-20 位");
        ok = false;
    } else if (!/^[a-zA-Z0-9]+$/.test(account)) {
        showFieldError("account", "账号只能包含字母和数字");
        ok = false;
    }

    if (!password) {
        showFieldError("password", "请输入密码");
        ok = false;
    } else if (password.length < 5 || password.length > 20) {
        showFieldError("password", "密码长度应为 5-20 位");
        ok = false;
    }

    if (!confirmPassword) {
        showFieldError("confirmPassword", "请再次输入密码");
        ok = false;
    } else if (password !== confirmPassword) {
        showFieldError("confirmPassword", "两次输入的密码不一致");
        ok = false;
    }

    return ok;
}

// 输入时移除错误样式
["nickname","account","password","confirmPassword"].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener("input", () => clearFieldError(id));
});

// 注册提交（补齐：fetch + 成功倒计时跳转）
document.getElementById('registerForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const successBox = document.getElementById('successMessage');
    const errorBox = document.getElementById('errorMessage');
    successBox.style.display = "none";
    errorBox.style.display = "none";

    if (!validateForm()) return;

    const formData = {
        nickname: document.getElementById('nickname').value.trim(),
        account: document.getElementById('account').value.trim(),
        password: document.getElementById('password').value,
        confirm_password: document.getElementById('confirmPassword').value,
        superior: document.getElementById('superior').value
    };

    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = '注册中...';

    try {
        const response = await fetch('/api/index.php?module=register&method=register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });

        const result = await response.json();

        if (result.code === 200) {
            // 注册成功：展示 + 清空 + 倒计时跳转
            this.reset();

            let countdown = 3;
            successBox.textContent = `${result.message}，${countdown}秒后跳转到首页...`;
            successBox.style.display = "block";

            const timer = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    successBox.textContent = `${result.message}，${countdown}秒后跳转到首页...`;
                } else {
                    clearInterval(timer);
                    window.location.href = 'https://yunzhuru.com';
                }
            }, 1000);
        } else {
            errorBox.textContent = result.message || '注册失败，请稍后重试';
            errorBox.style.display = "block";
        }
    } catch (err) {
        errorBox.textContent = '网络错误，请检查网络连接后重试';
        errorBox.style.display = "block";
        console.error('注册请求失败:', err);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = '立即注册';
    }
});
</script>
</body>
</html>
