(function () {
  const ACTION_OPTIONS = [
    { value: 1, label: '打开链接' },
    { value: 2, label: '添加QQ群' },
    { value: 4, label: '分享内容' },
    { value: 5, label: '提交输入内容' },
    { value: 6, label: '复制内容' },
    { value: 7, label: '打开窗口类' }
  ];

  const ACTION_LABELS = ACTION_OPTIONS.reduce((map, item) => {
    map[item.value] = item.label;
    return map;
  }, {
    0: '无动作',
    3: '退出APP'
  });

  const PARAM_LABELS = {
    1: '打开链接',
    2: 'QQ群Key',
    4: '分享内容',
    5: '提交接口',
    6: '复制内容',
    7: '窗口类名'
  };

  const PARAM_PLACEHOLDERS = {
    1: '请输入要打开的网址，可一行一个',
    2: '请输入QQ群加群Key',
    4: '请输入要分享的文本内容',
    5: '请输入提交接口地址，例如：https://api.yunzhuru.com/kami/',
    6: '请输入要复制的文本内容',
    7: '请输入完整窗口类名，例如 com.abc.shell.MainActivity'
  };

  function toAction(value) {
    return Number(value || 0);
  }

  function hasParam(actionType) {
    return ![0, 3].includes(toAction(actionType));
  }

  function paramLabel(actionType) {
    return PARAM_LABELS[toAction(actionType)] || '事件参数';
  }

  function paramPlaceholder(actionType) {
    return PARAM_PLACEHOLDERS[toAction(actionType)] || '无参数';
  }

  function paramInputType(actionType) {
    return [1, 4, 5, 6].includes(toAction(actionType)) ? 'textarea' : 'input';
  }

  function actionLabel(actionType) {
    return ACTION_LABELS[toAction(actionType)] || '未知事件';
  }

  function ensureStyles() {
    if (typeof document === 'undefined' || document.getElementById('click-param-asset-style')) {
      return;
    }
    const style = document.createElement('style');
    style.id = 'click-param-asset-style';
    style.textContent = `
      .param-option {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        width: 100%;
      }
      .param-option-name {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
      }
      .param-option-text {
        color: #909399;
        font-size: 12px;
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .param-toolbar {
        display: flex;
        gap: 8px;
        margin-top: 8px;
        flex-wrap: wrap;
      }
      .param-preview-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 8px;
      }
      .param-preview-item {
        width: 190px;
        min-height: 74px;
        border: 1px solid #ebeef5;
        border-radius: 6px;
        padding: 8px;
        background: #fafafa;
      }
      .param-preview-item.is-disabled {
        border-color: #dcdfe6;
        background: #f5f7fa;
      }
      .param-preview-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
      }
      .param-preview-tags {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex-shrink: 0;
      }
      .param-preview-name {
        color: #303133;
        font-size: 13px;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .param-preview-text {
        color: #606266;
        font-size: 12px;
        line-height: 1.45;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-all;
      }
    `;
    document.head.appendChild(style);
  }

  function isEnabled(asset) {
    return Number(asset && asset.enabled !== undefined ? asset.enabled : 1) === 1;
  }

  function optionLabel(asset) {
    if (!asset) {
      return '';
    }
    const name = asset.name || '';
    return isEnabled(asset) ? name : `${name}（停用）`;
  }

  function toIdList(value) {
    const values = Array.isArray(value) ? value : [value];
    return values
      .map(item => Number(item || 0))
      .filter(item => item > 0);
  }

  function firstId(value) {
    const ids = toIdList(value);
    return ids.length > 0 ? ids[0] : 0;
  }

  function idsOrFallback(ids, fallback) {
    const normalized = toIdList(ids);
    return normalized.length > 0 ? normalized : toIdList(fallback);
  }

  function submitFields(assetIds) {
    const ids = toIdList(assetIds);
    const first = ids.length > 0 ? ids[0] : null;
    return {
      // 保存时同时覆盖新旧字段，避免编辑弹窗里残留的后端旧字段覆盖当前多选值。
      clickParamAssetId: first,
      clickParamAssetIds: ids,
      click_param_asset_id: first || 0,
      click_param_asset_ids: ids
    };
  }

  function normalizeParamText(value) {
    return String(value || '')
      .split(/\r\n|\r|\n/)
      .map(item => item.trim())
      .filter(Boolean)
      .join('\n');
  }

  function firstKeyword(value) {
    const lines = normalizeParamText(value).split('\n').filter(Boolean);
    return lines[0] || '';
  }

  function multipleLimit(actionType) {
    return toAction(actionType) === 1 ? 0 : 1;
  }

  function normalizeSort(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
      return 0;
    }
    return Math.max(-10000, Math.min(10000, Math.trunc(number)));
  }

  /**
   * 资源选择器与管理列表共用同一排序合同：数值越大越靠前，ID 作稳定兜底。
   */
  function compareBySort(left, right) {
    const sortDiff = normalizeSort(right && right.sort) - normalizeSort(left && left.sort);
    if (sortDiff !== 0) {
      return sortDiff;
    }
    return Number((right && right.id) || 0) - Number((left && left.id) || 0);
  }

  function createStore(Vue, ElementPlus) {
    ensureStyles();

    const list = Vue.ref([]);
    const page = Vue.ref(1);
    const limit = Vue.ref(20);
    const total = Vue.ref(0);
    const keyword = Vue.ref('');
    const loading = Vue.ref(false);
    const knownList = Vue.ref([]);
    // 服务端已关联资源在当前页面生命周期内长期保留，供多个弹窗目标共同回显。
    const mergeRetainedIds = new Set();
    // 表单当前选择按目标分别计数，取消或改选时可以精确释放旧资源缓存。
    const selectedIdsByTarget = new WeakMap();
    const selectedRetainCounts = new Map();
    // 恢复历史文本可能发起异步查询，代次用于拦截对话框关闭后的迟到回显。
    const selectionSequences = new WeakMap();
    const dialogVisible = Vue.ref(false);
    const editVisible = Vue.ref(false);
    const editMode = Vue.ref(false);
    const lastActionType = Vue.ref(null);
    const sortSavingId = Vue.ref(0);
    let loadSequence = 0;
    // 编辑基线只用于判断用户是否真实改动排序，不作为表单字段传给后端。
    let formSortBaseline = 0;
    const form = Vue.reactive({
      id: null,
      name: '',
      action_type: 1,
      param_text: '',
      enabled: true,
      sort: 0,
      remark: ''
    });

    function cacheRows(assets) {
      const rows = Array.isArray(assets) ? assets : [assets];
      const existed = new Map(knownList.value.map(item => [Number(item.id), item]));
      let changed = false;
      rows.forEach(item => {
        if (item && item.id) {
          existed.set(Number(item.id), item);
          changed = true;
        }
      });
      if (changed) {
        knownList.value = Array.from(existed.values());
      }
    }

    function retainMergedIds(values) {
      toIdList(values).forEach(id => mergeRetainedIds.add(id));
    }

    /**
     * 更新单个表单目标正在使用的资源集合，并维护跨目标引用计数。
     */
    function trackSelection(target, values) {
      if (!target || (typeof target !== 'object' && typeof target !== 'function')) {
        return;
      }

      const previousIds = selectedIdsByTarget.get(target) || new Set();
      const nextIds = new Set(toIdList(values));
      previousIds.forEach(id => {
        if (nextIds.has(id)) {
          return;
        }
        const nextCount = Number(selectedRetainCounts.get(id) || 0) - 1;
        if (nextCount > 0) {
          selectedRetainCounts.set(id, nextCount);
        } else {
          selectedRetainCounts.delete(id);
        }
      });
      nextIds.forEach(id => {
        if (!previousIds.has(id)) {
          selectedRetainCounts.set(id, Number(selectedRetainCounts.get(id) || 0) + 1);
        }
      });
      selectedIdsByTarget.set(target, nextIds);
    }

    function isRetained(id) {
      return mergeRetainedIds.has(id) || Number(selectedRetainCounts.get(id) || 0) > 0;
    }

    function nextSelectionSequence(target) {
      const nextSequence = Number(selectionSequences.get(target) || 0) + 1;
      selectionSequences.set(target, nextSequence);
      return nextSequence;
    }

    function isCurrentSelectionSequence(target, sequence) {
      return Number(selectionSequences.get(target) || 0) === sequence;
    }

    /**
     * 释放指定表单目标的当前选择；字段清空由页面自己的表单合同负责。
     */
    function clearSelection(target) {
      nextSelectionSequence(target);
      trackSelection(target, []);
    }

    /**
     * 保留服务端返回的已关联资源，确保分页刷新不会丢失标签和预览。
     */
    function retainRows(assets) {
      const rows = Array.isArray(assets) ? assets : [assets];
      retainMergedIds(rows.map(item => Number(item && item.id)));
      cacheRows(rows);
    }

    function allKnownRows() {
      const existed = new Map();
      knownList.value.forEach(item => {
        if (item && item.id) {
          existed.set(Number(item.id), item);
        }
      });
      list.value.forEach(item => {
        if (item && item.id) {
          existed.set(Number(item.id), item);
        }
      });
      return Array.from(existed.values());
    }

    /**
     * 分页刷新时丢弃同分类的旧页缓存，仅保留已关联资源，避免旧排序值污染选项顺序。
     */
    function pruneLoadedCache(actionType) {
      const hasActionFilter = actionType !== null && actionType !== undefined && actionType !== '';
      const type = hasActionFilter ? toAction(actionType) : null;
      knownList.value = knownList.value.filter(item => {
        const id = Number(item && item.id);
        if (isRetained(id)) {
          return true;
        }
        return hasActionFilter && Number(item && item.action_type) !== type;
      });
    }

    function options(actionType) {
      const type = toAction(actionType);
      return allKnownRows()
        .filter(item => Number(item.action_type) === type)
        .sort(compareBySort);
    }

    function merge(assets) {
      const rows = Array.isArray(assets) ? assets : [assets];
      retainRows(rows);
      const existed = new Set(list.value.map(item => Number(item.id)));
      rows.forEach(item => {
        if (item && item.id && !existed.has(Number(item.id))) {
          list.value.push(item);
          existed.add(Number(item.id));
        }
      });
    }

    function selected(value) {
      const ids = toIdList(value);
      if (ids.length === 0) {
        return [];
      }
      const rows = allKnownRows();
      return ids
        .map(id => rows.find(item => Number(item.id) === id))
        .filter(Boolean);
    }

    function inferIdsByText(actionType, text) {
      const normalizedText = normalizeParamText(text);
      if (!normalizedText) {
        return [];
      }
      const textLines = new Set(normalizedText.split('\n').filter(Boolean));
      return options(actionType)
        .filter(item => {
          const itemText = normalizeParamText(item.param_text);
          if (!itemText) {
            return false;
          }
          if (itemText === normalizedText) {
            return true;
          }
          if (toAction(actionType) !== 1) {
            return false;
          }
          const itemLines = itemText.split('\n').filter(Boolean);
          return itemLines.length > 0 && itemLines.every(line => textLines.has(line));
        })
        .map(item => Number(item.id))
        .filter(id => id > 0);
    }

    async function load(actionType = null, p = page.value) {
      const targetPage = typeof p === 'number' ? (p || 1) : (page.value || 1);
      const requestActionType = actionType === null || actionType === undefined || actionType === ''
        ? null
        : toAction(actionType);
      const requestKeyword = keyword.value;
      const requestSequence = ++loadSequence;
      page.value = targetPage;
      lastActionType.value = requestActionType;
      loading.value = true;
      try {
        const payload = {
          page: targetPage,
          limit: limit.value,
          keyword: requestKeyword
        };
        if (requestActionType !== null) {
          payload.action_type = requestActionType;
        }
        const res = await fetch('/api/index.php?module=click_param_asset&method=getList', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (requestSequence !== loadSequence) {
          return false;
        }
        if (json.code === 200) {
          const loadedRows = json.data.list || [];
          pruneLoadedCache(requestActionType);
          list.value = loadedRows;
          cacheRows(list.value);
          total.value = json.data.total || 0;
        } else {
          ElementPlus.ElMessage.error(json.message || '事件参数资源加载失败');
        }
        return json.code === 200;
      } finally {
        if (requestSequence === loadSequence) {
          loading.value = false;
        }
      }
    }

    function onSelectVisible(visible, actionType) {
      if (visible) {
        load(actionType, 1);
      }
    }

    function openDialog() {
      dialogVisible.value = true;
      load(null, 1);
    }

    function openEdit(row = null, actionType = 1) {
      if (row) {
        const currentSort = normalizeSort(row.sort);
        editMode.value = true;
        Object.assign(form, {
          id: row.id,
          name: row.name || '',
          action_type: Number(row.action_type || 1),
          param_text: row.param_text || '',
          enabled: Number(row.enabled ?? 1) === 1,
          sort: currentSort,
          remark: row.remark || ''
        });
        formSortBaseline = currentSort;
      } else {
        editMode.value = false;
        Object.assign(form, {
          id: null,
          name: '',
          action_type: hasParam(actionType) ? toAction(actionType) : 1,
          param_text: '',
          enabled: true,
          sort: 0,
          remark: ''
        });
        formSortBaseline = 0;
      }
      editVisible.value = true;
    }

    async function submit() {
      const method = editMode.value ? 'editAsset' : 'addAsset';
      const payload = {
        ...form,
        sort: normalizeSort(form.sort)
      };
      form.sort = payload.sort;
      // 未改排序时不传旧副本，保留弹窗打开后可能由列表行内或其他窗口更新的值。
      if (editMode.value && payload.sort === formSortBaseline) {
        delete payload.sort;
      }
      const res = await fetch(`/api/index.php?module=click_param_asset&method=${method}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (json.code === 200) {
        ElementPlus.ElMessage.success(json.message || '保存成功');
        editVisible.value = false;
        await load(lastActionType.value, page.value);
        return json.data || {};
      }
      ElementPlus.ElMessage.error(json.message || '保存失败');
      return null;
    }

    async function toggleEnabled(row) {
      const nextEnabled = Number(row.enabled || 0) === 1 ? 0 : 1;
      const oldEnabled = Number(row.enabled || 0);
      row.enabled = nextEnabled;
      const res = await fetch('/api/index.php?module=click_param_asset&method=setEnabled', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: row.id, enabled: nextEnabled })
      });
      const json = await res.json();
      if (json.code === 200) {
        ElementPlus.ElMessage.success(json.message || (nextEnabled === 1 ? '已启用' : '已停用'));
        cacheRows(row);
        return true;
      }
      row.enabled = oldEnabled;
      ElementPlus.ElMessage.error(json.message || '状态更新失败');
      return false;
    }

    /**
     * 保存单条资源的行内排序值，成功后重读当前分页以反映全局顺序。
     */
    async function setSort(row, value) {
      const id = Number(row && row.id);
      if (!Number.isInteger(id) || id <= 0 || sortSavingId.value !== 0) {
        return false;
      }

      const oldSort = normalizeSort(row.sort);
      const nextSort = normalizeSort(value);
      row.sort = nextSort;
      if (oldSort === nextSort) {
        return true;
      }

      sortSavingId.value = id;
      try {
        const res = await fetch('/api/index.php?module=click_param_asset&method=setSort', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, sort: nextSort })
        });
        const json = await res.json();
        if (json.code !== 200) {
          row.sort = oldSort;
          ElementPlus.ElMessage.error(json.message || '排序更新失败');
          return false;
        }

        row.sort = normalizeSort(json.data && json.data.sort !== undefined ? json.data.sort : nextSort);
        cacheRows(row);
        try {
          await load(lastActionType.value, page.value);
        } catch (error) {
          ElementPlus.ElMessage.warning('排序已保存，列表刷新失败，请手动刷新');
          return true;
        }
        ElementPlus.ElMessage.success(json.message || '排序已更新');
        return true;
      } catch (error) {
        row.sort = oldSort;
        ElementPlus.ElMessage.error('排序更新失败');
        return false;
      } finally {
        sortSavingId.value = 0;
      }
    }

    async function remove(row) {
      await ElementPlus.ElMessageBox.confirm(`确认删除事件参数资源：${row.name}?`, '警告', { type: 'warning' });
      const res = await fetch('/api/index.php?module=click_param_asset&method=deleteAsset', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: row.id })
      });
      const json = await res.json();
      if (json.code === 200) {
        ElementPlus.ElMessage.success('删除成功');
        await load(lastActionType.value, page.value);
      } else {
        ElementPlus.ElMessage.error(json.message || '删除失败');
      }
    }

    function applyTo(target, actionField, textField, assetId, assetIdField) {
      const id = firstId(assetId);
      const asset = list.value.find(item => Number(item.id) === id);
      if (!asset) {
        return;
      }
      target[actionField] = Number(asset.action_type);
      target[textField] = asset.param_text || '';
      if (assetIdField) {
        target[assetIdField] = asset.id;
      }
    }

    function applySelection(target, actionField, textField, assetIdField, assetIds, assetIdsField) {
      const ids = toIdList(assetIds);
      const assets = selected(ids);
      nextSelectionSequence(target);
      trackSelection(target, ids);
      cacheRows(assets);
      const id = firstId(ids);
      target[assetIdField] = id || null;
      if (assetIdsField) {
        target[assetIdsField] = ids;
      }
      if (assets.length > 0) {
        target[actionField] = Number(assets[0].action_type);
        // 资源参数由关联表接管，备用参数只保留手动填写内容，避免资源内容被误显示成备用参数。
        target[textField] = '';
      } else {
        target[textField] = '';
      }
    }

    async function restoreSelectionFromText(target, actionField, textField, assetIdField, assetIdsField) {
      const restoreSequence = nextSelectionSequence(target);
      const currentIds = idsOrFallback(target[assetIdsField], target[assetIdField]);
      const actionType = toAction(target[actionField]);
      const text = target[textField];
      if (currentIds.length > 0) {
        trackSelection(target, currentIds);
        target[textField] = '';
        return currentIds;
      }
      if (!hasParam(actionType) || !normalizeParamText(text)) {
        clearSelection(target);
        return [];
      }

      const cachedIds = inferIdsByText(actionType, text);
      if (cachedIds.length > 0) {
        target[assetIdField] = cachedIds[0];
        target[assetIdsField] = multipleLimit(actionType) === 1 ? [cachedIds[0]] : cachedIds;
        trackSelection(target, target[assetIdsField]);
        target[textField] = '';
        return target[assetIdsField];
      }

      const oldKeyword = keyword.value;
      const lookupKeyword = firstKeyword(text);
      if (!lookupKeyword) {
        clearSelection(target);
        return [];
      }
      // 历史数据可能只有备用参数，没有资源关联；这里按参数内容反查同类资源用于回显。
      keyword.value = lookupKeyword;
      try {
        await load(actionType, 1);
      } finally {
        keyword.value = oldKeyword;
      }
      if (!isCurrentSelectionSequence(target, restoreSequence)) {
        return [];
      }

      const loadedIds = inferIdsByText(actionType, text);
      if (loadedIds.length > 0) {
        target[assetIdField] = loadedIds[0];
        target[assetIdsField] = multipleLimit(actionType) === 1 ? [loadedIds[0]] : loadedIds;
        trackSelection(target, target[assetIdsField]);
        target[textField] = '';
        return target[assetIdsField];
      }
      clearSelection(target);
      return [];
    }

    return {
      list,
      page,
      limit,
      total,
      keyword,
      loading,
      dialogVisible,
      editVisible,
      editMode,
      sortSavingId,
      form,
      options,
      merge,
      selected,
      isEnabled,
      optionLabel,
      toIdList,
      firstId,
      idsOrFallback,
      submitFields,
      multipleLimit,
      load,
      onSelectVisible,
      openDialog,
      openEdit,
      submit,
      toggleEnabled,
      setSort,
      remove,
      applyTo,
      applySelection,
      clearSelection,
      restoreSelectionFromText
    };
  }

  window.ClickParamAsset = {
    ACTION_OPTIONS,
    actionLabel,
    hasParam,
    paramLabel,
    paramPlaceholder,
    paramInputType,
    multipleLimit,
    isEnabled,
    optionLabel,
    createStore
  };
})();
