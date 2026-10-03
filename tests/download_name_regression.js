#!/usr/bin/env node

/**
 * 下载名模板回归：模板展开、文件名安全边界，以及两个业务页统一复用工具。
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const serverRoot = path.resolve(__dirname, '..');
const helperPath = path.join(serverRoot, 'pages', 'app', 'common', 'download-name.js');
const helperSource = fs.readFileSync(helperPath, 'utf8');
const context = { window: {}, console };
vm.runInNewContext(helperSource, context, { filename: helperPath });
const helper = context.window.YunzhuruDownloadName;
let passed = 0;

function expect(condition, message) {
  if (!condition) throw new Error(`[FAIL] ${message}`);
  passed += 1;
}

const fixture = {
  name: '演示应用',
  version: '1.2.3',
  package: 'com.example.demo',
  appid: 42,
  task_id: 314
};
const date = new Date(2026, 9, 3);
expect(helper.DEFAULT_TEMPLATE === '{name}_{date}_云注入.apk', '默认模板为应用名、日期和云注入后缀');
expect(helper.resolve('', fixture, date) === '演示应用_20261003_云注入.apk', '空模板使用默认下载文件名');
expect(
  helper.resolve('{name}_{date}_{version}_{package}_{appid}_{task_id}', fixture, date)
    === '演示应用_20261003_1.2.3_com.example.demo_42_314.apk',
  '名称、日期、版本、包名、APPID、任务 ID 占位符展开正确'
);
expect(helper.validateTemplate('{name}_{date}_{version}') === '', '支持的占位符模板可保存');
expect(helper.validateTemplate('{unknown}') !== '', '未知占位符被拒绝');
expect(helper.validateTemplate('../escape') !== '', '路径分隔符被拒绝');
expect(helper.resolve('{name}/x', fixture, date) === '演示应用_x.apk', '展开结果清理路径分隔符');

for (const [pageRoot, pageName] of [['app', 'my_apps.html'], ['app', 'injected.html'], ['admin', 'release.html']]) {
  const html = fs.readFileSync(path.join(serverRoot, 'pages', pageRoot, pageName), 'utf8');
  expect(html.includes('/pages/app/common/download-name.js'), `${pageName} 接入统一下载名模块`);
  expect(html.includes('YunzhuruDownloadName.resolve'), `${pageName} 下载入口使用统一展开函数`);
}

const appsHtml = fs.readFileSync(path.join(serverRoot, 'pages', 'app', 'my_apps.html'), 'utf8');
expect(appsHtml.includes('@click="openDownloadNameSettings(scope.row)"'), '应用列表提供下载名设置入口');
expect(appsHtml.includes('download_name_template'), '下载名设置通过应用模板字段保存');
expect(appsHtml.includes('insertDownloadNameToken(token.key)'), '设置页变量支持点击插入');
expect(appsHtml.includes('{name}_{date}_云注入.apk'), '设置页展示默认模板');

const downPhp = fs.readFileSync(path.join(serverRoot, 'down.php'), 'utf8');
expect(downPhp.includes('renderDownloadNameTemplate($downloadTemplate'), '下载接口先展开服务端模板');
expect(downPhp.includes("$downloadCacheKey = buildDownloadCacheKey($filename, $downloadName)"), '下载缓存按最终文件名隔离');
expect(downPhp.includes('if ($task && !$hasCustomDownloadName)'), '自定义名称不会复用旧桶对象记录');

const appPhp = fs.readFileSync(path.join(serverRoot, 'api', 'module', 'app.php'), 'utf8');
expect(appPhp.includes('download_name_template = :download_name_template'), '应用接口支持保存下载名模板');
expect(appPhp.includes('a.download_name_template'), '任务接口返回下载名模板');

const migrationSql = fs.readFileSync(path.join(serverRoot, 'install', 'migrate_download_name_template.sql'), 'utf8');
expect(migrationSql.includes('ADD `download_name_template`'), '迁移脚本补充下载名模板字段');

const lanzouPhp = fs.readFileSync(path.join(serverRoot, 'api', 'module', 'lanzou.php'), 'utf8');
expect(lanzouPhp.includes('renderDownloadNameTemplate($apk[\'download_name_template\']'), '蓝奏转存复用同一下载名模板');

console.log(`download_name_regression: PASS (${passed})`);
