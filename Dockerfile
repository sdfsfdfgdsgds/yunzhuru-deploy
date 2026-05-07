FROM php:7.4-cli-bullseye

# 系统依赖（含 Android 注入工具链）
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev \
    supervisor \
    aapt zipalign default-jre-headless \
    curl \
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
COPY . /var/www/html/
RUN chmod 777 /var/www/html/temp
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8080
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
