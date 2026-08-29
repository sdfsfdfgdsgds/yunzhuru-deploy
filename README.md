# yunzhuru-deploy

云注入 Railway 生产部署仓。此仓库是独立的 Docker 构建上下文，和主源码仓
`/Users/yyh/Documents/Codex/云注入` 分开维护；只有本仓的 `main` 或显式
`railway up` 会影响 `yunzhuru-app`。

## 生产合同

- Railway 项目：`a1a520ff-d0b2-4e47-9c79-a0a9f3f9297d`
- 环境/服务：`production` / `yunzhuru-app`
- 构建入口：`railway.json` → 根目录 `Dockerfile`
- 健康探针：`/healthz`（轻量进程探针，超时 120 秒）
- 运行资产：`/var/www/html/uploads` 持久卷；模板、签名文件和发布产物按启动脚本规则保留

数据库、Redis、AWS 和 CloudFront 的敏感值只从 Railway Variables 读取。本仓库不保存可用凭据，
也不把运行日志、PID、Finder 元数据或本机缓存带入镜像。

## 本地检查与发布

```bash
php -l router.php
php -l api/index.php
git diff --check
railway up \
  --project a1a520ff-d0b2-4e47-9c79-a0a9f3f9297d \
  --environment production \
  --service yunzhuru-app \
  --detach
```

发布后在主源码仓执行：

```bash
/Users/yyh/Documents/Codex/云注入/scripts/verify-production.sh https://zkzam9hoby.top
```

同时记录 deployment ID、健康探针、后台页面、未登录 API 响应和 worker 日志。GitHub trigger 与显式
`railway up` 二选一，避免同一提交重复发布。

## 文件说明

- `Dockerfile`：当前 Railway 构建文件。
- `entrypoint.sh`：读取环境变量、等待外部 MySQL、准备持久目录并启动 supervisor。
- `router.php`：公开路径白名单、健康探针和静态资源路由。
- `supervisord.conf`：PHP、业务 worker、配置失效 worker、API 域名池 worker。
- `config/`：仅保留环境变量读取模板，真实值由运行环境注入。

生产数据卷、用户上传文件、模板、签名材料、数据库和 Redis 不随 Git 清理；删除或迁移需另行备份、
演练和记录。
