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
  <title>安装向导 - 菜鸟系统</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  
  <link rel="stylesheet" href="/element/css/index.css" />
  <script src="/element/js/vue.global.js"></script>
  <script src="/element/js/index.full.js"></script>
  <script src="/config.js"></script>

  <style>
    html, body {
      margin: 0;
      padding: 0;
      height: 100%;
      font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
      background: linear-gradient(135deg, #76E8D5, #A8BAE4);
    }

    #app {
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }

    .install-card {
      width: 600px;
      padding: 30px;
      background: white;
      border-radius: 10px;
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
    }

    .step-content {
      margin-top: 30px;
      flex: 1;
    }

    .step-buttons {
      display: flex;
      justify-content: space-between;
      margin-top: 30px;
    }

    .env-check-item {
      margin-bottom: 10px;
      font-size: 15px;
    }

    .env-ok {
      color: green;
    }

    .env-error {
      color: red;
    }
  </style>
</head>
<body>
  <div id="app">
    <div class="install-card">
      <el-steps :active="step" finish-status="success" align-center>
        <el-step title="安装协议"></el-step>
        <el-step title="环境检测"></el-step>
        <el-step title="数据库配置"></el-step>
        <el-step title="安装完成"></el-step>
      </el-steps>

      <div class="step-content">
        <!-- 步骤内容 -->
        <div v-if="step === 0">
          <h3>软件安装协议</h3>
          <p style="line-height: 1.8; font-size: 14px; color: #555;">
            欢迎使用菜鸟云注入系统。本系统由菜鸟八哥提供，仅限授权用户使用。请阅读以下协议内容，继续操作代表您同意本协议全部条款：
            <br><br>
            1. 本系统不得用于非法用途；<br>
            2. 禁止对系统进行未经授权的二次开发和传播；<br>
            3. 安装过程中的数据操作请谨慎处理，我们不承担任何数据损失责任。<br><br>
            如您同意以上条款，请点击“下一步”继续。
          </p>
        </div>

        <div v-if="step === 1">
          <h3>环境检测结果</h3>
          <div v-if="loading">正在检测环境，请稍候...</div>
          <div v-else>
            <div
              class="env-check-item"
              v-for="(item, key) in envResult"
              :key="key"
              :class="item.status ? 'env-ok' : 'env-error'"
            >
              <span v-if="item.status">✔</span>
              <span v-else>✘</span>
              {{ key }}：
              {{ item.status ? '通过' : (item.error || '失败') }}
              <template v-if="item.value">
                （<span style="color: #666;">{{ item.value }}</span>）
              </template>
              <br v-if="!item.status && item.install" />
              <span v-if="!item.status && item.install" style="font-size: 13px; color: #999;">
                安装建议：<code>{{ item.install }}</code>
              </span>
            </div>
            <!-- 安装提示信息 -->
              <div style="margin-top: 20px; padding: 12px; background: #fff7e6; border: 1px solid #f5c37d; color: #c87d0a; border-radius: 4px; font-size: 14px;">
                如果您尚未安装 Android 依赖环境，可执行以下命令一键安装(仅限于Ubunt安装)：<br>
                <code style="background: #f0f0f0; padding: 2px 6px; display: inline-block; margin-top: 5px;">sudo apt install google-android-build-tools-29.0.3-installer</code>
              </div>
              <div style="margin-top: 20px; padding: 12px; background: #fff7e6; border: 1px solid #f5c37d; color: #c87d0a; border-radius: 4px; font-size: 14px;">
                非Ubunt系统需要手动安装以下环境：<br>
                <code style="background: #f0f0f0; padding: 2px 6px; display: inline-block; margin-top: 5px;">JAVA环境21版本 + 完整AndroidSDK29.0.3版本</code>
                <code style="background: #f0f0f0; padding: 2px 6px; display: inline-block; margin-top: 5px;">同时需要设置好环境变量，或将AndroidSDK的路径添加到PHP配置中确保PHP能够执行aapt和zipalign等SKD的命令</code>
              </div>
          </div>
        </div>



        <div v-if="step === 2">
          <h3>数据库及管理员配置(必须用数据库root用户)</h3>
          <el-form :model="form" label-width="100px">
            <el-form-item label="数据库地址">
              <el-input v-model="form.dbHost" placeholder="如：127.0.0.1"></el-input>
            </el-form-item>
            <el-form-item label="数据库名称">
              <el-input v-model="form.dbName" placeholder="如：my_database"></el-input>
            </el-form-item>
            <el-form-item label="数据库用户">
              <el-input v-model="form.dbUser" placeholder="如：root"></el-input>
            </el-form-item>
            <el-form-item label="数据库密码">
              <el-input type="password" v-model="form.dbPass"></el-input>
            </el-form-item>
            <el-form-item label="管理员账号">
              <el-input v-model="form.adminUser" placeholder="如：admin"></el-input>
            </el-form-item>
            <el-form-item label="管理员密码">
              <el-input type="password" v-model="form.adminPass"></el-input>
            </el-form-item>
          </el-form>
        </div>

        <div v-if="step === 3">
          <h3>安装完成</h3>
          <p style="font-size: 16px; color: #409EFF;">🎉 系统已成功安装，点击下方按钮进入后台管理系统。</p>
        </div>
      </div>

      <div class="step-buttons">
        <el-button v-if="step > 0" @click="prevStep">上一步</el-button>
        <el-button
          type="primary"
          @click="nextStep"
          :disabled="step === 1 && !envCheckPassed"
        >{{ step < 3 ? '下一步' : '进入后台' }}</el-button>
      </div>
    </div>
  </div>

  <script>
    const { createApp, reactive, ref, watch } = Vue;

    createApp({
      setup() {
        const step = ref(0);
        const loading = ref(false);
        const envResult = ref({});
        const envCheckPassed = ref(false);

        const form = reactive({
          dbHost: '127.0.0.1',
          dbName: 'apktool',
          dbUser: 'root',
          dbPass: '',
          adminUser: 'admin',
          adminPass: 'admin'
        });


        async function nextStep() {
          if (step.value === 2) {
            // 显示加载提示
            const loadingInstance = ElementPlus.ElLoading.service({
              lock: true,
              text: '正在提交安装信息...',
              background: 'rgba(0, 0, 0, 0.3)'
            });
        
            try {
              const res = await fetch('install_db.php', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json'
                },
                body: JSON.stringify(form)
              });
        
              const json = await res.json();
        
              if (json.code === 200) {
                ElementPlus.ElMessage.success(json.message || '安装信息提交成功');
                step.value++; // 进入“安装完成”步骤
              } else {
                ElementPlus.ElMessage.error(json.message || '提交失败');
              }
            } catch (err) {
              ElementPlus.ElMessage.error('提交异常，请稍后重试');
            } finally {
              loadingInstance.close(); // 关闭 loading 提示
            }
            return;
          }
        
          // 其他步骤正常跳转
          if (step.value < 3) {
            step.value++;
          } else {
            window.location.href = '/console'+ config.pageSuffix;
          }
        }


        function prevStep() {
          if (step.value > 0) step.value--;
        }

        async function loadEnvCheck() {
          loading.value = true;
          envResult.value = {};
          envCheckPassed.value = false;

          try {
            const res = await fetch('check_env.php');
            const json = await res.json();
            if (json.code === 200 && json.data) {
              envResult.value = json.data;
              // 检查是否所有检测都通过
              envCheckPassed.value = Object.values(json.data).every(item => item.status === true);
            } else {
              envCheckPassed.value = false;
              ElementPlus.ElMessage.error('环境检测失败：返回数据异常');
            }
          } catch (err) {
            envCheckPassed.value = false;
            ElementPlus.ElMessage.error('接口请求失败或系统已安装');
          } finally {
            loading.value = false;
          }
        }

        watch(step, val => {
          if (val === 1) {
            loadEnvCheck();
          }
        });

        return {
          step,
          form,
          nextStep,
          prevStep,
          loading,
          envResult,
          envCheckPassed
        };
      }
    }).use(ElementPlus).mount('#app');
  </script>
</body>
</html>
