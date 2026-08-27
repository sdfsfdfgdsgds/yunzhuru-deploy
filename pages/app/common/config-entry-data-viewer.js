(function registerConfigEntryDataViewer(global) {
  'use strict';

  if (!global || !global.Vue) {
    throw new Error('配置数据查看组件需要先加载 Vue');
  }

  const { ref, reactive } = global.Vue;

  function replaceReactiveObject(target, source) {
    Object.keys(target).forEach(function removeOldKey(key) { delete target[key]; });
    Object.assign(target, source);
  }

  function createEmptyBucketMeta() {
    return {
      appId: 0,
      appName: '',
      bucketId: 0,
      bucketName: '',
      providerLabel: '',
      fileUrl: '',
      snapshotState: 'unknown',
      snapshotExact: 0,
      snapshotEvidence: '',
      taskId: 0,
      attemptNo: 0,
      updatedAt: '',
      checkedAt: '',
      cipherBytes: 0,
      plainBytes: 0,
      prettyBytes: 0,
      cipherSha256: '',
      plainSha256: '',
      prettySha256: '',
      fieldCount: 0,
      payloadAppId: null,
      appIdMatches: null,
      configDeliveryVersion: null,
      networkConfigVersion: null,
    };
  }

  function createEmptyApiMeta() {
    return {
      appId: 0,
      appName: '',
      apiId: 0,
      apiName: '',
      entryKey: '',
      deliveryUrl: '',
      requestUrl: '',
      httpCode: 0,
      contentType: '',
      responseEncoding: 'utf-8',
      dataSource: '',
      appDataSource: '',
      dataSourceTtl: '',
      dataTtl: '',
      configResolution: '',
      elapsedMs: null,
      checkedAt: '',
      responseBytes: 0,
      plainBytes: 0,
      prettyBytes: 0,
      responseSha256: '',
      plainSha256: '',
      prettySha256: '',
      fieldCount: 0,
      payloadAppId: null,
      appIdMatches: null,
      configDeliveryVersion: null,
      networkConfigVersion: null,
    };
  }

  async function copyText(text) {
    const value = String(text || '');
    if (!value) throw new Error('没有可复制的内容');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(value);
      return;
    }
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    try {
      textarea.select();
      if (!document.execCommand('copy')) throw new Error('浏览器复制命令执行失败');
    } finally {
      document.body.removeChild(textarea);
    }
  }

  const component = {
    name: 'ConfigEntryDataViewer',
    template: `
      <el-dialog
        v-model="bucketDialogVisible"
        class="config-entry-data-dialog"
        title="桶实际对象：加密与明文"
        append-to-body
        destroy-on-close
        @closed="resetBucket">
        <el-alert
          :title="bucketEvidenceText(bucketMeta)"
          :type="bucketMeta.snapshotState === 'verified' ? 'info' : 'warning'"
          show-icon
          :closable="false"
          style="margin-bottom: 14px;">
        </el-alert>

        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="应用">
            [{{ bucketMeta.appId || '-' }}] {{ bucketMeta.appName || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="固定桶">
            [{{ bucketMeta.bucketId || '-' }}] {{ bucketMeta.bucketName || '-' }}
            <span v-if="bucketMeta.providerLabel"> / {{ bucketMeta.providerLabel }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="制品证据">
            <el-tag size="small" :type="bucketStateType(bucketMeta.snapshotState)">
              {{ bucketStateLabel(bucketMeta.snapshotState) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="任务 / 构建尝试">
            {{ bucketMeta.taskId || '-' }} / {{ bucketMeta.attemptNo === 0 ? '0（历史任务）' : (bucketMeta.attemptNo || '-') }}
          </el-descriptions-item>
          <el-descriptions-item label="对象地址" :span="2">
            <el-link
              v-if="safeHttpUrl(bucketMeta.fileUrl)"
              class="config-entry-data-mono"
              type="primary"
              :href="safeHttpUrl(bucketMeta.fileUrl)"
              target="_blank"
              rel="noopener noreferrer">
              {{ bucketMeta.fileUrl }}
            </el-link>
            <span v-else class="config-entry-data-mono">{{ bucketMeta.fileUrl || '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="对象时间">{{ bucketMeta.updatedAt || '-' }}</el-descriptions-item>
          <el-descriptions-item label="读取时间">{{ bucketMeta.checkedAt || '-' }}</el-descriptions-item>
          <el-descriptions-item label="密文 / 原始明文大小">
            {{ bucketMeta.cipherBytes ? formatBytes(bucketMeta.cipherBytes) : '-' }}
            / {{ bucketMeta.plainBytes ? formatBytes(bucketMeta.plainBytes) : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="格式化明文大小">
            {{ bucketMeta.prettyBytes ? formatBytes(bucketMeta.prettyBytes) : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="APPID 校验">
            <el-tag size="small" :type="matchType(bucketMeta.appIdMatches)">
              {{ matchLabel(bucketMeta) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="配置版本">
            delivery={{ bucketMeta.configDeliveryVersion ?? '-' }}，network={{ bucketMeta.networkConfigVersion ?? '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="顶层字段数">{{ bucketMeta.fieldCount || '-' }}</el-descriptions-item>
          <el-descriptions-item label="密文 SHA-256" :span="2">
            <div class="config-entry-data-hash-row">
              <span class="config-entry-data-mono">{{ bucketMeta.cipherSha256 || '-' }}</span>
              <el-button v-if="bucketMeta.cipherSha256" type="primary" link @click="copyHash('密文 SHA-256', bucketMeta.cipherSha256)">复制</el-button>
            </div>
          </el-descriptions-item>
          <el-descriptions-item label="原始明文 SHA-256" :span="2">
            <div class="config-entry-data-hash-row">
              <span class="config-entry-data-mono">{{ bucketMeta.plainSha256 || '-' }}</span>
              <el-button v-if="bucketMeta.plainSha256" type="primary" link @click="copyHash('原始明文 SHA-256', bucketMeta.plainSha256)">复制</el-button>
            </div>
          </el-descriptions-item>
          <el-descriptions-item label="格式化展示 SHA-256" :span="2">
            <div class="config-entry-data-hash-row">
              <span class="config-entry-data-mono">{{ bucketMeta.prettySha256 || '-' }}</span>
              <el-button v-if="bucketMeta.prettySha256" type="primary" link @click="copyHash('格式化明文 SHA-256', bucketMeta.prettySha256)">复制</el-button>
            </div>
          </el-descriptions-item>
        </el-descriptions>

        <el-alert
          v-if="bucketError"
          :title="bucketError"
          type="error"
          show-icon
          :closable="false"
          style="margin-top: 14px;">
        </el-alert>

        <div
          class="config-entry-data-loading"
          v-loading="bucketLoading"
          element-loading-text="正在读取并解密桶对象">
          <div class="config-entry-data-section">
            <div class="config-entry-data-heading">
              <span>加密原文（Base64 AES 密文）</span>
              <el-button size="small" type="primary" :disabled="!bucketCiphertext" @click="copyBucketCiphertext">复制加密原文</el-button>
            </div>
            <pre v-if="bucketCiphertext" class="config-entry-data-code">{{ bucketCiphertext }}</pre>
            <el-empty v-else-if="!bucketLoading && !bucketError" description="当前对象没有可展示的密文"></el-empty>
          </div>

          <div class="config-entry-data-section">
            <div class="config-entry-data-heading">
              <span>解密明文</span>
              <div class="config-entry-data-actions">
                <el-radio-group v-model="bucketPlainViewMode" size="small" @change="changeBucketPlainView">
                  <el-radio-button label="pretty">格式化 JSON</el-radio-button>
                  <el-radio-button label="raw">原始明文</el-radio-button>
                </el-radio-group>
                <el-button size="small" type="primary" :disabled="!bucketPlainText" @click="copyBucketPlain">复制当前明文</el-button>
              </div>
            </div>
            <pre v-if="bucketPlainText" class="config-entry-data-code">{{ bucketPlainText }}</pre>
            <el-empty v-else-if="!bucketLoading && !bucketError" description="当前对象没有可展示的解密明文"></el-empty>
          </div>
        </div>

        <template #footer>
          <el-button @click="bucketDialogVisible = false">关闭</el-button>
        </template>
      </el-dialog>

      <el-dialog
        v-model="apiDialogVisible"
        class="config-entry-data-dialog"
        title="API 节点实际响应数据"
        append-to-body
        destroy-on-close
        @closed="resetApi">
        <el-alert
          title="这是当前控制面的即时诊断请求，与历史 task / attempt 制品证据分开；服务端固定模拟 shell_version=153，并使用应用当前资料。这里展示该次响应，不代表某台手机的本地缓存、DNS 或运营商链路。"
          type="info"
          show-icon
          :closable="false"
          style="margin-bottom: 14px;">
        </el-alert>

        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="应用">
            [{{ apiMeta.appId || '-' }}] {{ apiMeta.appName || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="API 节点">
            [{{ apiMeta.apiId || '-' }}] {{ apiMeta.apiName || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="下发 URL" :span="2">
            <span class="config-entry-data-mono">{{ apiMeta.deliveryUrl || '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="本次实际 URL" :span="2">
            <span class="config-entry-data-mono">{{ apiMeta.requestUrl || '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="HTTP / Content-Type">
            <el-tag size="small" effect="plain" :type="httpType(apiMeta.httpCode)">
              {{ apiMeta.httpCode || '-' }}
            </el-tag>
            <span style="margin-left: 8px;">{{ apiMeta.contentType || '-' }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="响应来源">{{ responseSourceLabel(apiMeta) }}</el-descriptions-item>
          <el-descriptions-item label="请求状态">
            <el-tag size="small" effect="plain" :type="requestStateType(apiRequestState)">
              {{ requestStateLabel(apiRequestState) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="解密状态">
            <el-tag size="small" effect="plain" :type="plainStateType(apiPlainState)">
              {{ plainStateLabel(apiPlainState) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="缓存 TTL / 服务端耗时">
            {{ apiMeta.dataSourceTtl || '-' }} / {{ apiMeta.dataTtl || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="本次请求耗时">{{ formatMilliseconds(apiMeta.elapsedMs) }}</el-descriptions-item>
          <el-descriptions-item label="读取时间">{{ apiMeta.checkedAt || '-' }}</el-descriptions-item>
          <el-descriptions-item label="响应原文 / 原始明文大小">
            {{ apiMeta.responseBytes ? formatBytes(apiMeta.responseBytes) : '-' }}
            / {{ apiMeta.plainBytes ? formatBytes(apiMeta.plainBytes) : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="响应展示编码">
            {{ apiMeta.responseEncoding === 'base64' ? 'Base64（原响应为二进制字节）' : 'UTF-8 原文' }}
          </el-descriptions-item>
          <el-descriptions-item label="格式化明文大小">
            {{ apiMeta.prettyBytes ? formatBytes(apiMeta.prettyBytes) : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="请求 APPID / 响应 APPID">
            {{ apiMeta.appId || '-' }} / {{ apiMeta.payloadAppId || '-' }}
            <el-tag
              v-if="apiMeta.appIdMatches !== null"
              size="small"
              effect="plain"
              :type="matchType(apiMeta.appIdMatches)"
              style="margin-left: 8px;">
              {{ apiMeta.appIdMatches ? '一致' : '不一致' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="配置版本">
            delivery={{ apiMeta.configDeliveryVersion ?? '-' }}，network={{ apiMeta.networkConfigVersion ?? '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="配置解析来源">{{ apiMeta.configResolution || '-' }}</el-descriptions-item>
          <el-descriptions-item label="顶层字段数">{{ apiMeta.fieldCount || '-' }}</el-descriptions-item>
          <el-descriptions-item label="原始响应字节 SHA-256" :span="2">
            <div class="config-entry-data-hash-row">
              <span class="config-entry-data-mono">{{ apiMeta.responseSha256 || '-' }}</span>
              <el-button v-if="apiMeta.responseSha256" type="primary" link @click="copyHash('原始响应 SHA-256', apiMeta.responseSha256)">复制</el-button>
            </div>
          </el-descriptions-item>
          <el-descriptions-item label="原始明文 SHA-256" :span="2">
            <div class="config-entry-data-hash-row">
              <span class="config-entry-data-mono">{{ apiMeta.plainSha256 || '-' }}</span>
              <el-button v-if="apiMeta.plainSha256" type="primary" link @click="copyHash('原始明文 SHA-256', apiMeta.plainSha256)">复制</el-button>
            </div>
          </el-descriptions-item>
          <el-descriptions-item label="格式化明文 SHA-256" :span="2">
            <div class="config-entry-data-hash-row">
              <span class="config-entry-data-mono">{{ apiMeta.prettySha256 || '-' }}</span>
              <el-button v-if="apiMeta.prettySha256" type="primary" link @click="copyHash('格式化明文 SHA-256', apiMeta.prettySha256)">复制</el-button>
            </div>
          </el-descriptions-item>
        </el-descriptions>

        <el-alert v-if="apiError" :title="apiError" type="error" show-icon :closable="false" style="margin-top: 14px;"></el-alert>
        <el-alert v-if="apiRequestError" :title="apiRequestError" type="warning" show-icon :closable="false" style="margin-top: 14px;"></el-alert>
        <el-alert v-if="apiIdentityError" :title="apiIdentityError" type="error" show-icon :closable="false" style="margin-top: 14px;"></el-alert>

        <div class="config-entry-data-loading" v-loading="apiLoading" element-loading-text="正在请求 API 并解密响应">
          <div class="config-entry-data-section">
            <div class="config-entry-data-heading">
              <span v-if="apiMeta.responseEncoding === 'base64'">响应原文字节（Base64 编码展示）</span>
              <span v-else>{{ isCiphertext(apiPlainState) ? '响应原文（Base64 AES 密文）' : '响应原文' }}</span>
              <el-button size="small" type="primary" :disabled="!apiResponseBody" @click="copyApiResponse">复制响应原文</el-button>
            </div>
            <pre v-if="apiResponseBody" class="config-entry-data-code">{{ apiResponseBody }}</pre>
            <el-empty v-else-if="!apiLoading && !apiError" description="本次响应没有正文"></el-empty>
          </div>

          <div class="config-entry-data-section">
            <div class="config-entry-data-heading">
              <span>解密明文</span>
              <div class="config-entry-data-actions">
                <el-radio-group v-model="apiPlainViewMode" size="small" @change="changeApiPlainView">
                  <el-radio-button label="pretty">格式化 JSON</el-radio-button>
                  <el-radio-button label="raw">原始明文</el-radio-button>
                </el-radio-group>
                <el-button size="small" type="primary" :disabled="!apiPlainText" @click="copyApiPlain">复制当前明文</el-button>
              </div>
            </div>
            <el-alert v-if="apiDecodeError" :title="apiDecodeError" type="warning" show-icon :closable="false" style="margin-top: 12px;"></el-alert>
            <pre v-if="apiPlainText" class="config-entry-data-code">{{ apiPlainText }}</pre>
            <el-empty v-else-if="!apiLoading && !apiDecodeError && !apiError" description="本次响应没有可展示的解密明文"></el-empty>
          </div>
        </div>

        <template #footer>
          <el-button @click="apiDialogVisible = false">关闭</el-button>
        </template>
      </el-dialog>
    `,
    setup(_, { expose }) {
      const bucketDialogVisible = ref(false);
      const bucketLoading = ref(false);
      const bucketError = ref('');
      const bucketCiphertext = ref('');
      const bucketPlainRaw = ref('');
      const bucketPlainPretty = ref('');
      const bucketPlainText = ref('');
      const bucketPlainViewMode = ref('pretty');
      const bucketActiveId = ref(0);
      let bucketRequestId = 0;
      const bucketMeta = reactive(createEmptyBucketMeta());

      const apiDialogVisible = ref(false);
      const apiLoading = ref(false);
      const apiError = ref('');
      const apiRequestState = ref('pending');
      const apiRequestError = ref('');
      const apiDecodeError = ref('');
      const apiIdentityError = ref('');
      const apiResponseBody = ref('');
      const apiPlainRaw = ref('');
      const apiPlainPretty = ref('');
      const apiPlainText = ref('');
      const apiPlainViewMode = ref('pretty');
      const apiPlainState = ref('pending');
      const apiActiveEntryKey = ref('');
      let apiRequestId = 0;
      const apiMeta = reactive(createEmptyApiMeta());

      function safeHttpUrl(value) {
        const url = String(value || '').trim();
        return /^https?:\/\//i.test(url) ? url : '';
      }

      function formatBytes(value) {
        const bytes = Math.max(0, Number(value) || 0);
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KiB';
        return (bytes / 1024 / 1024).toFixed(2) + ' MiB';
      }

      function matchType(value) {
        if (value === true) return 'success';
        if (value === false) return 'danger';
        return 'info';
      }

      function matchLabel(meta) {
        if (meta.appIdMatches === true) return '一致（' + (meta.payloadAppId || meta.appId || '-') + '）';
        if (meta.appIdMatches === false) return '不一致（响应 ' + (meta.payloadAppId || '-') + '）';
        return '未校验';
      }

      function bucketStateLabel(value) {
        const labels = {
          verified: '已核验', legacy_inferred: '历史推算', unknown: '证据不足',
          pending: '生成中', unresolved_placeholder: '占位符未替换', failed: '生成失败', none: '未固定桶',
        };
        return labels[String(value || '').toLowerCase()] || '证据不足';
      }

      function bucketStateType(value) {
        const state = String(value || '').toLowerCase();
        if (state === 'verified') return 'success';
        if (state === 'legacy_inferred' || state === 'pending') return 'warning';
        if (state === 'failed' || state === 'unresolved_placeholder') return 'danger';
        return 'info';
      }

      function bucketEvidenceText(meta) {
        if (meta.snapshotState === 'verified' && meta.snapshotExact === 1) {
          return 'URL 来自所选制品的不可变固定桶快照；正文是该 URL 当前实时返回的对象。';
        }
        if (meta.snapshotState === 'legacy_inferred') {
          return '旧制品的固定桶由历史任务记录推算；正文是页面当前展示 URL 实时返回的对象。';
        }
        return meta.snapshotEvidence || '固定桶证据口径以当前所选制品快照为准。';
      }

      function changeBucketPlainView(mode) {
        bucketPlainText.value = mode === 'raw' ? bucketPlainRaw.value : bucketPlainPretty.value;
      }

      async function openBucket(context) {
        const source = context && typeof context === 'object' ? context : {};
        const snapshot = source.snapshot && typeof source.snapshot === 'object' ? source.snapshot : {};
        const bucket = source.bucket && typeof source.bucket === 'object' ? source.bucket : {};
        const appId = Number(source.appId) || 0;
        const bucketId = Number(bucket.id) || 0;
        const taskId = Number(snapshot.task_id) || 0;
        const attemptNo = Math.max(0, Number(snapshot.attempt_no) || 0);
        if (appId <= 0 || bucketId <= 0 || taskId <= 0) {
          global.ElementPlus.ElMessage.warning('应用、制品或固定桶标识缺失，请刷新详情');
          return;
        }
        if (bucketLoading.value) return;

        const requestId = ++bucketRequestId;
        bucketActiveId.value = bucketId;
        bucketLoading.value = true;
        bucketError.value = '';
        bucketCiphertext.value = '';
        bucketPlainRaw.value = '';
        bucketPlainPretty.value = '';
        bucketPlainText.value = '';
        bucketPlainViewMode.value = 'pretty';
        replaceReactiveObject(bucketMeta, Object.assign(createEmptyBucketMeta(), {
          appId,
          appName: String(source.appName || ''),
          bucketId,
          bucketName: String(bucket.name || ''),
          providerLabel: String(bucket.provider_label || bucket.provider || ''),
          fileUrl: String(bucket.app_file_url || ''),
          snapshotState: String(snapshot.state || 'unknown'),
          snapshotExact: Number(snapshot.exact) || 0,
          snapshotEvidence: String(snapshot.evidence || ''),
          taskId,
          attemptNo,
        }));
        bucketDialogVisible.value = true;

        try {
          const response = await fetch('/api/index.php?module=app&method=getAppStaticBucketFileContent', {
            method: 'POST',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ app_id: appId, bucket_id: bucketId, task_id: taskId, attempt_no: attemptNo }),
          });
          const json = await response.json();
          if (json.code !== 200 || !json.data) throw new Error(json.message || '桶配置数据读取失败');
          if (requestId !== bucketRequestId || !bucketDialogVisible.value || bucketActiveId.value !== bucketId) return;

          const data = json.data || {};
          const responseSnapshot = data.snapshot || {};
          const responseBucket = data.bucket || {};
          if (Number(data.app_id) !== appId
            || Number(responseSnapshot.task_id) !== taskId
            || Number(responseSnapshot.attempt_no) !== attemptNo
            || Number(responseBucket.id) !== bucketId) {
            throw new Error('桶对象响应身份校验异常，请关闭详情后重新打开');
          }
          const object = data.object || {};
          const ciphertext = data.ciphertext || {};
          const plaintext = data.plaintext || {};
          replaceReactiveObject(bucketMeta, {
            appId,
            appName: String(data.app_name || source.appName || ''),
            bucketId,
            bucketName: String(responseBucket.name || bucket.name || ''),
            providerLabel: String(responseBucket.provider_label || responseBucket.provider || bucket.provider_label || ''),
            fileUrl: String(responseBucket.app_file_url || bucket.app_file_url || ''),
            snapshotState: String(responseSnapshot.state || snapshot.state || 'unknown'),
            snapshotExact: Number(responseSnapshot.exact ?? snapshot.exact) || 0,
            snapshotEvidence: String(responseSnapshot.evidence || snapshot.evidence || ''),
            taskId,
            attemptNo,
            updatedAt: String(object.updated_at || object.last_modified || ''),
            checkedAt: String(data.checked_at || ''),
            cipherBytes: Number(ciphertext.byte_size || object.byte_size) || 0,
            plainBytes: Number(plaintext.byte_size) || 0,
            prettyBytes: Number(plaintext.pretty_byte_size) || 0,
            cipherSha256: String(ciphertext.sha256 || object.cipher_sha256 || ''),
            plainSha256: String(plaintext.sha256 || ''),
            prettySha256: String(plaintext.pretty_sha256 || ''),
            fieldCount: Number(plaintext.field_count) || 0,
            payloadAppId: plaintext.payload_app_id ?? null,
            appIdMatches: plaintext.app_id_matches === true ? true : (plaintext.app_id_matches === false ? false : null),
            configDeliveryVersion: plaintext.config_delivery_version ?? null,
            networkConfigVersion: plaintext.network_config_version ?? null,
          });
          bucketCiphertext.value = String(ciphertext.body ?? ciphertext.text ?? object.body ?? '');
          bucketPlainRaw.value = String(plaintext.json ?? plaintext.raw_json ?? plaintext.text ?? '');
          bucketPlainPretty.value = String(plaintext.pretty_json ?? bucketPlainRaw.value);
          bucketPlainText.value = bucketPlainPretty.value || bucketPlainRaw.value;
        } catch (error) {
          if (requestId !== bucketRequestId || !bucketDialogVisible.value || bucketActiveId.value !== bucketId) return;
          bucketError.value = String((error && error.message) || '桶配置数据读取失败');
        } finally {
          if (requestId === bucketRequestId) bucketLoading.value = false;
        }
      }

      function resetBucket() {
        bucketRequestId++;
        bucketLoading.value = false;
        bucketError.value = '';
        bucketCiphertext.value = '';
        bucketPlainRaw.value = '';
        bucketPlainPretty.value = '';
        bucketPlainText.value = '';
        bucketPlainViewMode.value = 'pretty';
        bucketActiveId.value = 0;
        replaceReactiveObject(bucketMeta, createEmptyBucketMeta());
      }

      async function copyBucketCiphertext() {
        try {
          await copyText(bucketCiphertext.value);
          global.ElementPlus.ElMessage.success('加密原文已复制');
        } catch (error) {
          global.ElementPlus.ElMessage.error('复制失败，请在加密原文区域手动选择复制');
        }
      }

      async function copyBucketPlain() {
        try {
          await copyText(bucketPlainText.value);
          global.ElementPlus.ElMessage.success('解密明文已复制');
        } catch (error) {
          global.ElementPlus.ElMessage.error('复制失败，请在解密明文区域手动选择复制');
        }
      }

      /** 哈希复制统一走相同剪贴板合同，无值时按钮不渲染。 */
      async function copyHash(label, value) {
        try {
          await copyText(value);
          global.ElementPlus.ElMessage.success(label + ' 已复制');
        } catch (error) {
          global.ElementPlus.ElMessage.error('复制失败，请手动选择哈希值');
        }
      }

      function apiHeaderValue(headers, name) {
        if (!headers || typeof headers !== 'object' || Array.isArray(headers)) return '';
        const target = String(name || '').toLowerCase();
        const key = Object.keys(headers).find(function findHeader(headerName) {
          return String(headerName).toLowerCase() === target;
        });
        return key ? String(headers[key] ?? '') : '';
      }

      function httpType(value) {
        const code = Number(value) || 0;
        if (code >= 200 && code < 300) return 'success';
        if (code >= 300 && code < 500) return 'warning';
        if (code >= 500) return 'danger';
        return 'info';
      }

      function requestStateLabel(value) {
        const labels = {
          pending: '等待请求', received: '已取得响应', timeout: '请求超时',
          transport_error: '网络异常', http_error: 'HTTP 异常', empty_response: '响应为空',
          too_large: '响应过大', request_error: '请求异常',
        };
        return labels[String(value || '').toLowerCase()] || '状态未知';
      }

      function requestStateType(value) {
        const state = String(value || '').toLowerCase();
        if (state === 'received') return 'success';
        if (state === 'pending') return 'info';
        if (state === 'timeout' || state === 'http_error' || state === 'empty_response') return 'warning';
        return 'danger';
      }

      function plainStateLabel(value) {
        const labels = {
          pending: '等待解密', success: '解密成功', payload_mismatch: '解密成功，APPID 不一致',
          invalid_ciphertext: '密文或 JSON 校验异常', unavailable: '本次没有可解密正文',
        };
        return labels[String(value || '').toLowerCase()] || '状态未知';
      }

      function plainStateType(value) {
        const state = String(value || '').toLowerCase();
        if (state === 'success') return 'success';
        if (state === 'payload_mismatch' || state === 'invalid_ciphertext') return 'danger';
        if (state === 'unavailable') return 'warning';
        return 'info';
      }

      function isCiphertext(value) {
        const state = String(value || '').toLowerCase();
        return state === 'success' || state === 'payload_mismatch';
      }

      function responseSourceLabel(meta) {
        const parts = [];
        if (meta.dataSource) parts.push('配置：' + meta.dataSource);
        if (meta.appDataSource) parts.push('应用：' + meta.appDataSource);
        return parts.length ? parts.join('，') : '-';
      }

      function formatMilliseconds(value) {
        const milliseconds = Number(value);
        return Number.isFinite(milliseconds) && milliseconds >= 0 ? milliseconds.toFixed(2) + ' ms' : '-';
      }

      async function openApi(context) {
        const source = context && typeof context === 'object' ? context : {};
        const api = source.api && typeof source.api === 'object' ? source.api : {};
        const appId = Number(source.appId) || 0;
        const entryKey = String(api.entry_key || '').trim().toLowerCase();
        if (appId <= 0 || !entryKey) {
          global.ElementPlus.ElMessage.warning('应用或 API 入口标识缺失，请刷新当前列表');
          return;
        }
        if (apiLoading.value) return;

        const requestId = ++apiRequestId;
        apiActiveEntryKey.value = entryKey;
        apiLoading.value = true;
        apiError.value = '';
        apiRequestState.value = 'pending';
        apiRequestError.value = '';
        apiDecodeError.value = '';
        apiIdentityError.value = '';
        apiResponseBody.value = '';
        apiPlainRaw.value = '';
        apiPlainPretty.value = '';
        apiPlainText.value = '';
        apiPlainViewMode.value = 'pretty';
        apiPlainState.value = 'pending';
        replaceReactiveObject(apiMeta, Object.assign(createEmptyApiMeta(), {
          appId,
          appName: String(source.appName || ''),
          apiId: Number(api.id) || 0,
          apiName: String(api.name || ''),
          entryKey,
          deliveryUrl: String(api.delivery_url || ''),
        }));
        apiDialogVisible.value = true;

        try {
          const response = await fetch('/api/index.php?module=app&method=getAppApiConfigPayload', {
            method: 'POST',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ app_id: appId, entry_key: entryKey }),
          });
          const json = await response.json();
          if (json.code !== 200 || !json.data) throw new Error(json.message || 'API 节点数据读取失败');
          if (requestId !== apiRequestId || !apiDialogVisible.value || apiActiveEntryKey.value !== entryKey) return;

          const data = json.data || {};
          const responseApi = data.api || data.endpoint || {};
          const responseAppId = Number(data.app_id) || 0;
          const responseEntryKey = String(responseApi.entry_key || '').trim().toLowerCase();
          if (responseAppId !== appId || responseEntryKey !== entryKey) {
            throw new Error('API 节点响应身份校验异常，请刷新当前列表后重试');
          }
          const ciphertext = data.ciphertext || {};
          const responseData = data.response || ciphertext || {};
          const plaintext = data.plaintext || {};
          const headers = responseData.headers || data.headers || {};
          const responseBody = String(responseData.body ?? responseData.raw_body ?? ciphertext.body ?? ciphertext.text ?? '');
          const rawPlaintext = String(plaintext.json ?? plaintext.raw_json ?? plaintext.text ?? '');
          const prettyPlaintext = String(plaintext.pretty_json ?? rawPlaintext);
          const payloadAppId = plaintext.payload_app_id === null || plaintext.payload_app_id === undefined
            ? (data.payload_app_id === null || data.payload_app_id === undefined ? null : String(data.payload_app_id))
            : String(plaintext.payload_app_id);
          let appIdMatches = plaintext.app_id_matches === true ? true : (plaintext.app_id_matches === false ? false : null);
          if (appIdMatches === null && payloadAppId !== null) appIdMatches = String(payloadAppId) === String(appId);
          const declaredSource = typeof responseData.source === 'string' ? responseData.source : '';
          const plainState = String(plaintext.state || ((rawPlaintext || prettyPlaintext) ? 'success' : 'unavailable')).toLowerCase();
          const requestState = String(responseData.state || data.state || 'received').toLowerCase();
          const requestError = String(responseData.error || (plainState === 'unavailable' ? (plaintext.error || '') : '') || '');
          // 后端已对 APPID 不一致的正文做裁剪；组件再按身份状态拦一次，
          // 避免旧服务节点或异常响应把其他 APPID 的密文、明文渲染到当前页面。
          const responseIdentityAccepted = plainState !== 'payload_mismatch' && appIdMatches !== false;

          replaceReactiveObject(apiMeta, {
            appId,
            appName: String(data.app_name || source.appName || ''),
            apiId: Number(responseApi.id || api.id) || 0,
            apiName: String(responseApi.name || api.name || ''),
            entryKey,
            deliveryUrl: String(responseApi.delivery_url || api.delivery_url || ''),
            requestUrl: String(responseApi.request_url || responseData.request_url || responseData.url || ''),
            httpCode: Number(responseData.http_code || responseData.status_code) || 0,
            contentType: String(responseData.content_type || apiHeaderValue(headers, 'content-type') || ''),
            responseEncoding: String(responseData.body_encoding || ciphertext.encoding || 'utf-8').toLowerCase(),
            dataSource: String(responseData.data_source || declaredSource || apiHeaderValue(headers, 'x-data-source') || ''),
            appDataSource: String(responseData.app_data_source || apiHeaderValue(headers, 'x-data-source-apk') || ''),
            dataSourceTtl: String(responseData.data_source_ttl ?? apiHeaderValue(headers, 'x-data-source-ttl') ?? ''),
            dataTtl: String(responseData.data_ttl ?? apiHeaderValue(headers, 'x-data-ttl') ?? ''),
            configResolution: String(responseData.config_resolution || apiHeaderValue(headers, 'x-config-resolution') || ''),
            elapsedMs: responseData.elapsed_ms ?? data.elapsed_ms ?? null,
            checkedAt: String(data.checked_at || ''),
            responseBytes: Number(responseData.byte_size || ciphertext.byte_size || 0) || 0,
            plainBytes: Number(plaintext.byte_size) || 0,
            prettyBytes: Number(plaintext.pretty_byte_size) || 0,
            responseSha256: String(responseData.sha256 || responseData.cipher_sha256 || ciphertext.sha256 || ''),
            plainSha256: String(plaintext.sha256 || ''),
            prettySha256: String(plaintext.pretty_sha256 || ''),
            fieldCount: Number(plaintext.field_count) || 0,
            payloadAppId,
            appIdMatches,
            configDeliveryVersion: plaintext.config_delivery_version ?? null,
            networkConfigVersion: plaintext.network_config_version ?? null,
          });
          apiResponseBody.value = responseIdentityAccepted ? responseBody : '';
          apiPlainRaw.value = responseIdentityAccepted ? rawPlaintext : '';
          apiPlainPretty.value = responseIdentityAccepted ? prettyPlaintext : '';
          apiPlainText.value = responseIdentityAccepted ? (prettyPlaintext || rawPlaintext) : '';
          apiRequestState.value = requestState;
          apiRequestError.value = requestError;
          apiPlainState.value = plainState;
          apiIdentityError.value = plainState === 'payload_mismatch'
            ? String(plaintext.error || '响应已解密，但 APPID 与请求应用不一致')
            : '';
          apiDecodeError.value = plainState === 'invalid_ciphertext'
            ? String(plaintext.decode_error || plaintext.error || data.decode_error || '响应原文未通过配置解密或 JSON 校验')
            : '';
        } catch (error) {
          if (requestId !== apiRequestId || !apiDialogVisible.value || apiActiveEntryKey.value !== entryKey) return;
          apiError.value = String((error && error.message) || 'API 节点数据读取失败');
          apiRequestState.value = 'request_error';
          apiPlainState.value = 'unavailable';
        } finally {
          if (requestId === apiRequestId) apiLoading.value = false;
        }
      }

      function resetApi() {
        apiRequestId++;
        apiLoading.value = false;
        apiError.value = '';
        apiRequestState.value = 'pending';
        apiRequestError.value = '';
        apiDecodeError.value = '';
        apiIdentityError.value = '';
        apiResponseBody.value = '';
        apiPlainRaw.value = '';
        apiPlainPretty.value = '';
        apiPlainText.value = '';
        apiPlainViewMode.value = 'pretty';
        apiPlainState.value = 'pending';
        apiActiveEntryKey.value = '';
        replaceReactiveObject(apiMeta, createEmptyApiMeta());
      }

      function changeApiPlainView(mode) {
        apiPlainText.value = mode === 'raw' ? apiPlainRaw.value : apiPlainPretty.value;
      }

      async function copyApiResponse() {
        try {
          await copyText(apiResponseBody.value);
          global.ElementPlus.ElMessage.success(apiMeta.responseEncoding === 'base64'
            ? '响应原文字节的 Base64 展示已复制'
            : '响应原文已复制');
        } catch (error) {
          global.ElementPlus.ElMessage.error('复制失败，请在响应原文区域手动选择复制');
        }
      }

      async function copyApiPlain() {
        try {
          await copyText(apiPlainText.value);
          global.ElementPlus.ElMessage.success('解密明文已复制');
        } catch (error) {
          global.ElementPlus.ElMessage.error('复制失败，请在解密明文区域手动选择复制');
        }
      }

      function closeAll() {
        bucketDialogVisible.value = false;
        apiDialogVisible.value = false;
        resetBucket();
        resetApi();
      }

      function isBucketLoading(bucketId) {
        return bucketLoading.value && bucketActiveId.value === Number(bucketId);
      }

      function isApiLoading(entryKey) {
        return apiLoading.value && apiActiveEntryKey.value === String(entryKey || '').trim().toLowerCase();
      }

      expose({
        openBucket,
        openApi,
        closeAll,
        isBucketLoading,
        isApiLoading,
        isBucketBusy: function isBucketBusy() { return bucketLoading.value; },
        isApiBusy: function isApiBusy() { return apiLoading.value; },
      });

      return {
        bucketDialogVisible, bucketLoading, bucketError, bucketCiphertext, bucketPlainRaw,
        bucketPlainPretty, bucketPlainText, bucketPlainViewMode, bucketMeta, resetBucket,
        changeBucketPlainView, copyBucketCiphertext, copyBucketPlain, copyHash,
        bucketEvidenceText, bucketStateLabel, bucketStateType,
        apiDialogVisible, apiLoading, apiError, apiRequestState, apiRequestError, apiDecodeError,
        apiIdentityError, apiResponseBody, apiPlainText, apiPlainViewMode, apiPlainState, apiMeta,
        resetApi, changeApiPlainView, copyApiResponse, copyApiPlain, httpType, requestStateLabel,
        requestStateType, plainStateLabel, plainStateType, isCiphertext, responseSourceLabel,
        formatMilliseconds, formatBytes, matchType, matchLabel, safeHttpUrl,
      };
    },
  };

  global.YunzhuruConfigEntryDataViewer = component;
}(window));
