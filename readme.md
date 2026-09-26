# Fixed QR Manager

一个轻量级 WordPress 插件，用于在后台维护固定 URL 的二维码图片和跳转链接。

插件会把每个二维码绑定到固定图片地址：

```text
https://example.com/qr/{slug}.png
```

同一个标识也提供无扩展名跳转地址：

```text
https://example.com/qr/{slug}
```

当二维码内容为 HTTP(S) 链接时，二维码会编码为该固定跳转地址。访问或扫码后，插件优先使用浏览器 JavaScript 获取的 UA 判断微信内置浏览器；JavaScript UA 不可用时才回退到 PHP 获取的 UA。UA 包含 `MicroMessenger` 时显示“在浏览器打开”引导，其他浏览器自动跳转到二维码内容的链接。

后续只修改二维码内容时，文章、页面或外部渠道里引用的图片 URL 不需要更换。

## 功能

- 在 WordPress 后台“设置 > 固定二维码”中新增、编辑、删除二维码。
- 每个二维码使用固定 `slug` 生成固定 PNG 地址。
- 当二维码内容为 HTTP(S) 链接时，二维码和 `/qr/{slug}` 都会经过固定跳转入口；优先用 JavaScript UA 判断，JavaScript UA 不可用时回退到 PHP UA；UA 包含 `MicroMessenger` 时引导用户点击右上角菜单并选择“在浏览器打开”，其他浏览器自动跳转。
- 编辑已有二维码时锁定 `slug`，避免 URL 变化。
- 使用 `chillerlan/php-qrcode` 在本机生成 PNG，不依赖第三方二维码服务。
- 生成后的 PNG 缓存在站点根目录 `qr/`，真实文件路径为 `/qr/{slug}.png`。
- 后台列表提供二维码预览、固定 URL 复制、刷新缓存和删除操作。
- 对 `/qr/{slug}.png` 和 `/qr/{slug}` 增加请求路径兜底识别，降低 rewrite 未刷新导致 404 的概率。
- 支持通过 GitHub 正式 Release 在 WordPress 后台检查和安装插件更新。

## 安装

1. 将插件目录放入 WordPress 的 `wp-content/plugins/`。
2. 确认主文件路径类似：

   ```text
   wp-content/plugins/fixed-qr-manager/fixed-qr-manager.php
   ```

3. 在 WordPress 后台“插件”页面启用 `Fixed QR Manager`。
4. 确认插件目录中的 `vendor/` 已完整上传。
5. 确认插件目录中的 `lib/plugin-update-checker/` 已完整上传，用于后台更新检查。
6. 确认 PHP 已启用 `gd` 和 `mbstring` 扩展。
7. 启用后插件会刷新 rewrite 规则，正常情况下无需手动保存固定链接。

## 使用

1. 进入“设置 > 固定二维码”。
2. 填写标题、固定 URL 标识和二维码内容。
3. 保存后复制列表中的固定图片 URL。
4. 将该 URL 作为图片地址插入文章、页面或其他系统。

示例：

```text
slug: pingan-bank
固定图片 URL: https://example.com/qr/pingan-bank.png
跳转 URL: https://example.com/qr/pingan-bank
```

## 字段说明

- 标题：仅用于后台识别二维码。
- 固定 URL 标识：用于生成 `/qr/{slug}.png`，创建后会锁定。
- 二维码内容：可以是网址、文本或联系方式。内容为 HTTP(S) 链接时，二维码实际编码为本站的无扩展名固定 URL，以便扫码后识别微信内置浏览器；其他内容则直接编码原内容。

## 审查结果

当前代码整体较小，核心流程清晰，权限和基础安全处理基本完整：

- 后台管理入口使用 `manage_options` 权限限制。
- 新增、删除、刷新缓存均使用 WordPress nonce 校验。
- 后台输出使用 `esc_html()`、`esc_attr()`、`esc_url()`、`esc_textarea()` 做了转义。
- 前台二维码输出优先使用站点根目录 `qr/` 下的真实 PNG 文件；缺失时由插件兜底生成并输出。
- 二维码配置使用单个 option 保存，并关闭 autoload，适合小规模管理场景。

需要注意的限制：

- 生成 PNG 依赖 PHP `gd` 扩展；未启用时会生成失败。
- 插件目录必须包含 Composer 生成的 `vendor/`。
- WordPress 根目录必须允许创建或写入 `qr/` 目录。
- 插件没有卸载清理逻辑，删除插件时 option 和 `qr/` 缓存文件不会自动移除。

## 调试模式

如需确认微信内置浏览器传入的 User-Agent，可在 WordPress 的 `wp-config.php` 中加入以下配置（应放在“停止编辑”注释之前）：

```php
define( 'FQM_DEBUG_MODE', true );
```

启用后，微信打开 `/qr/{slug}` 时的引导页会同时显示 PHP 获取的 UA、JavaScript 获取的 UA，以及实际采用的判断来源。排查完成后删除该配置或改为 `false`，避免对访客公开浏览器信息。

## 故障排查

如果访问 `/qr/{slug}.png` 返回 404：

1. 确认插件已经启用。
2. 到“设置 > 固定链接”点击一次“保存更改”，手动刷新 rewrite 规则。
3. 确认二维码列表里存在对应 `slug`。
4. 如果响应是 HTML 页面，并且地址被跳转到 `/qr/{slug}.png/`，请更新到当前版本，插件会提前接管二维码图片请求并禁用这类 canonical 跳转。

如果提示二维码生成失败：

1. 确认 PHP 已启用 `gd` 和 `mbstring` 扩展。
2. 确认插件目录中的 `vendor/` 已完整上传。
3. 尝试缩短二维码内容后重新保存。

如果提示图片保存失败：

1. 检查 WordPress 站点根目录是否可写。
2. 检查 `qr/` 是否可创建或可写。

## 更新发布

插件通过 GitHub 正式 Release 向 WordPress 后台提供更新：

1. 更新 `fixed-qr-manager.php` 插件头中的 `Version`。
2. 打包 ZIP 时确保根目录名为 `fixed-qr-manager`，并包含 `vendor/` 和 `lib/plugin-update-checker/`。
3. 在 GitHub 创建非 pre-release 的正式 Release。
4. 上传附件，文件名必须是 `fixed-qr-manager.zip`。

更新器只检查最新正式 Release，并要求 Release 带有这个 ZIP 附件；没有附件或附件名不匹配时，WordPress 后台不会安装更新。

## 开发说明

主要逻辑集中在 `fixed-qr-manager.php`：

- `init()`：注册前台路由、后台菜单和表单处理钩子。
- `add_rewrite_rules()`：注册 `/qr/{slug}.png` 图片和 `/qr/{slug}` 跳转地址。
- `serve_qr_image()`：前台输出二维码 PNG。
- `generate_qr_png()`：使用 `chillerlan/php-qrcode` 本地生成并缓存图片。
- `render_admin_page()`：渲染后台管理页面。
- `includes/class-fixed-qr-manager-github-updater.php`：接入 GitHub Release 更新检查。

## 兼容性

建议运行环境：

- WordPress 5.8 或更新版本。
- PHP 7.4 或更新版本。
- PHP 已启用 `gd` 和 `mbstring` 扩展。
- 服务器允许写入 WordPress 根目录下的 `qr/` 目录。
