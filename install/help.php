<?php

// 配置锁路径
$lockFile = __DIR__ . '/../config/config.lock';
// 如果已安装，返回 404
if (file_exists($lockFile)) {
    http_response_code(404);
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ubuntu 系统开发环境安装教程</title>
<style>
    body {
        font-family: "Microsoft YaHei", sans-serif;
        line-height: 1.6;
        margin: 20px;
        background-color: #f9f9f9;
        color: #333;
    }
    h1, h2 {
        color: #2c3e50;
    }
    code {
        background-color: #eaeaea;
        padding: 2px 4px;
        border-radius: 4px;
    }
    pre {
        background-color: #eaeaea;
        padding: 10px;
        border-radius: 5px;
        overflow-x: auto;
    }
    .section {
        margin-bottom: 30px;
        padding: 15px;
        background-color: #fff;
        border-radius: 8px;
        box-shadow: 0 0 8px rgba(0,0,0,0.05);
    }
    .warning {
        color: #c0392b;
        font-weight: bold;
    }
    .verify {
        color: #27ae60;
        font-weight: bold;
    }
</style>
</head>
<body>

<h1>Ubuntu 系统开发环境安装教程</h1>
<p class="warning">此教程仅限于 Ubuntu 系统，推荐版本 24.04，可使用安装脚本一键安装，命令  ./install_android_sdk.sh</p>

<div class="section">
    <h2>一、安卓 SDK 安装</h2>
    <p>安装命令：</p>
    <pre><code>sudo apt install google-android-build-tools-29.0.3-installer</code></pre>

    <p>如果报错如下：</p>
    <pre><code>root@server:~# sudo apt install google-android-build-tools-29.0.3-installer
Reading package lists... Done
Building dependency tree... Done
Reading state information... Done
E: Unable to locate package google-android-build-tools-29.0.3-installer
E: Couldn't find any package by glob 'google-android-build-tools-29.0.3-installer'</code></pre>

    <p>解决方法：</p>
    <ol>
        <li>检查源文件 <code>/etc/apt/sources.list</code></li>
        <li>加入一条源记录：
            <pre><code>deb [arch=amd64] http://mirrors.cloud.tencent.com/ubuntu noble multiverse</code></pre>
        </li>
        <li>更新源：
            <pre><code>sudo apt update</code></pre>
        </li>
        <li>重新执行安装命令，提示输入 <code>y</code>，回车确认</li>
        <li>等待安装完成即可</li>
    </ol>

    <p class="verify">验证安装结果：</p>
    <pre><code>aapt version
aapt2 version
zipalign -v</code></pre>
</div>

<div class="section">
    <h2>二、Java 安装</h2>
    <p>安装命令：</p>
    <pre><code>sudo apt install openjdk-21-jdk</code></pre>

    <p class="verify">验证安装结果：</p>
    <pre><code>java -version
javac -version</code></pre>
</div>

<div class="section">
    <h2>三、Go 安装</h2>
    <p>安装命令：</p>
    <pre><code>sudo apt install -y golang-go</code></pre>

    <p class="verify">验证安装结果：</p>
    <pre><code>go version
go env</code></pre>
</div>


<div class="section">
    <h2>四、sdkmanager 安装（Android 命令行工具）</h2>

    <p>sdkmanager 用于安装 CMake、NDK 等 Android 组件。</p>

    <p>安装命令：</p>
    <pre><code>sudo apt update
sudo apt install -y google-android-cmdline-tools-8.0-installer</code></pre>

    <p class="warning">注意：该工具为 Ubuntu 官方打包版本，安装后位于 /usr/lib/android-sdk 目录</p>

    <p class="verify">配置环境变量（建议加入 ~/.bashrc）：</p>
    <pre><code>export ANDROID_SDK_ROOT=/usr/lib/android-sdk
export ANDROID_HOME=/usr/lib/android-sdk
export PATH=$PATH:/usr/lib/android-sdk/cmdline-tools/8.0/bin</code></pre>

    <p class="verify">验证安装结果：</p>
    <pre><code>sdkmanager --version</code></pre>
</div>

<div class="section">
    <h2>五、CMake 安装</h2>

    <p>CMake 主要用于 Android Native / so 编译。</p>

    <p>安装指定版本（推荐 3.22.1）：</p>
    <pre><code>sdkmanager "cmake;3.22.1"</code></pre>

    <p class="verify">验证安装结果：</p>
    <pre><code>cmake --version</code></pre>
</div>

<div class="section">
    <h2>六、Android NDK 安装</h2>

    <p>NDK 用于编译 Android Native so 文件。</p>

    <p>安装指定版本（推荐 26.1.10909125）：</p>
    <pre><code>sdkmanager "ndk;26.1.10909125"</code></pre>

    <p class="verify">验证安装结果：</p>
    <pre><code>ls /usr/lib/android-sdk/ndk
clang --version</code></pre>
</div>



</body>
</html>
