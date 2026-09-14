# Dramatool 部署说明（Linux）

文字冒险游戏（AVG）在线编辑器网站。纯 PHP 静态站点，无第三方依赖。

## 一、环境要求

- PHP 8.0+（需启用 `json` 扩展，PHP 8 默认已内置）
- Web 服务器：Nginx 或 Apache
- 无需 MySQL（当前版本数据存于浏览器 localStorage）

检查 PHP 版本：

```bash
php -v
```

## 二、目录结构

```
public/                 # 网站根目录（DocumentRoot 指向此处）
├── index.php           # 首页
├── editor.php          # 编辑器页面
├── player.php          # 播放器页面
└── assets/
    ├── css/            # 样式
    ├── js/             # 脚本
    ├── bg/             # 背景素材
    ├── sprites/        # 立绘素材
    └── manifest.json   # 素材清单
storage/
└── uploads/            # 用户上传素材（在 public 之外，需 Nginx 映射为 /uploads/）
    └── {userId}/{type}/{yyyyMM}/{hash}.{ext}
```

> 用户上传的素材存放在 `storage/uploads/`，位于网站根目录之外，无法被直接访问；
> 通过 Nginx 的 `location /uploads/` 映射对外提供只读访问（见下文配置）。

## 三、部署步骤

### 1. 上传并解压

```bash
# 上传 dramatool-public.tar.gz 到服务器后
mkdir -p /var/www/dramatool
tar -xzf dramatool-public.tar.gz -C /var/www/dramatool
```

解压后 `/var/www/dramatool/public` 即为网站根目录。

### 2. 设置权限

```bash
chown -R www-data:www-data /var/www/dramatool
find /var/www/dramatool -type d -exec chmod 755 {} \;
find /var/www/dramatool -type f -exec chmod 644 {} \;

# 用户上传目录：需对 PHP-FPM 运行用户可写
mkdir -p /var/www/dramatool/storage/uploads
chown -R www-data:www-data /var/www/dramatool/storage
chmod -R 755 /var/www/dramatool/storage
```

> 若服务器用户组不是 `www-data`（如 CentOS 为 `nginx`），请相应替换。

### 3. 配置 Nginx

**第一步：确认 PHP-FPM 实际监听地址**（重要，不要硬编码路径）

Nginx 的 `fastcgi_pass` 必须与 PHP-FPM 实际 `listen` 的地址完全一致。先执行：

```bash
# 查看 PHP-FPM 池配置中的 listen 指令
grep -rh '^listen =' /etc/php-fpm.d/ /etc/php/*/fpm/pool.d/ 2>/dev/null
```

输出通常是以下两种之一：

- `listen = /run/php/php8.1-fpm.sock`（Unix Socket 模式）
- `listen = 127.0.0.1:9000`（TCP 端口模式）

**第二步：根据 listen 值编写 Nginx 配置**

新建 `/etc/nginx/conf.d/dramatool.conf`：

**情况 A：PHP-FPM 使用 Unix Socket**

```nginx
server {
    listen 80;
    server_name your-domain.com;   # 替换为你的域名或服务器 IP

    root /var/www/dramatool/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;  # 与 grep 结果完全一致
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # 用户上传素材：映射到 public 之外的 storage/uploads
    # 必须放在 .php 规则之后，且显式禁止 PHP 解析（防止上传文件被当作脚本执行）
    location /uploads/ {
        alias /var/www/dramatool/storage/uploads/;
        location ~ \.php$ { return 403; }
        expires 7d;
        add_header Cache-Control "public";
        add_header X-Content-Type-Options "nosniff";
    }

    # 静态资源缓存
    location ~* \.(css|js|svg|png|jpg|jpeg|gif|ico|woff2?)$ {
        expires 7d;
        add_header Cache-Control "public";
    }
}
```

**情况 B：PHP-FPM 使用 TCP 端口**

```nginx
server {
    listen 80;
    server_name your-domain.com;

    root /var/www/dramatool/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;   # 与 grep 结果完全一致
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # 用户上传素材：映射到 public 之外的 storage/uploads
    # 必须放在 .php 规则之后，且显式禁止 PHP 解析（防止上传文件被当作脚本执行）
    location /uploads/ {
        alias /var/www/dramatool/storage/uploads/;
        location ~ \.php$ { return 403; }
        expires 7d;
        add_header Cache-Control "public";
        add_header X-Content-Type-Options "nosniff";
    }

    location ~* \.(css|js|svg|png|jpg|jpeg|gif|ico|woff2?)$ {
        expires 7d;
        add_header Cache-Control "public";
    }
}
```

### 4. 重载 Nginx

```bash
nginx -t          # 测试配置
systemctl reload nginx
```

### 5. 访问

浏览器打开 `http://your-domain.com/` 即可。

## 四、Apache 部署（可选）

若使用 Apache，将 `public` 设为 DocumentRoot，并确保启用 `mod_php` 或 `php-fpm`：

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/dramatool/public

    <Directory /var/www/dramatool/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## 五、本地快速验证（无需 Nginx）

在服务器上直接用 PHP 内置服务器测试：

```bash
cd /var/www/dramatool/public
php -S 0.0.0.0:8000
```

浏览器访问 `http://服务器IP:8000/`。

> 注意：PHP 内置服务器仅用于测试，生产环境请使用 Nginx/Apache。
>
> 用户上传素材存放在 `public` 之外的 `storage/uploads/`，PHP 内置服务器无法直接访问 `/uploads/`。
> 本地验证上传功能时，请改用项目根目录下的启动脚本（会自动把 `/uploads/` 路由到 `storage/uploads/`），
> 或临时创建软链接：`ln -s ../storage/uploads public/uploads`（Windows 下用 `mklink /D`）。

## 六、常见问题

### Q: Nginx 报 502，错误日志提示 `connect() to unix:/xxx.sock failed (2: No such file or directory)`

**原因：PHP-FPM 的 Unix Socket 文件没有生成。** 按以下步骤排查修复：

**1. 确认 PHP-FPM 是否正在运行**

```bash
systemctl status php-fpm          # 或 php8.1-fpm，按实际服务名
systemctl restart php-fpm         # 重启试试
```

**2. 确认 PHP-FPM 实际 listen 地址**

```bash
grep -rh '^listen =' /etc/php-fpm.d/ /etc/php/*/fpm/pool.d/ 2>/dev/null
```

- 如果输出为 `listen = /run/php/php8.1-fpm.sock`，继续下一步。
- 如果输出为 `listen = 127.0.0.1:9000`，说明 PHP-FPM 用的是 TCP 端口，Nginx 也应改成 `fastcgi_pass 127.0.0.1:9000;`，不要继续用 socket。

**3. 检查 socket 目录是否存在并有写权限**

```bash
# 以 listen = /run/php/php8.1-fpm.sock 为例
ls -ld /run/php/
# 若目录不存在，创建并设置权限
mkdir -p /run/php
chown www-data:www-data /run/php   # 与 PHP-FPM 的 user/group 一致
```

> `/run` 是 tmpfs 临时文件系统，重启后会清空。如果重启后目录消失，需配置 systemd-tmpfiles 或在 php-fpm.service 中添加 `RuntimeDirectory=php`。

**4. 检查 PHP-FPM 池配置是否完整**

编辑对应的 `www.conf`（常见路径：`/etc/php-fpm.d/www.conf` 或 `/etc/php/8.1/fpm/pool.d/www.conf`），确保以下关键字段存在：

```ini
[www]
user = www-data
group = www-data
listen = /run/php/php8.1-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
```

> `listen.owner/group` 必须与 Nginx 运行用户一致（通常为 `www-data` 或 `nginx`），否则 Nginx 即使能看到 sock 文件也会因权限不足报 `(13: Permission denied)`。

**5. 重启 PHP-FPM 并验证 socket 生成**

```bash
systemctl restart php-fpm
ls -l /run/php/php8.1-fpm.sock   # 应能看到该文件
systemctl reload nginx
```

### Q: 不想用 Unix Socket，可以改成 TCP 端口吗？

可以。将 PHP-FPM 的 `www.conf` 中 `listen` 改为端口：

```ini
listen = 127.0.0.1:9000
```

然后重启 PHP-FPM，并将 Nginx 的 `fastcgi_pass` 改为：

```nginx
fastcgi_pass 127.0.0.1:9000;
```

> 注意：切换前确保 9000 端口未被占用，且不要在未确认 PHP-FPM 监听端口的情况下盲目改 Nginx。

### Q: 页面样式/素材加载失败？

确认 DocumentRoot 指向 `public` 目录，而非项目根目录。

### Q: 编辑器数据会丢失吗？

当前版本数据保存在浏览器 localStorage，清除浏览器数据会丢失。建议在编辑器中定期「导出 JSON」备份。

### Q: 端口被占用？

修改 Nginx `listen` 端口，或检查 `php -S` 的端口是否被占用。
