FROM php:7.4-cli-bullseye

# 系统依赖（含 Android 注入工具链）
# Railway 构建机偶发 Debian 源连接重置，apt 增加重试和缺失包修复，避免临时网络抖动导致发布失败。
RUN apt-get -o Acquire::Retries=5 update \
    && apt-get -o Acquire::Retries=5 install -y --fix-missing --no-install-recommends \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev \
    supervisor \
    aapt zipalign default-jre-headless \
    curl python3 python3-pip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql mysqli gd zip pcntl \
    && pecl install redis-5.3.7 && docker-php-ext-enable redis

# 时区
ENV TZ=Asia/Shanghai
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# PHP 配置（大文件上传）
RUN echo "upload_max_filesize = 4096M\npost_max_size = 4096M\nmemory_limit = 4096M\nmax_execution_time = 600\nmax_input_time = 600" > /usr/local/etc/php/conf.d/uploads.ini

# 目录准备
RUN mkdir -p /var/log/supervisor /var/www/html/temp && chmod 777 /var/www/html/temp

WORKDIR /var/www/html

# 注入成品发布门禁固定使用已验证版本。依赖安装失败会直接阻断镜像构建，
# 避免生产 worker 在缺少 DEX 扫描能力时继续把任务标记为成功。
COPY bin/requirements-dex-gate.txt /tmp/requirements-dex-gate.txt
RUN python3 -m pip install --no-cache-dir --disable-pip-version-check \
    -r /tmp/requirements-dex-gate.txt

COPY . /var/www/html/
RUN chmod 777 /var/www/html/temp
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
