/*
 * 应用下载文件名模板工具。
 *
 * 页面只保存模板，实际下载前再根据当前应用和任务展开，避免重新上传 APK
 * 或修改应用展示名称时生成旧文件名。该文件不依赖 Vue，可被“我的应用”和
 * “注入管理”等页面复用。
 */
(function (global) {
  'use strict';

  const TOKEN_LABELS = Object.freeze({
    name: '名称',
    date: '日期',
    version: '版本',
    package: '包名',
    appid: 'APPID',
    task_id: '任务 ID'
  });
  const DEFAULT_TEMPLATE = '{name}_{date}_云注入.apk';
  const TOKEN_PATTERN = /\{(name|date|version|package|appid|task_id)\}/g;
  const INVALID_FILENAME_PATTERN = /[\\/\u0000-\u001f\u007f]/;

  /** 将值收敛为可安全插入文件名的单行文本。 */
  function normalizePart(value) {
    return String(value == null ? '' : value)
      .replace(/[\\/\u0000-\u001f\u007f]+/g, '_')
      .replace(/\s+/g, ' ')
      .trim();
  }

  /** 使用本地日期生成文件名友好的 YYYYMMDD 日期。 */
  function formatDate(date) {
    const value = date instanceof Date && !Number.isNaN(date.getTime()) ? date : new Date();
    const year = value.getFullYear();
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');
    return `${year}${month}${day}`;
  }

  /**
   * 展开下载文件名模板，并保证结果带有 APK 扩展名。
   * 空模板使用“应用名_日期_云注入.apk”；未知占位符不参与替换，保存时由页面校验拒绝。
   */
  function resolve(template, context, date) {
    const source = context && typeof context === 'object' ? context : {};
    const values = {
      name: normalizePart(source.name || source.apk_name || 'download'),
      date: formatDate(date),
      version: normalizePart(source.version || source.apk_version || ''),
      package: normalizePart(source.package || source.apk_package || ''),
      appid: normalizePart(source.appid || source.apk_id || source.id || ''),
      task_id: normalizePart(source.task_id || source.taskId || '')
    };
    const rawTemplate = String(template == null ? '' : template).trim() || DEFAULT_TEMPLATE;
    let result = rawTemplate.replace(TOKEN_PATTERN, function (_, token) {
      return values[token] || '';
    });
    result = normalizePart(result).replace(/[<>:"|?*]+/g, '_').trim();
    if (!result) result = values.name || 'download';
    if (!/\.apk$/i.test(result)) result += '.apk';
    return result;
  }

  /** 校验保存的模板，阻止路径分隔符和不可见字符进入下载名合同。 */
  function validateTemplate(template) {
    const value = String(template == null ? '' : template).trim();
    if (value.length > 120) return '下载名模板不能超过 120 个字符';
    if (INVALID_FILENAME_PATTERN.test(value) || /[<>:"|?*]/.test(value)) {
      return '下载名模板不能包含路径分隔符或文件名特殊字符';
    }
    const unsupported = value.match(/\{[^{}]+\}/g) || [];
    const allowed = new Set(Object.keys(TOKEN_LABELS));
    for (const token of unsupported) {
      const key = token.slice(1, -1);
      if (!allowed.has(key)) return `不支持的占位符：${token}`;
    }
    const withoutSupportedTokens = value.replace(/\{(?:name|date|version|package|appid|task_id)\}/g, '');
    if (/[{}]/.test(withoutSupportedTokens)) return '下载名模板中的占位符格式不正确';
    return '';
  }

  global.YunzhuruDownloadName = Object.freeze({
    DEFAULT_TEMPLATE,
    TOKEN_LABELS,
    formatDate,
    resolve,
    validateTemplate
  });
})(window);
