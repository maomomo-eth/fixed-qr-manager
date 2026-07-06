# Fixed QR Manager

一个轻量级 WordPress 插件，用于在后台维护固定 URL 的二维码图片。

插件会把每个二维码绑定到固定图片地址：

```text
https://example.com/qr/{slug}.png
```

后续只修改二维码内容时，文章、页面或外部渠道里引用的图片 URL 不需要更换。

## 功能

- 在 WordPress 后台“设置 > 固定二维码”中新增、编辑、删除二维码。
- 每个二维码使用固定 `slug` 生成固定 PNG 地址。
- 编辑已有二维码时锁定 `slug`，避免 URL 变化。
- 首次访问或刷新缓存时调用 `quickchart.io` 生成 PNG。
- 生成后的 PNG 缓存在 `wp-content/uploads/fixed-qr-manager/`。
- 后台列表提供二维码预览、固定 URL 复制、刷新缓存和删除操作。
- 对 `/qr/{slug}.png` 增加请求路径兜底识别，降低 rewrite 未刷新导致 404 的概率。

## 安装

1. 将插件目录放入 WordPress 的 `wp-content/plugins/`。
2. 确认主文件路径类似：

   ```text
   wp-content/plugins/fixed-qr-manager/fixed-qr-manager.php
   ```

3. 在 WordPress 后台“插件”页面启用 `Fixed QR Manager`。
4. 启用后插件会刷新 rewrite 规则，正常情况下无需手动保存固定链接。

## 使用

1. 进入“设置 > 固定二维码”。
2. 填写标题、固定 URL 标识和二维码内容。
3. 保存后复制列表中的固定图片 URL。
4. 将该 URL 作为图片地址插入文章、页面或其他系统。

示例：

```text
slug: pingan-bank
固定图片 URL: https://example.com/qr/pingan-bank.png
```

## 字段说明

- 标题：仅用于后台识别二维码。
- 固定 URL 标识：用于生成 `/qr/{slug}.png`，创建后会锁定。
- 二维码内容：实际编码进二维码的内容，可以是网址、文本或联系方式。

## 审查结果

当前代码整体较小，核心流程清晰，权限和基础安全处理基本完整：

- 后台管理入口使用 `manage_options` 权限限制。
- 新增、删除、刷新缓存均使用 WordPress nonce 校验。
- 后台输出使用 `esc_html()`、`esc_attr()`、`esc_url()`、`esc_textarea()` 做了转义。
- 前台二维码输出通过 rewrite 规则接管，不直接暴露真实缓存文件路径。
- 二维码配置使用单个 option 保存，并关闭 autoload，适合小规模管理场景。

需要注意的限制：

- 生成二维码依赖 `quickchart.io`，服务器无法访问外网时会生成失败。
- 二维码内容通过 URL query 传给第三方服务，敏感内容不建议使用。
- 内容很长时可能触发远程服务或服务器 URL 长度限制。
- 刷新缓存接口目前不会把远程生成失败单独提示给后台用户。
- 插件没有卸载清理逻辑，删除插件时 option 和 uploads 缓存目录不会自动移除。

## 故障排查

如果访问 `/qr/{slug}.png` 返回 404：

1. 确认插件已经启用。
2. 到“设置 > 固定链接”点击一次“保存更改”，手动刷新 rewrite 规则。
3. 确认二维码列表里存在对应 `slug`。
4. 如果响应是 HTML 页面，并且地址被跳转到 `/qr/{slug}.png/`，请更新到当前版本，插件会提前接管二维码图片请求并禁用这类 canonical 跳转。

如果提示二维码生成失败：

1. 确认服务器可以访问 `https://quickchart.io/qr`。
2. 检查 WordPress 是否允许发起外部 HTTP 请求。
3. 尝试缩短二维码内容后重新保存。

如果提示图片保存失败：

1. 检查 `wp-content/uploads/` 是否可写。
2. 检查 `wp-content/uploads/fixed-qr-manager/` 是否可创建或可写。

## 开发说明

主要逻辑集中在 `fixed-qr-manager.php`：

- `init()`：注册前台路由、后台菜单和表单处理钩子。
- `add_rewrite_rules()`：注册 `/qr/{slug}.png` 固定图片地址。
- `serve_qr_image()`：前台输出二维码 PNG。
- `generate_qr_png()`：调用第三方接口生成并缓存图片。
- `render_admin_page()`：渲染后台管理页面。

## 兼容性

建议运行环境：

- WordPress 5.8 或更新版本。
- PHP 7.4 或更新版本。
- 服务器允许写入 WordPress uploads 目录。
- 服务器允许访问 `quickchart.io`。
