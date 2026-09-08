/**
 * 配置同步任务浏览器：统一管理两页的任务分页、历史快照与详情切换。
 * 当前任务状态仍由页面原轮询维护；历史详情使用独立副本，列表刷新保留当前页码。
 */
(function (root) {
  'use strict';

  /** 深拷贝接口快照，隔离后续当前任务结果数组的更新。 */
  function copy(value) {
    return JSON.parse(JSON.stringify(value || {}));
  }

  /** 创建单个同步中心的浏览状态；请求代次负责丢弃分页和快速切换产生的旧响应。 */
  function create(options) {
    const defaults = copy(options.current);
    const detail = options.Vue.reactive(copy(defaults));
    const state = options.Vue.reactive({
      view: 'list', items: [], total: 0, page: 1, pageSize: 10,
      loading: false, error: '', selectedJobId: '', currentJobId: '',
      detailLoading: false, detailReady: false, detailError: '', isCurrent: false
    });
    let listGeneration = 0;
    let detailGeneration = 0;
    let currentRevision = 0;
    let lastListRequestAt = 0;
    let lastObservedJobId = String(options.current.job_id || '');
    let disposed = false;

    /** 替换整份详情，缺失字段恢复默认值，避免跨任务残留成功数、失败项或时间。 */
    function replaceDetail(snapshot) {
      Object.keys(detail).forEach(key => { delete detail[key]; });
      Object.assign(detail, copy(defaults), copy(snapshot));
      if (snapshot.current === undefined && snapshot.current_index !== undefined) detail.current = Number(snapshot.current_index) || 0;
      if (snapshot.expected_total === undefined && snapshot.expected_document_total !== undefined) detail.expected_total = Number(snapshot.expected_document_total) || 0;
    }

    /** 同页刷新保留分页；主动翻页清空旧行，错误时保留可重试入口。 */
    async function loadList(page, silent) {
      if (disposed) return;
      const requestedPage = Math.max(1, Number(page || state.page) || 1);
      const generation = ++listGeneration;
      if (requestedPage !== state.page) state.items = [];
      state.page = requestedPage;
      state.loading = true;
      if (!silent) state.error = '';
      lastListRequestAt = Date.now();
      try {
        const json = await options.api('getConfigSyncTasks', { page: requestedPage, page_size: state.pageSize });
        if (disposed || generation !== listGeneration) return;
        if (Number(json.code) !== 200) throw new Error(json.message || '读取任务列表失败');
        const data = json.data || {};
        state.items = Array.isArray(data.items) ? data.items : [];
        state.total = Math.max(0, Number(data.total) || 0);
        state.page = Math.max(1, Number(data.page) || requestedPage);
        state.currentJobId = String(data.current_job_id || options.current.job_id || '');
        state.error = '';
        // 历史保留期缩短时页数可能减少，只跳到最后一个有效页。
        const lastPage = Math.max(1, Math.ceil(state.total / state.pageSize));
        if (state.page > lastPage) return loadList(lastPage, false);
      } catch (error) {
        if (!disposed && generation === listGeneration) state.error = error.message || '读取任务列表失败';
      } finally {
        if (!disposed && generation === listGeneration) state.loading = false;
      }
    }

    /** 按 job_id 读取独立历史详情，快速换行或返回列表后忽略迟到响应。 */
    async function selectTask(jobId) {
      const selected = String(jobId || '').trim();
      if (!selected || disposed) return;
      const generation = ++detailGeneration;
      const requestedCurrentRevision = currentRevision;
      state.view = 'detail';
      state.selectedJobId = selected;
      state.detailLoading = true;
      state.detailReady = false;
      state.detailError = '';
      state.isCurrent = false;
      options.resetFilters();
      try {
        const json = await options.api('getConfigSyncTaskDetail', { job_id: selected });
        if (disposed || generation !== detailGeneration || state.selectedJobId !== selected) return;
        if (Number(json.code) !== 200) throw new Error(json.message || '读取任务详情失败');
        const snapshot = json.data || {};
        if (String(snapshot.job_id || '') !== selected) throw new Error('任务详情与所选任务不一致，请重试');
        const responseIsCurrent = snapshot.is_current === true || Number(snapshot.is_current) === 1;
        // 详情查询期间若实时轮询已经发现新任务，旧响应的 current 标记也已过期。
        if (responseIsCurrent && currentRevision !== requestedCurrentRevision
          && String(options.current.job_id || '') !== selected) return selectTask(selected);
        replaceDetail(snapshot);
        state.isCurrent = responseIsCurrent;
        if (state.isCurrent) state.currentJobId = selected;
        if (state.isCurrent && currentRevision !== requestedCurrentRevision
          && String(options.current.job_id || '') === selected) replaceDetail(options.current);
        state.detailReady = true;
      } catch (error) {
        if (!disposed && generation === detailGeneration) state.detailError = error.message || '读取任务详情失败';
      } finally {
        if (!disposed && generation === detailGeneration) state.detailLoading = false;
      }
    }

    /** 当前快照只刷新同一个选中任务；新任务出现后补读旧任务的最终历史，不切换选择。 */
    function observeCurrent() {
      if (disposed) return;
      currentRevision += 1;
      const currentJobId = String(options.current.job_id || '');
      const changedJob = currentJobId !== lastObservedJobId;
      lastObservedJobId = currentJobId;
      state.currentJobId = currentJobId;
      if (state.view === 'detail' && state.detailReady && !state.detailLoading) {
        if (state.selectedJobId === currentJobId && state.isCurrent) {
          replaceDetail(options.current);
        } else if (state.isCurrent && state.selectedJobId !== currentJobId) {
          state.isCurrent = false;
          selectTask(state.selectedJobId);
        }
      }
      // 当前页只就地替换已有任务摘要，不插入头部导致每次轮询挤动分页。
      const summaryFields = ['job_id', 'status', 'phase', 'phase_label', 'message', 'reasons',
        'started_at', 'updated_at', 'finished_at', 'total', 'expected_total', 'current',
        'current_index', 'processed', 'success', 'fail', 'app_partial_count'];
      const summary = {};
      summaryFields.forEach(key => {
        if (options.current[key] !== undefined) summary[key] = options.current[key];
      });
      state.items = state.items.map(item => String(item.job_id || '') === currentJobId
        ? Object.assign({}, item, copy(summary)) : item);
      if (options.isVisible() && state.view === 'list' && !state.loading
        && (changedJob || Date.now() - lastListRequestAt >= 5000)) loadList(state.page, true);
    }

    /** 返回列表会让未完成的详情请求失效，页码继续保留。 */
    function showList() {
      detailGeneration += 1;
      state.view = 'list';
      state.detailLoading = false;
      state.detailError = '';
      return loadList(state.page, false);
    }

    /** 手工操作完成后转到接口确认的当前任务，旧任务本身始终只读。 */
    function showCurrent() {
      const jobId = String(options.current.job_id || state.currentJobId || '');
      return jobId ? selectTask(jobId) : showList();
    }

    /** 重试同时核对服务端 current 标记和本地实时任务编号，避免对旧快照提交写操作。 */
    function canRetry() {
      return state.view === 'detail' && state.detailReady && !state.detailLoading && state.isCurrent
        && state.selectedJobId === String(options.current.job_id || '');
    }

    function dispose() {
      disposed = true;
      listGeneration += 1;
      detailGeneration += 1;
    }

    return { state, detail, loadList, selectTask, showList, showCurrent, observeCurrent, canRetry, dispose };
  }

  const statusLabels = {
    queued: '排队中', running: '同步中', completed: '已完成', partial_failure: '部分失败',
    partial: '部分成功', partial_success: '部分成功', failed: '同步失败', idle: '待命',
    superseded: '已被新任务替代', abandoned: '已中断'
  };

  /** 两页共享列表与导航；既有完整详情通过插槽复用，避免产生第三套结果展开实现。 */
  const component = {
    props: { controller: { type: Object, required: true } },
    methods: {
      statusLabel(item) { return statusLabels[String(item.status || 'idle')] || item.phase_label || '已记录'; },
      statusType(item) {
        if (item.status === 'completed') return 'success';
        if (['failed', 'partial_failure', 'abandoned'].includes(item.status)) return 'danger';
        if (['queued', 'running', 'partial', 'partial_success'].includes(item.status)) return 'warning';
        return 'info';
      },
      taskTime(item) { return item.created_at || item.started_at || item.updated_at || '时间未记录'; },
      taskReason(item) {
        return Array.isArray(item.reasons) && item.reasons.length ? item.reasons.join('；') : '未记录触发原因';
      },
      taskProgress(item) {
        const total = Number(item.total || item.expected_total || item.expected_document_total || 0);
        const current = Number(item.current || item.current_index || item.processed || 0);
        return total > 0 ? '应用 ' + current + ' / ' + total : (item.phase_label || this.statusLabel(item));
      }
    },
    template: `
      <div class="config-sync-task-browser">
        <template v-if="controller.state.view === 'list'">
          <div class="config-sync-task-browser__toolbar">
            <span>共 {{ controller.state.total }} 次同步 · 最新在前</span>
            <el-button text class="config-sync-task-browser__action" :loading="controller.state.loading" @click="controller.loadList(controller.state.page, false)">刷新列表</el-button>
          </div>
          <div v-if="controller.state.error" class="config-sync-task-browser__error" role="alert">
            {{ controller.state.error }}
            <el-button text class="config-sync-task-browser__action" @click="controller.loadList(controller.state.page, false)">重试</el-button>
          </div>
          <div v-if="controller.state.loading && !controller.state.items.length" class="config-sync-task-browser__empty" role="status">正在加载任务列表…</div>
          <div v-else-if="!controller.state.items.length && !controller.state.error" class="config-sync-task-browser__empty">暂无同步任务。配置更新或手工同步后将在这里记录。</div>
          <div class="config-sync-task-browser__list" data-config-sync-task-list :aria-busy="controller.state.loading">
            <button v-for="item in controller.state.items" :key="item.job_id" :data-config-sync-task-job="item.job_id" type="button" class="config-sync-task-browser__card" @click="controller.selectTask(item.job_id)">
              <span class="config-sync-task-browser__card-heading">
                <strong>{{ taskTime(item) }}</strong>
                <el-tag size="small" effect="light" :type="statusType(item)">{{ statusLabel(item) }}</el-tag>
              </span>
              <span class="config-sync-task-browser__reason">{{ taskReason(item) }}</span>
              <span class="config-sync-task-browser__message">{{ item.message || item.phase_label || statusLabel(item) }}</span>
              <span class="config-sync-task-browser__card-footer">
                <span>{{ taskProgress(item) }}<span v-if="String(item.job_id) === controller.state.currentJobId"> · 当前任务</span></span>
                <span class="config-sync-task-browser__link">查看详情 ›</span>
              </span>
            </button>
          </div>
          <el-pagination v-if="controller.state.total > controller.state.pageSize" class="config-sync-task-browser__pagination" background small layout="prev, pager, next" :pager-count="5" :current-page="controller.state.page" :page-size="controller.state.pageSize" :total="controller.state.total" @current-change="controller.loadList($event, false)"></el-pagination>
        </template>
        <template v-else>
          <div class="config-sync-task-browser__toolbar">
            <el-button text class="config-sync-task-browser__action" data-config-sync-task-back @click="controller.showList()">‹ 返回任务列表</el-button>
            <span>{{ controller.state.detailReady ? (controller.state.isCurrent ? '当前任务 · 实时更新' : '历史任务 · 只读快照') : '任务详情' }}</span>
          </div>
          <div v-if="controller.state.detailLoading" class="config-sync-task-browser__empty" role="status">正在加载任务详情…</div>
          <div v-else-if="controller.state.detailError" class="config-sync-task-browser__error" role="alert">
            {{ controller.state.detailError }}
            <el-button text class="config-sync-task-browser__action" @click="controller.selectTask(controller.state.selectedJobId)">重试</el-button>
          </div>
          <slot v-else-if="controller.state.detailReady"></slot>
        </template>
      </div>`
  };

  root.ConfigSyncTaskHistory = { create, component };
})(typeof window !== 'undefined' ? window : globalThis);
