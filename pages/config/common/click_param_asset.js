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

  function createStore(Vue, ElementPlus) {
    const list = Vue.ref([]);
    const page = Vue.ref(1);
    const limit = Vue.ref(20);
    const total = Vue.ref(0);
    const keyword = Vue.ref('');
    const loading = Vue.ref(false);
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

    function options(actionType) {
      const type = toAction(actionType);
      return list.value.filter(item => Number(item.action_type) === type);
    }

    function merge(assets) {
      const rows = Array.isArray(assets) ? assets : [assets];
      const existed = new Set(list.value.map(item => Number(item.id)));
      rows.forEach(item => {
        if (item && item.id && !existed.has(Number(item.id))) {
          list.value.push(item);
          existed.add(Number(item.id));
        }
      });
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
      const asset = list.value.find(item => Number(item.id) === Number(assetId));
      if (!asset) {
        return;
      }
      target[actionField] = Number(asset.action_type);
      target[textField] = asset.param_text || '';
      if (assetIdField) {
        target[assetIdField] = asset.id;
      }
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
      load,
      onSelectVisible,
      openDialog,
      openEdit,
      submit,
      remove,
      applyTo
    };
  }

  window.ClickParamAsset = {
    ACTION_OPTIONS,
    actionLabel,
    hasParam,
    paramLabel,
    paramPlaceholder,
    paramInputType,
    createStore
  };
})();
