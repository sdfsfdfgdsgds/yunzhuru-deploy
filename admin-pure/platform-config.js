/**
 * Pure Admin 运行时配置。
 *
 * 使用 `.js` 而非 `.json`，以适配现有 PHP 静态资源白名单；
 * 该文件在 Vue 入口之前加载，仍支持部署后独立调整界面配置。
 */
window.__YUNZHURU_PLATFORM_CONFIG__ = {
  Version: "6.2.0-yunzhuru.0",
  Title: "云注入管理后台",
  FixedHeader: true,
  HiddenSideBar: false,
  MultiTagsCache: false,
  KeepAlive: true,
  Layout: "vertical",
  Theme: "light",
  DarkMode: false,
  OverallStyle: "light",
  Grey: false,
  Weak: false,
  HideTabs: false,
  HideFooter: false,
  Stretch: false,
  SidebarStatus: true,
  EpThemeColor: "#409EFF",
  ShowLogo: true,
  ShowModel: "smart",
  MenuArrowIconNoTransition: false,
  CachingAsyncRoutes: false,
  TooltipEffect: "light",
  ResponsiveStorageNameSpace: "responsive-",
  MenuSearchHistory: 6
};
