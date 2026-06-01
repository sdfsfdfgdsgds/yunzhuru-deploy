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
        gap: 12px;
        width: 100%;
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
      .param-preview-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
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

  function createStore(Vue, ElementPlus) {
    ensureStyles();

    const list = Vue.ref([]);
    const page = Vue.ref(1);
    const limit = Vue.ref(20);
    const total = Vue.ref(0);
    const keyword = Vue.ref('');
    const loading = Vue.ref(false);
    const knownList = Vue.ref([]);
    const dialogVisible = Vue.ref(false);
    const editVisible = Vue.ref(false);
    const editMode = Vue.ref(false);
    const lastActionType = Vue.ref(null);
    const form = Vue.reactive({
      id: null,
      name: '',
      action_type: 1,
      param_text: '',
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

    function options(actionType) {
      const type = toAction(actionType);
      return allKnownRows().filter(item => Number(item.action_type) === type);
    }

    function merge(assets) {
      const rows = Array.isArray(assets) ? assets : [assets];
      cacheRows(rows);
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
      if (typeof p !== 'number') {
        p = page.value || 1;
      }
      page.value = p || 1;
      lastActionType.value = actionType === null || actionType === undefined || actionType === '' ? null : toAction(actionType);
      loading.value = true;
      try {
        const payload = {
          page: page.value,
          limit: limit.value,
          keyword: keyword.value
        };
        if (lastActionType.value !== null) {
          payload.action_type = lastActionType.value;
        }
        const res = await fetch('/api/index.php?module=click_param_asset&method=getList', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.code === 200) {
          list.value = json.data.list || [];
          cacheRows(list.value);
          total.value = json.data.total || 0;
        } else {
          ElementPlus.ElMessage.error(json.message || '事件参数资源加载失败');
        }
      } finally {
        loading.value = false;
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
        editMode.value = true;
        Object.assign(form, {
          id: row.id,
          name: row.name || '',
          action_type: Number(row.action_type || 1),
          param_text: row.param_text || '',
          remark: row.remark || ''
        });
      } else {
        editMode.value = false;
        Object.assign(form, {
          id: null,
          name: '',
          action_type: hasParam(actionType) ? toAction(actionType) : 1,
          param_text: '',
          remark: ''
        });
      }
      editVisible.value = true;
    }

    async function submit() {
      const method = editMode.value ? 'editAsset' : 'addAsset';
      const res = await fetch(`/api/index.php?module=click_param_asset&method=${method}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form })
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
      const id = firstId(ids);
      target[assetIdField] = id || null;
      if (assetIdsField) {
        target[assetIdsField] = ids;
      }
      if (assets.length > 0) {
        target[actionField] = Number(assets[0].action_type);
        target[textField] = assets
          .map(asset => asset.param_text || '')
          .filter(Boolean)
          .join('\n');
      } else {
        target[textField] = '';
      }
    }

    async function restoreSelectionFromText(target, actionField, textField, assetIdField, assetIdsField) {
      const currentIds = idsOrFallback(target[assetIdsField], target[assetIdField]);
      const actionType = toAction(target[actionField]);
      const text = target[textField];
      if (currentIds.length > 0 || !hasParam(actionType) || !normalizeParamText(text)) {
        return [];
      }

      const cachedIds = inferIdsByText(actionType, text);
      if (cachedIds.length > 0) {
        target[assetIdField] = cachedIds[0];
        target[assetIdsField] = multipleLimit(actionType) === 1 ? [cachedIds[0]] : cachedIds;
        return target[assetIdsField];
      }

      const oldKeyword = keyword.value;
      const lookupKeyword = firstKeyword(text);
      if (!lookupKeyword) {
        return [];
      }
      // 历史数据可能只有备用参数，没有资源关联；这里按参数内容反查同类资源用于回显。
      keyword.value = lookupKeyword;
      try {
        await load(actionType, 1);
      } finally {
        keyword.value = oldKeyword;
      }

      const loadedIds = inferIdsByText(actionType, text);
      if (loadedIds.length > 0) {
        target[assetIdField] = loadedIds[0];
        target[assetIdsField] = multipleLimit(actionType) === 1 ? [loadedIds[0]] : loadedIds;
        return target[assetIdsField];
      }
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
      form,
      options,
      merge,
      selected,
      toIdList,
      firstId,
      idsOrFallback,
      multipleLimit,
      load,
      onSelectVisible,
      openDialog,
      openEdit,
      submit,
      remove,
      applyTo,
      applySelection,
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
    createStore
  };
})();
