/**
 * Element Plus 文档根滚动锁宽度稳定器。
 *
 * Dialog、Drawer、MessageBox 和全屏 Loading 会在打开期间隐藏 body
 * 滚动条。经典占位滚动条环境中，这会扩大页面可用宽度。本脚本只在
 * Element Plus 真正锁定文档 body 时，使用打开前记录的根滚动条宽度约束
 * body；内部表格、弹窗正文和普通 v-loading 的滚动容器均不受影响。
 */
(function installAdminScrollLockStabilizer(global) {
  'use strict';

  const ASSET_VERSION = '20260902-1';
  const currentScript = document.currentScript;
  const existing = global.AdminScrollLockStabilizer;
  if (
    existing
    && existing.assetVersion === ASSET_VERSION
    && typeof existing.refresh === 'function'
  ) {
    existing.refresh();
    if (currentScript && currentScript.dataset) {
      currentScript.dataset.adminScrollLockLoaded = 'true';
    }
    return;
  }
  // 旧文档中已加载的其它版本先按自身合同清理，再由本版本接管。
  if (existing && typeof existing.destroy === 'function') {
    existing.destroy();
  }

  const ACTIVE_ROOT_CLASS = 'admin-scroll-lock-stabilized';
  const STYLE_ID = 'admin-scroll-lock-stabilizer-style';
  const SCROLLBAR_WIDTH_PROPERTY = '--admin-root-scrollbar-width';
  const BODY_LOCK_CLASSES = Object.freeze([
    'el-popup-parent--hidden',
    'el-loading-parent--hidden',
    'el-tour-parent--hidden',
  ]);
  const ACTIVE_LAYER_SELECTORS = Object.freeze({
    'el-popup-parent--hidden': '.el-dialog, .el-drawer, .el-message-box',
    'el-loading-parent--hidden': '.el-loading-mask.is-fullscreen',
    'el-tour-parent--hidden': '.el-tour__content',
  });

  let bodyObserver = null;
  let rootResizeObserver = null;
  let scheduledFrame = 0;
  let followUpFrame = 0;
  let lastUnlockedScrollbarWidth = 0;
  let hasScrollbarWidthBaseline = false;
  let disposed = false;
  let synchronizing = false;
  const managedLockClasses = new Set();
  const observedLockClasses = new Set();

  /** 仅识别 Element Plus 在文档 body 上的三类根锁，局部 Loading 不会命中。 */
  function isBodyScrollLocked() {
    const body = document.body;
    return Boolean(body && BODY_LOCK_CLASSES.some(className => body.classList.contains(className)));
  }

  /** 只把实际占据布局的弹层视为活动，已 v-show 隐藏的常驻 DOM 不计入。 */
  function hasRenderedLayer(selector) {
    return [...document.querySelectorAll(selector)].some(element => {
      const style = global.getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style.display !== 'none'
        && style.visibility !== 'hidden'
        && rect.width > 0
        && rect.height > 0;
    });
  }

  /**
   * Element Plus 的多实例 useLockscreen 可能由先打开的弹层拥有唯一 body 锁类。
   * 先关闭该层时，同类的另一层仍可见，但延迟清理会移除锁类。
   * 本函数只在仍有真实可见弹层时补回缺失的同类锁，并在最后一层
   * 离场后释放由本脚本补的锁，从而保持原有背景不可滚动语义。
   */
  function reconcileVisibleLayerLocks() {
    const body = document.body;
    if (!body) return;
    for (const lockClass of BODY_LOCK_CLASSES) {
      const hasActiveLayer = hasRenderedLayer(ACTIVE_LAYER_SELECTORS[lockClass]);
      const hasBodyClass = body.classList.contains(lockClass);
      // 只续接本轮曾由 Element Plus 真实加过的锁，避免改变 lock-scroll=false 组件的显式语义。
      if (hasBodyClass && !managedLockClasses.has(lockClass)) {
        observedLockClasses.add(lockClass);
      }
      if (hasActiveLayer && !hasBodyClass && observedLockClasses.has(lockClass)) {
        managedLockClasses.add(lockClass);
        body.classList.add(lockClass);
      } else if (!hasActiveLayer && managedLockClasses.has(lockClass)) {
        managedLockClasses.delete(lockClass);
        body.classList.remove(lockClass);
        observedLockClasses.delete(lockClass);
      } else if (!hasActiveLayer && !hasBodyClass) {
        observedLockClasses.delete(lockClass);
      }
    }
  }

  /**
   * 在未锁定时记录视口与根内容区的差值。
   * overlay scrollbar（覆盖式滚动条）返回 0，因此 macOS 等环境不会被额外缩窄。
   */
  function rememberUnlockedScrollbarWidth() {
    if (disposed || isBodyScrollLocked() || !document.documentElement) return;
    const measuredWidth = Math.max(0, global.innerWidth - document.documentElement.clientWidth);
    if (Number.isFinite(measuredWidth)) {
      lastUnlockedScrollbarWidth = measuredWidth;
      hasScrollbarWidthBaseline = true;
    }
  }

  /**
   * 脚本在页面已开弹层后才被 iframe 壳注入时，没有现成的未锁定基线。
   * 此时在同一 JavaScript 任务内暂时撤下锁类与 Element Plus 临时宽度，
   * 同步读取自然根布局后立即原样恢复。浏览器在任务结束前不会绘制，
   * 因此这个量取过程不会暴露一帧可滚动背景。
   */
  function rememberScrollbarWidthFromLockedPage() {
    if (disposed || !isBodyScrollLocked() || !document.documentElement || !document.body) return;
    const body = document.body;
    const lockedClasses = BODY_LOCK_CLASSES.filter(className => body.classList.contains(className));
    const inlineWidth = body.style.getPropertyValue('width');
    const inlineWidthPriority = body.style.getPropertyPriority('width');

    lockedClasses.forEach(className => body.classList.remove(className));
    body.style.removeProperty('width');
    const measuredWidth = Math.max(0, global.innerWidth - document.documentElement.clientWidth);
    if (Number.isFinite(measuredWidth)) {
      lastUnlockedScrollbarWidth = measuredWidth;
      hasScrollbarWidthBaseline = true;
    }

    if (inlineWidth) {
      body.style.setProperty('width', inlineWidth, inlineWidthPriority);
    } else {
      body.style.removeProperty('width');
    }
    lockedClasses.forEach(className => body.classList.add(className));
  }

  /** 按当前是否已锁定，选择直接或同步临时解锁的量取方式。 */
  function refreshScrollbarWidthBaseline() {
    if (isBodyScrollLocked()) {
      rememberScrollbarWidthFromLockedPage();
      return;
    }
    rememberUnlockedScrollbarWidth();
  }

  /** 取消待执行的合并帧，避免页面销毁后继续访问旧文档。 */
  function cancelScheduledFrames() {
    if (scheduledFrame) {
      global.cancelAnimationFrame(scheduledFrame);
      scheduledFrame = 0;
    }
    if (followUpFrame) {
      global.cancelAnimationFrame(followUpFrame);
      followUpFrame = 0;
    }
  }

  /**
   * 同步根锁定状态。宽度规则使用 !important 覆盖 Element Plus 的临时 inline width，
   * 但不改写该 inline 值，因此关闭弹层时仍由 Element Plus 按原生时序恢复。
   */
  function synchronizeLockState() {
    if (disposed || synchronizing || !document.documentElement || !document.body) return;
    synchronizing = true;
    try {
      const root = document.documentElement;
      reconcileVisibleLayerLocks();
      if (isBodyScrollLocked()) {
        // 没有可信基线时保留 Element Plus 原生补偿，避免用默认 0 覆盖正确宽度。
        if (!hasScrollbarWidthBaseline) {
          root.classList.remove(ACTIVE_ROOT_CLASS);
          root.style.removeProperty(SCROLLBAR_WIDTH_PROPERTY);
          return;
        }
        root.style.setProperty(SCROLLBAR_WIDTH_PROPERTY, `${lastUnlockedScrollbarWidth}px`);
        root.classList.add(ACTIVE_ROOT_CLASS);
        return;
      }

      root.classList.remove(ACTIVE_ROOT_CLASS);
      root.style.removeProperty(SCROLLBAR_WIDTH_PROPERTY);
      rememberUnlockedScrollbarWidth();
    } finally {
      synchronizing = false;
    }
  }

  /**
   * 将同一轮尺寸或已接管弹层的变化收敛到一次帧内。
   * 普通未锁页只更新根滚动条基线，避免后台业务 DOM 频繁更新时
   * 扫描 Dialog、Drawer 等全局选择器。
   */
  function scheduleSynchronization() {
    if (disposed || scheduledFrame) return;
    scheduledFrame = global.requestAnimationFrame(() => {
      scheduledFrame = 0;
      if (isBodyScrollLocked() || managedLockClasses.size > 0) {
        synchronizeLockState();
        return;
      }
      rememberUnlockedScrollbarWidth();
    });
  }

  /** body 自身的锁类变化需在绘制前立即处理；弹层 DOM 过渡变化合并到下一帧。 */
  function handleBodyMutations(records) {
    const hasBodyLockMutation = records.some(record => (
      record.type === 'attributes'
      && record.target === document.body
      && record.attributeName === 'class'
    ));
    if (hasBodyLockMutation) {
      synchronizeLockState();
      return;
    }
    // 普通页面的表格、计时器和表单 class/style 变化与根锁无关。
    // 只在本脚本为重叠弹层续接锁类后，才跟踪其最后一层离场。
    if (managedLockClasses.size > 0) {
      scheduleSynchronization();
    }
  }

  /** 注入只命中文档 body 锁类的宽度规则。 */
  function ensureStyleContract() {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
html.${ACTIVE_ROOT_CLASS} > body.el-popup-parent--hidden,
html.${ACTIVE_ROOT_CLASS} > body.el-loading-parent--hidden,
html.${ACTIVE_ROOT_CLASS} > body.el-tour-parent--hidden {
  width: calc(100% - var(${SCROLLBAR_WIDTH_PROPERTY}, 0px)) !important;
}
`;
    (document.head || document.documentElement).appendChild(style);
  }

  /**
   * 主动重新量取两帧，用于公共主题刚加载、iframe 刚显示或响应式尺寸改变后更新基线。
   */
  function refresh() {
    if (disposed) return;
    ensureStyleContract();
    cancelScheduledFrames();
    refreshScrollbarWidthBaseline();
    synchronizeLockState();
    scheduledFrame = global.requestAnimationFrame(() => {
      scheduledFrame = 0;
      synchronizeLockState();
      followUpFrame = global.requestAnimationFrame(() => {
        followUpFrame = 0;
        synchronizeLockState();
      });
    });
  }

  /**
   * BFCache（前进/后退缓存）页面会在 pageshow 恢复，此时保留观察器；
   * 只有文档真正卸载时才销毁实例。
   */
  function handlePageHide(event) {
    if (!event.persisted) destroy();
  }

  /** 页面卸载时释放观察器；应用主动调用后也可安全重复执行。 */
  function destroy() {
    if (disposed) return;
    disposed = true;
    cancelScheduledFrames();
    if (bodyObserver) bodyObserver.disconnect();
    if (rootResizeObserver) rootResizeObserver.disconnect();
    bodyObserver = null;
    rootResizeObserver = null;
    if (document.body) {
      managedLockClasses.forEach(className => document.body.classList.remove(className));
    }
    managedLockClasses.clear();
    observedLockClasses.clear();
    global.removeEventListener('resize', scheduleSynchronization);
    global.removeEventListener('pageshow', refresh);
    global.removeEventListener('pagehide', handlePageHide);
    if (document.documentElement) {
      document.documentElement.classList.remove(ACTIVE_ROOT_CLASS);
      document.documentElement.style.removeProperty(SCROLLBAR_WIDTH_PROPERTY);
    }
  }

  function start() {
    if (!document.body || !document.documentElement) return;
    ensureStyleContract();
    refreshScrollbarWidthBaseline();

    // MutationObserver 回调会在浏览器绘制前执行，body 锁类因此可以同帧补偿。
    bodyObserver = new MutationObserver(handleBodyMutations);
    bodyObserver.observe(document.body, {
      attributes: true,
      attributeFilter: ['class', 'style', 'hidden'],
      childList: true,
      subtree: true,
    });

    if (typeof ResizeObserver === 'function') {
      rootResizeObserver = new ResizeObserver(scheduleSynchronization);
      rootResizeObserver.observe(document.documentElement);
      rootResizeObserver.observe(document.body);
    }

    global.addEventListener('resize', scheduleSynchronization, { passive: true });
    global.addEventListener('pageshow', refresh);
    global.addEventListener('pagehide', handlePageHide);
    refresh();
  }

  global.AdminScrollLockStabilizer = Object.freeze({
    assetVersion: ASSET_VERSION,
    version: '1.0.0',
    refresh,
    destroy,
    lockClasses: BODY_LOCK_CLASSES,
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }

  if (currentScript && currentScript.dataset) {
    currentScript.dataset.adminScrollLockLoaded = 'true';
  }
})(window);
