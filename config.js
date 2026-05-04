// config.js - 后台管理系统基础配置文件

const config = {
    // 网站标题
    siteTitle: '菜鸟后台管理系统',
  
    // 子页面文件后缀，如 .html、.vue 等
    pageSuffix: '.html',

    // 子页面所在目录（以 / 结尾）
    pagePath: 'pages/',
  
    // 默认后台首页路由
    defaultPage: 'dashboard',
  
    // 是否启用调试模式
    debug: true,
  
    // 接口基础地址
    apiBaseUrl: '',
  
    // 登录页路径
    loginPage: 'login',
  
    // 本地存储 Token 的键名
    tokenKey: 'admin_token',
  
    // 主题颜色（可用于动态换肤）
    themeColor: '#409EFF',
  
    // 是否启用多标签页
    enableTabs: true,
  
    // 菜单折叠状态默认值
    menuCollapse: false,
  
    // 系统版本号
    version: '1.0.0',
  
    // 是否启用权限控制
    enablePermission: true
  };
  
  