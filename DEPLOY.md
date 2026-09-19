# Dramatool 部署说明（Linux）

文字冒险游戏（AVG）在线编辑器网站。纯 PHP 应用，无第三方 Composer 依赖。

## 一、环境要求

- PHP 8.0+，需启用扩展：`pdo_mysql`、`mbstring`、`json`、`gd`（头像缩放与封面缩略图）、`fileinfo`（上传 MIME 校验）
- MySQL 5.7+ / MariaDB 10.3+
- Web 服务器：Nginx 或 Apache
- 可写目录：`storage/`（会话、上传、日志、邮件）

检查 PHP 版本与扩展：

```bash
php -v
php -m | grep -E 'pdo_mysql|mbstring|gd|fileinfo'
```

## 二、目录结构

```
app/                    # 应用代码（控制器、模型、服务、视图）
config/
├── config.php          # 全局配置（含默认值）
├── local.php.example   # 本地配置示例（复制为 local.php 后填写真实值）
└── local.php           # 本地配置（不入版本库，需手动创建）
public/                 # 网站根目录（DocumentRoot 指向此处）
├── index.php           # 统一入口（front controller）
├── .htaccess           # Apache 重写规则
└── assets/             # 静态资源（css / js / manifest.json）
sql/
└── schema.sql          # 建表脚本
storage/                # 运行时数据（在 public 之外，不可直接访问）
├── sessions/           # 会话文件
├── uploads/            # 用户上传素材与头像
├── logs/               # 应用日志
└── mail/               # log 驱动下的邮件记录
tools/
├── migrate.php         # 建表 + 多租户 site 列迁移脚本
└── package.ps1         # 打包脚本（Windows）
```

> 所有请求经 `public/index.php` 进入应用，路由定义在 `app/routes.php`。
> 用户上传的素材存放在 `storage/uploads/`，位于网站根目录之外，无法被直接访问；
> 通过 Nginx 的 `location /uploads/` 映射对外提供只读访问（见下文配置）。

## 三、打包

在开发机（Windows）项目根目录执行：

```powershell
powershell -ExecutionPolicy Bypass -File tools/package.ps1
```

产物为 `dist/dramatool-<时间戳>.tar.gz`，已自动排除：

- `config/local.php`（本地配置，含数据库密码）
- `storage/` 下的运行时数据（会话、上传、日志、邮件）
- `.git`、IDE 配置、`node_modules` 等开发文件

打包时会生成 `app/blockword.txt` 占位文件（敏感词词库不入版本库，需在服务器上自行维护）。

## 四、部署步骤

### 1. 上传并解压

```bash
# 上传 dramatool-<时间戳>.tar.gz 到服务器后
mkdir -p /var/www/dramatool
tar -xzf dramatool-<时间戳>.tar.gz -C /var/www/dramatool --strip-components=1
```

解压后 `/var/www/dramatool/public` 即为网站根目录。

### 2. 创建本地配置

```bash
cd /var/www/dramatool
cp config/local.php.example config/local.php
```

编辑 `config/local.php`，至少填写数据库连接信息：

```php
return [
    'app' => [
        'env'   => 'production',   // 生产环境务必为 production
        'debug' => false,          // 生产环境务必为 false
        'url'   => 'https://your-domain.com',
        'site'  => 'main',         // 站点标识（多租户隔离键），见「八、多租户部署」
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'dramatool',
        'username' => 'dramatool',
        'password' => '你的数据库密码',
    ],
    'mail' => [
        'driver' => 'smtp',        // 生产环境建议 smtp；log 仅写日志不发信
        'from'   => [
            'address' => 'noreply@your-domain.com',
            'name'    => 'Dramatool',
        ],
        'smtp'   => [
            'host'       => 'smtp.your-domain.com',
            'port'       => 465,
            'encryption' => 'ssl', // ssl / starttls / none
            'username'   => '',
            'password'   => '',
            'timeout'    => 10,
        ],
    ],
];
```

> `config/local.php` 已被 `.gitignore` 忽略，不会提交到版本库。
> 未配置的项会回落到 `config/config.php` 中的默认值。

### 3. 初始化数据库

```bash
# 创建数据库与账号（示例）
mysql -u root -p -e "CREATE DATABASE dramatool DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 执行建表（读取 config/local.php 中的连接信息）
php tools/migrate.php
```

脚本会输出建表结果与当前库中的表清单。若只想预览将执行的 SQL：

```bash
php tools/migrate.php --dry-run
```

> 建表语句均为 `CREATE TABLE IF NOT EXISTS`，可重复执行。
> 也可直接用 `mysql -u root -p dramatool < sql/schema.sql`。
>
> 脚本分两阶段执行：阶段 1 建表；阶段 2 为隔离表补加 `site` 列并重建索引（幂等，已存在则跳过）。
> 详见「八、多租户部署」。

### 4. 设置权限

```bash
chown -R www-data:www-data /var/www/dramatool
find /var/www/dramatool -type d -exec chmod 755 {} \;
find /var/www/dramatool -type f -exec chmod 644 {} \;

# 运行时目录：需对 PHP-FPM 运行用户可写
chown -R www-data:www-data /var/www/dramatool/storage
chmod -R 775 /var/www/dramatool/storage
```

> 若服务器用户组不是 `www-data`（如 CentOS 为 `nginx`），请相应替换。

###a 5. 维护敏感词词库

`app/blockword.txt` 每行一个词条，以 `#` 开头为注释行：

```
# 示例
违禁词A
测试\pP*内容
```

- 词条按 PCRE 正则处理，`\pP*` 表示「任意标点零次或多次」
- 词库中的反斜杠若被转义过一层（如 `\\n`），载入时会自动反转义
- 该文件不入版本库，需在服务器上单独维护；修改后无需重启，下次请求即生效

### 6. 配置 Nginx

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

### 7. 重载 Nginx

```bash
nginx -t          # 测试配置
systemctl reload nginx
```

### 8. 访问

浏览器打开 `http://your-domain.com/` 即可。

首个注册用户如需管理员权限，手动提升：

```sql
UPDATE users SET role = 'admin' WHERE email = 'your@email.com';
```

## 五、Apache 部署（可选）

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

`public/.htaccess` 已包含重写规则，会把不存在的路径统一交给 `index.php`。

## 六、本地快速验证（无需 Nginx）

在项目根目录启动 PHP 内置服务器：

```bash
php -S 127.0.0.1:8000 -t public
```

浏览器访问 `http://127.0.0.1:8000/`。

> 注意：PHP 内置服务器仅用于测试，生产环境请使用 Nginx/Apache。
>
> 用户上传素材存放在 `public` 之外的 `storage/uploads/`，PHP 内置服务器无法直接访问 `/uploads/`。
> 应用已内置兜底路由（`GET /uploads/{path}`）代为输出，因此本地验证上传功能无需额外配置。

## 七、多租户部署（多分支共用一套数据库）

同一台服务器上部署多个分支（如 `main`、`asha`）时，可让它们**共用同一个数据库**：
用户账号、会话等身份数据全局共享（一次注册，各站通用），而作品、素材、评论等业务数据按站点隔离。

### 1. 隔离原理

- **共享表**（不加 `site`，全站通用）：`users`、`sessions`、`password_resets`、`email_verifications`、`register_attempts`
- **隔离表**（带 `site` 列，按站点过滤）：`works`、`work_revisions`、`work_assets`、`likes`、`comments`、`favorites`、`reports`、`announcements`

隔离表的所有查询、写入、更新、删除均由 `App\Core\TenantScoped` trait 自动附加 `site = ?` 条件，
跨表 JOIN 也显式带 `x.site = y.site`，避免跨站数据串联。

### 2. 站点标识配置

每个部署实例在 `config/local.php` 中设置唯一的 `app.site`：

```php
// /var/www/dramatool-main/config/local.php
'app' => [
    'site' => 'main',
    // ...
],
```

```php
// /var/www/dramatool-asha/config/local.php
'app' => [
    'site' => 'asha',
    // ...
],
```

约束：

- 长度不超过 32 字符（超出会被截断），建议使用 `[a-z0-9_-]`
- 未配置时回落为 `default`
- **同一实例的 `site` 一旦确定不可随意更改**，否则会读不到既有数据

### 3. 部署步骤

各实例的目录、Nginx `server_name`、`app.url` 均不同，但 `db` 配置指向同一个库：

```bash
# 实例 1（main 分支）
mkdir -p /var/www/dramatool-main
tar -xzf dramatool-main-<时间戳>.tar.gz -C /var/www/dramatool-main --strip-components=1
cp /var/www/dramatool-main/config/local.php.example /var/www/dramatool-main/config/local.php
# 编辑 local.php：site = 'main'，db 指向共享库

# 实例 2（asha 分支）
mkdir -p /var/www/dramatool-asha
tar -xzf dramatool-asha-<时间戳>.tar.gz -C /var/www/dramatool-asha --strip-components=1
cp /var/www/dramatool-asha/config/local.php.example /var/www/dramatool-asha/config/local.php
# 编辑 local.php：site = 'asha'，db 指向同一个共享库
```

Nginx 为每个实例配置独立的 `server` 块（`server_name` 与 `root` 不同，`fastcgi_pass` 相同）：

```nginx
server {
    listen 80;
    server_name main.your-domain.com;
    root /var/www/dramatool-main/public;
    # ... 其余同「六、配置 Nginx」
}

server {
    listen 80;
    server_name asha.your-domain.com;
    root /var/www/dramatool-asha/public;
    # ... 其余同「六、配置 Nginx」
}
```

> 两个实例的 `storage/` 目录相互独立，上传素材不会互相覆盖。
> 若希望素材也共享，需自行将 `storage/uploads` 指向同一目录，但此时素材记录仍按 `site` 隔离。

### 4. 新增站点

1. 部署新实例目录，`config/local.php` 中设置新的 `app.site`（如 `beta`）
2. 执行 `php tools/migrate.php`（幂等，不会影响既有站点数据）
3. 配置 Nginx `server` 块并重载

无需为新站点单独建库或建表。

## 八、既有 main 库迁移为多租户

若 `main` 分支已在生产运行、库中已有数据，升级到多租户版本时按以下步骤操作。

### 1. 备份数据库（务必先做）

```bash
mysqldump -u dramatool -p --single-transaction --routines --triggers dramatool > dramatool-backup-$(date +%F).sql
```

### 2. 确认 main 实例的 site 值

编辑 `/var/www/dramatool-main/config/local.php`，显式设置：

```php
'app' => [
    'site' => 'main',
    // ...
],
```

> 迁移脚本会用该值回填既有数据。**必须先设置再执行迁移**，否则历史数据会被标记为 `default`。

### 3. 预览迁移内容

```bash
cd /var/www/dramatool-main
php tools/migrate.php --dry-run
```

输出会列出：当前 `app.site`、每张隔离表将添加的 `site` 列、将删除的旧索引、将新建的索引。

### 4. 执行迁移

```bash
php tools/migrate.php
```

脚本对每张隔离表依次执行：

1. 检测 `site` 列是否存在，缺失则 `ADD COLUMN site VARCHAR(32) NOT NULL DEFAULT '' AFTER id`
2. 将既有行回填为当前 `app.site`（`UPDATE ... SET site = ? WHERE site = ''`）
3. 删除旧索引（如 `uk_works_short_code`、`idx_works_user_status`）
4. 新建带 `site` 前缀的索引（如 `uk_works_site_short_code`、`idx_works_site_user_status`）

> 全部步骤均基于 `INFORMATION_SCHEMA` 做存在性检测，**可重复执行**：
> 已迁移过的表会输出「site 列已存在，跳过」，不会重复加列或报错。

### 5. 验证

```bash
# 确认各隔离表 site 列已回填，且无空值
mysql -u dramatool -p dramatool -e "
  SELECT 'works' AS t, site, COUNT(*) FROM works GROUP BY site
  UNION ALL SELECT 'work_assets', site, COUNT(*) FROM work_assets GROUP BY site
  UNION ALL SELECT 'comments', site, COUNT(*) FROM comments GROUP BY site;"

# 确认索引已重建
mysql -u dramatool -p dramatool -e "SHOW INDEX FROM works;"
```

预期：所有隔离表的 `site` 均为 `main`，且不存在 `site = ''` 的行。

### 6. 部署 asha 实例

按「七、多租户部署」部署 `asha` 实例，`app.site` 设为 `asha`，`db` 指向同一库。
asha 实例首次执行 `php tools/migrate.php` 时，因 `site` 列已存在会直接跳过，不会改动 main 的数据。

### 7. 回滚

若迁移异常，用备份恢复：

```bash
mysql -u dramatool -p dramatool < dramatool-backup-<日期>.sql
```

> 回滚后需同时将代码回退到迁移前的版本，否则新代码会因缺少 `site` 列而报错。

## 九、常见问题

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

### Q: 页面报「数据库连接失败」？

确认 `config/local.php` 已创建且数据库信息正确，并检查 MySQL 是否允许该账号从本机连接：

```bash
php -r "require 'config/config.php';" # 语法自检
mysql -u dramatool -p -h 127.0.0.1 dramatool -e "SHOW TABLES;"
```

### Q: 上传素材失败 / 头像无法保存？

确认 `storage/uploads/` 存在且对 PHP-FPM 运行用户可写：

```bash
ls -ld storage/uploads
chown -R www-data:www-data storage
```

同时确认 PHP 已启用 `gd` 与 `fileinfo` 扩展（`php -m`）。

### Q: 收不到验证邮件 / 重置密码邮件？

- 若 `mail.driver` 为 `log`，邮件只会写入 `storage/mail/` 日志，不会真实发送。生产环境请改为 `smtp` 并填写正确的 SMTP 账号。
- 检查 `storage/mail/` 或 `storage/logs/` 下的日志确认发送结果。

### Q: 页面样式/素材加载失败？

确认 DocumentRoot 指向 `public` 目录，而非项目根目录。

### Q: 端口被占用？

修改 Nginx `listen` 端口，或检查 `php -S` 的端口是否被占用。

### Q: 多站点部署后，A 站看不到自己的数据 / 看到了 B 站的数据？

按顺序排查：

1. 确认各实例 `config/local.php` 中的 `app.site` 已设置且互不相同
2. 确认各实例 `db` 配置指向同一个库（共享登录的前提）
3. 检查隔离表数据归属：

```bash
mysql -u dramatool -p dramatool -e "SELECT site, COUNT(*) FROM works GROUP BY site;"
```

4. 若存在 `site = ''` 的历史数据，说明迁移时未设置 `app.site`，需手动回填：

```sql
UPDATE works SET site = 'main' WHERE site = '';
-- 其余隔离表同理
```

### Q: 迁移脚本报 `Duplicate entry` 或索引已存在？

说明该表此前已部分迁移过。脚本对索引做了存在性检测，正常不会重复创建；
若手工执行过 `ALTER` 导致索引名冲突，先确认现有索引：

```bash
mysql -u dramatool -p dramatool -e "SHOW INDEX FROM works;"
```

再按需删除残留的旧索引后重新执行 `php tools/migrate.php`。
