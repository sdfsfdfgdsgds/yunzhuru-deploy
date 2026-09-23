(function (global) {
  'use strict';

  const COLORS = ['#409eff', '#e6a23c', '#67c23a', '#f56c6c', '#8e6df2'];
  const HOURS = Array.from({ length: 24 }, (_, hour) => hour);
  const { defineComponent, h, ref, computed, watch } = global.Vue;

  /** 统一归一化弹窗汇总与链接小时统计，供两个弹窗配置页复用。 */
  function normalize(data) {
    const source = data || {};
    const showCount = Number(source.show_count || 0);
    const clickCount = Number(source.click_count || 0);
    const hourly = source.link_hourly || {};
    const links = Array.isArray(hourly.links) ? hourly.links : [];
    const rows = Array.isArray(hourly.rows) ? hourly.rows : [];
    return {
      show_count: showCount,
      click_count: clickCount,
      ctr: showCount > 0 ? `${(clickCount * 100 / showCount).toFixed(1)}%` : '0.0%',
      details: (Array.isArray(source.details) ? source.details : []).map(item => ({
        ...item,
        button_index: Number(item.button_index),
        click_type: Number(item.click_type),
        count: Number(item.count || 0)
      })),
      link_hourly: {
        timezone: hourly.timezone || 'Asia/Shanghai',
        today: String(hourly.today || ''),
        yesterday: String(hourly.yesterday || ''),
        current_hour: Number.isInteger(Number(hourly.current_hour)) ? Number(hourly.current_hour) : -1,
        links: links.map((item, index) => ({
          ...item,
          key: String(item.key || `link-${index}`),
          click_text: String(item.click_text || ''),
          yesterday_total: Number(item.yesterday_total || 0),
          today_total: Number(item.today_total || 0)
        })),
        rows: rows.map((row, index) => ({
          hour: Number(row.hour ?? index),
          label: String(row.label || `${String(index).padStart(2, '0')}:00`),
          yesterday: row.yesterday || {},
          today: row.today || {}
        }))
      }
    };
  }

  function createEmpty() { return normalize({}); }

  /** 以原生 Vue 渲染实现可复用曲线、小时表、筛选和状态展示。 */
  const PopupClickStats = defineComponent({
    name: 'PopupClickStats',
    props: {
      data: { type: Object, default: createEmpty },
      loading: { type: Boolean, default: false },
      error: { type: String, default: '' }
    },
    emits: ['refresh'],
    setup(props, { emit }) {
      const selectedDate = ref('today');
      const selectedKeys = ref([]);
      const highlightedKey = ref('');
      const hoveredHour = ref(null);
      const stats = computed(() => normalize(props.data).link_hourly);
      const links = computed(() => stats.value.links);
      const dateText = computed(() => selectedDate.value === 'today' ? stats.value.today : stats.value.yesterday);
      const selectedLinks = computed(() => selectedKeys.value
        .map(key => links.value.find(link => link.key === key)).filter(Boolean));
      const maxValue = computed(() => Math.max(1, ...selectedLinks.value.flatMap(link => HOURS
        .map(hour => getValue(link, hour)))));

      function getValue(link, hour) {
        if (selectedDate.value === 'today' && hour > stats.value.current_hour) return null;
        const row = stats.value.rows.find(item => item.hour === hour);
        const counts = row && row[selectedDate.value];
        if (!counts || !Object.prototype.hasOwnProperty.call(counts, link.key)) return 0;
        const value = Number(counts[link.key]);
        return Number.isFinite(value) ? value : 0;
      }

      /** 按当前对比顺序分配颜色，任意挑选 5 条时也保持颜色互异。 */
      function colorFor(key) {
        const selectedIndex = selectedKeys.value.indexOf(key);
        return selectedIndex >= 0 ? COLORS[selectedIndex] : '#a6a9ad';
      }

      watch(links, value => {
        const valid = new Set(value.map(link => link.key));
        const retained = selectedKeys.value.filter(key => valid.has(key));
        if (!retained.length && value.length) selectedKeys.value = value.slice(0, 5).map(link => link.key);
        else selectedKeys.value = retained.slice(0, 5);
      }, { immediate: true });

      function toggleLink(key) {
        if (selectedKeys.value.includes(key)) selectedKeys.value = selectedKeys.value.filter(item => item !== key);
        else if (selectedKeys.value.length < 5) selectedKeys.value = [...selectedKeys.value, key];
      }
      function linePoints(link) {
        return HOURS.filter(hour => getValue(link, hour) !== null).map(hour => {
          const x = 52 + hour * (1000 / 23);
          const y = 220 - (getValue(link, hour) / maxValue.value) * 188;
          return `${x},${y}`;
        }).join(' ');
      }
      function pointY(link, hour) { return 220 - (getValue(link, hour) / maxValue.value) * 188; }
      function label(link) { return link.click_text || '未填写链接'; }
      function renderEmpty() {
        return h('div', { class: 'pcs-empty' }, props.loading ? '正在加载链接小时统计…' : '暂无链接点击记录');
      }

      return () => {
        const active = selectedLinks.value;
        const hasData = links.value.length > 0;
        const svgChildren = [];
        [0, 1, 2, 3, 4].forEach(step => {
          const y = 32 + step * 47;
          svgChildren.push(h('line', { x1: 52, y1: y, x2: 1052, y2: y, class: 'pcs-grid' }));
          const tickValue = Math.round(maxValue.value * (4 - step) / 4);
          svgChildren.push(h('text', { x: 45, y: y + 4, 'text-anchor': 'end', class: 'pcs-axis-label pcs-y-axis-label' }, String(tickValue)));
        });
        HOURS.filter(hour => hour % 3 === 0).forEach(hour => {
          const x = 52 + hour * (1000 / 23);
          svgChildren.push(h('text', { x, y: 250, 'text-anchor': 'middle', class: 'pcs-axis-label' }, `${String(hour).padStart(2, '0')}:00`));
        });
        active.forEach(link => {
          const color = colorFor(link.key);
          const dimmed = highlightedKey.value && highlightedKey.value !== link.key;
          svgChildren.push(h('polyline', {
            points: linePoints(link), fill: 'none', stroke: color, 'stroke-width': highlightedKey.value === link.key ? 3.5 : 2.5,
            opacity: dimmed ? 0.18 : 1, class: 'pcs-line'
          }));
          HOURS.forEach(hour => {
            const value = getValue(link, hour);
            if (value === null) return;
            svgChildren.push(h('circle', {
              cx: 52 + hour * (1000 / 23), cy: pointY(link, hour), r: hoveredHour.value === hour ? 5 : 3,
              fill: color, opacity: dimmed ? 0.15 : 1, class: 'pcs-point',
              onMouseenter: () => { hoveredHour.value = hour; },
              onClick: () => { hoveredHour.value = hour; }
            }));
          });
        });
        const hovered = hoveredHour.value;
        const hoverRows = hovered === null ? [] : active.map(link => ({ link, value: getValue(link, hovered) }));
        const children = [
          h('div', { class: 'pcs-toolbar' }, [
            h('div', { class: 'pcs-date-switch' }, [
              h('button', { class: { 'pcs-active': selectedDate.value === 'yesterday' }, onClick: () => { selectedDate.value = 'yesterday'; hoveredHour.value = null; } }, `昨天 ${stats.value.yesterday || ''}`),
              h('button', { class: { 'pcs-active': selectedDate.value === 'today' }, onClick: () => { selectedDate.value = 'today'; hoveredHour.value = null; } }, `今天 ${stats.value.today || ''}`)
            ]),
            h('span', { class: 'pcs-timezone' }, `时区：${stats.value.timezone || 'Asia/Shanghai'}`),
            h('button', { class: 'pcs-refresh', disabled: props.loading, onClick: () => emit('refresh') }, props.loading ? '刷新中…' : '刷新')
          ]),
          props.error ? h('div', { class: 'pcs-error', role: 'alert' }, [props.error, h('button', { onClick: () => emit('refresh') }, '重试')]) : null,
          !hasData ? renderEmpty() : h('div', { class: 'pcs-links' }, links.value.map(link => {
            const color = colorFor(link.key);
            const chosen = selectedKeys.value.includes(link.key);
            return h('button', {
              class: { 'pcs-link-card': true, 'pcs-link-selected': chosen }, title: link.click_text,
              disabled: !chosen && selectedKeys.value.length >= 5,
              onClick: () => toggleLink(link.key), onMouseenter: () => { highlightedKey.value = link.key; },
              onMouseleave: () => { highlightedKey.value = ''; }
            }, [h('span', { class: 'pcs-color', style: { backgroundColor: color } }),
              h('span', { class: 'pcs-link-label' }, label(link)),
              h('span', { class: 'pcs-link-total' }, `昨天 ${link.yesterday_total} · 今天 ${link.today_total}`)]);
          })),
          hasData ? h('div', { class: 'pcs-chart-wrap' }, [
            h('div', { class: 'pcs-chart-heading' }, [h('strong', null, `${dateText.value || (selectedDate.value === 'today' ? '今天' : '昨天')} 每小时点击量`),
              h('span', null, selectedDate.value === 'today' ? '当前小时尚未结束，数据持续增加' : '完整自然日')]),
            h('svg', { class: 'pcs-chart', viewBox: '0 0 1080 264', role: 'img', 'aria-label': '链接每小时点击曲线' }, svgChildren),
            hovered !== null ? h('div', { class: 'pcs-hover' }, [
              h('strong', null, `${String(hovered).padStart(2, '0')}:00${selectedDate.value === 'today' && hovered === stats.value.current_hour ? '（当前小时，未完结）' : ''}`),
	              ...hoverRows.map(({ link, value }) => h('div', { class: 'pcs-hover-row' }, [
                h('span', { class: 'pcs-color', style: { backgroundColor: colorFor(link.key) } }),
	                h('span', { class: 'pcs-hover-label', title: link.click_text }, label(link)),
	                h('b', null, value === null ? '—' : value)
	              ]))
            ]) : null,
            h('div', { class: 'pcs-hour-table-wrap' }, h('table', { class: 'pcs-hour-table' }, [
              h('thead', null, h('tr', null, [h('th', null, '小时'), ...active.map(link => h('th', { title: link.click_text }, label(link))), h('th', { title: '所选链接之和' }, '合计（所选链接）')])),
              h('tbody', null, HOURS.map(hour => {
                const values = active.map(link => getValue(link, hour));
                const sum = values.reduce((acc, value) => acc + (value === null ? 0 : value), 0);
                const isCurrent = selectedDate.value === 'today' && hour === stats.value.current_hour;
                return h('tr', { class: { 'pcs-current-hour': isCurrent, 'pcs-future-hour': selectedDate.value === 'today' && hour > stats.value.current_hour }, onMouseenter: () => { hoveredHour.value = hour; }, onMouseleave: () => { hoveredHour.value = null; } }, [
                  h('th', null, `${String(hour).padStart(2, '0')}:00${isCurrent ? ' *' : ''}`),
                  ...values.map(value => h('td', null, value === null ? '—' : String(value))),
                  h('td', { class: 'pcs-sum' }, selectedDate.value === 'today' && hour > stats.value.current_hour ? '—' : String(sum))
                ]);
              }))
            ])),
            selectedDate.value === 'today' ? h('div', { class: 'pcs-footnote' }, '* 当前小时尚未完结；未来小时以“—”显示。最多同时对比 5 条链接。') : h('div', { class: 'pcs-footnote' }, '小时按北京时间统计。最多同时对比 5 条链接。')
          ]) : null
        ].filter(Boolean);
        return h('section', { class: 'popup-click-stats' }, children);
      };
    }
  });

  global.PopupClickStats = { createEmpty, normalize, component: PopupClickStats };
})(window);
