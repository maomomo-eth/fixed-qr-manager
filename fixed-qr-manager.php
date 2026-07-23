<?php
/**
 * Plugin Name: Fixed QR Manager
 * Description: 在后台管理二维码标题和内容，并通过固定 URL 输出二维码 PNG 图片或跳转链接。
 * Version: 1.1.0
 * Author: MAOMOMO
 * License: GPL-2.0-or-later
 * Text Domain: fixed-qr-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

final class Fixed_QR_Manager {
    // 所有二维码配置存放在一个 option 中，避免为小型插件额外建表。
    const OPTION_KEY = 'fqm_qr_items';
    // rewrite 规则会把 /qr/{slug}.png 或 /qr/{slug} 映射到这些 query vars。
    const QUERY_VAR  = 'fqm_qr_slug';
    const TYPE_VAR   = 'fqm_qr_type';
    const MENU_SLUG  = 'fixed-qr-manager';

    /**
     * 注册前台输出、后台管理和表单处理所需的 WordPress 钩子。
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
        add_filter( 'redirect_canonical', array( __CLASS__, 'disable_canonical_redirect' ), 10, 2 );
        add_action( 'parse_request', array( __CLASS__, 'serve_qr_image_early' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'serve_qr_image' ), 0 );

        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'generate_missing_qr_images' ) );
        add_action( 'admin_post_fqm_save_qr', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_fqm_delete_qr', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_fqm_refresh_qr', array( __CLASS__, 'handle_refresh' ) );
    }

    /**
     * 插件启用时写入二维码图片和跳转链接的 rewrite 规则。
     */
    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
        self::generate_missing_qr_images();
    }

    /**
     * 插件停用时刷新 rewrite 规则，移除 /qr/*.png 路由缓存。
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * 固定图片地址格式为 /qr/{slug}.png，跳转地址格式为 /qr/{slug}。
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule(
            '^qr/([^/]+)\.png/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::TYPE_VAR . '=image',
            'top'
        );
        add_rewrite_rule(
            '^qr/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::TYPE_VAR . '=redirect',
            'top'
        );
    }

    /**
     * 允许 WordPress 从 rewrite 规则中读取自定义 query var。
     *
     * @param array $vars 已注册的 query vars。
     * @return array
     */
    public static function add_query_vars( $vars ) {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::TYPE_VAR;
        return $vars;
    }

    /**
     * 在“设置”菜单下添加二维码管理页。
     */
    public static function add_admin_menu() {
        add_options_page(
            '固定二维码管理',
            '固定二维码',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    /**
     * 为已有二维码补齐 /qr/*.png 缓存文件。
     */
    public static function generate_missing_qr_images() {
        if ( is_admin() && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        foreach ( self::get_items() as $slug => $item ) {
            if ( empty( $item['content'] ) ) {
                continue;
            }

            $path = self::get_cache_path( $slug );
            if ( file_exists( $path ) && 0 < filesize( $path ) ) {
                continue;
            }

            self::generate_qr_png( $slug, $item['content'] );
        }
    }

    /**
     * 读取所有二维码记录。
     *
     * @return array<string,array<string,string>>
     */
    private static function get_items() {
        $items = get_option( self::OPTION_KEY, array() );
        return is_array( $items ) ? $items : array();
    }

    /**
     * 保存二维码记录，不自动加载到每个前台请求，减少 autoload 压力。
     *
     * @param array $items 二维码记录集合。
     */
    private static function update_items( $items ) {
        update_option( self::OPTION_KEY, $items, false );
    }

    /**
     * 按 slug 读取单条二维码记录。
     *
     * @param string $slug 固定 URL 标识。
     * @return array<string,string>|null
     */
    private static function get_item( $slug ) {
        $items = self::get_items();
        return isset( $items[ $slug ] ) && is_array( $items[ $slug ] ) ? $items[ $slug ] : null;
    }

    /**
     * 获取并确保公开二维码缓存目录存在。
     *
     * @return string
     */
    private static function get_cache_dir() {
        $dir = trailingslashit( ABSPATH ) . 'qr';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        return $dir;
    }

    /**
     * 根据 slug 生成公开二维码 PNG 缓存路径。
     *
     * @param string $slug 固定 URL 标识。
     * @return string
     */
    private static function get_cache_path( $slug ) {
        return trailingslashit( self::get_cache_dir() ) . sanitize_file_name( $slug ) . '.png';
    }

    /**
     * 删除单个二维码缓存，下次访问时会重新生成。
     *
     * @param string $slug 固定 URL 标识。
     */
    private static function delete_cache( $slug ) {
        $path = self::get_cache_path( $slug );
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    /**
     * 返回前台可直接引用的固定二维码图片 URL。
     *
     * @param string $slug 固定 URL 标识。
     * @return string
     */
    private static function fixed_url( $slug ) {
        return home_url( '/qr/' . rawurlencode( $slug ) . '.png' );
    }

    /**
     * 返回访问后直接跳转到二维码内容的固定 URL。
     *
     * @param string $slug 固定 URL 标识。
     * @return string
     */
    private static function redirect_link_url( $slug ) {
        return home_url( '/qr/' . rawurlencode( $slug ) );
    }

    /**
     * 统一生成后台管理页跳转地址，便于带状态消息返回。
     *
     * @param array<string,string> $extra 附加 query 参数。
     * @return string
     */
    private static function redirect_url( $extra = array() ) {
        return add_query_arg(
            array_merge(
                array( 'page' => self::MENU_SLUG ),
                $extra
            ),
            admin_url( 'options-general.php' )
        );
    }

    /**
     * 从当前请求路径中识别二维码图片或跳转链接，作为 rewrite 未刷新时的兜底。
     *
     * @return array{slug:string,type:string}
     */
    private static function get_request_route() {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return array( 'slug' => '', 'type' => '' );
        }

        $request_path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
        $home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

        if ( ! is_string( $request_path ) ) {
            return array( 'slug' => '', 'type' => '' );
        }

        $home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';
        $path      = trim( rawurldecode( $request_path ), '/' );

        if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }

        if ( preg_match( '#^qr/([^/]+)\.png/?$#', $path, $matches ) ) {
            return array( 'slug' => sanitize_title( $matches[1] ), 'type' => 'image' );
        }

        if ( preg_match( '#^qr/([^/]+)/?$#', $path, $matches ) ) {
            return array( 'slug' => sanitize_title( $matches[1] ), 'type' => 'redirect' );
        }

        return array( 'slug' => '', 'type' => '' );
    }

    /**
     * 二维码图片和跳转地址不走 WordPress canonical 跳转，避免路径被改写。
     *
     * @param string|false $redirect_url 规范化后的跳转地址。
     * @param string       $requested_url 当前请求地址。
     * @return string|false
     */
    public static function disable_canonical_redirect( $redirect_url, $requested_url ) {
        $route = self::get_request_route();
        if ( $route['slug'] ) {
            return false;
        }

        return $redirect_url;
    }

    /**
     * 在 WordPress 主查询判定 404 前接管二维码图片请求。
     *
     * @param WP $wp 当前请求对象。
     */
    public static function serve_qr_image_early( $wp ) {
        $slug = '';
        $type = '';

        if ( isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
            $slug = $wp->query_vars[ self::QUERY_VAR ];
        }

        if ( isset( $wp->query_vars[ self::TYPE_VAR ] ) ) {
            $type = $wp->query_vars[ self::TYPE_VAR ];
        }

        $route = self::get_request_route();
        if ( ! $slug ) {
            $slug = $route['slug'];
        }
        if ( ! $type ) {
            $type = $route['type'];
        }

        if ( $slug && 'redirect' === $type ) {
            self::redirect_by_slug( $slug );
        }

        if ( $slug && 'image' === $type ) {
            self::serve_qr_image_by_slug( $slug );
        }
    }

    /**
     * 处理新增或编辑二维码的后台表单提交。
     */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        check_admin_referer( 'fqm_save_qr' );

        $old_slug = isset( $_POST['old_slug'] ) ? sanitize_title( wp_unslash( $_POST['old_slug'] ) ) : '';
        $raw_slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        $title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $content  = isset( $_POST['content'] ) ? trim( wp_unslash( $_POST['content'] ) ) : '';

        if ( '' === $raw_slug || '' === $title || '' === $content ) {
            wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'missing' ) ) );
            exit;
        }

        $items = self::get_items();

        // 已存在的二维码不允许改 slug，保证输出 URL 不变。
        $slug = $old_slug && isset( $items[ $old_slug ] ) ? $old_slug : $raw_slug;

        if ( ! $old_slug && isset( $items[ $slug ] ) ) {
            wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'duplicate' ) ) );
            exit;
        }

        $items[ $slug ] = array(
            'slug'       => $slug,
            'title'      => $title,
            'content'    => $content,
            'updated_at' => current_time( 'mysql' ),
        );

        self::update_items( $items );
        self::delete_cache( $slug );
        $result = self::generate_qr_png( $slug, $content );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'generate_failed', 'edit' => $slug ) ) );
            exit;
        }

        wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'saved', 'edit' => $slug ) ) );
        exit;
    }

    /**
     * 处理后台删除二维码请求，同时清理本地缓存文件。
     */
    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        check_admin_referer( 'fqm_delete_qr' );

        $slug  = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
        $items = self::get_items();

        if ( $slug && isset( $items[ $slug ] ) ) {
            unset( $items[ $slug ] );
            self::update_items( $items );
            self::delete_cache( $slug );
        }

        wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'deleted' ) ) );
        exit;
    }

    /**
     * 手动刷新某个二维码缓存。
     */
    public static function handle_refresh() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        check_admin_referer( 'fqm_refresh_qr' );

        $slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : '';
        if ( $slug ) {
            self::delete_cache( $slug );
            $item = self::get_item( $slug );
            if ( $item ) {
                $result = self::generate_qr_png( $slug, $item['content'] );
                if ( is_wp_error( $result ) ) {
                    wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'generate_failed', 'edit' => $slug ) ) );
                    exit;
                }
            }
        }

        wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'refreshed', 'edit' => $slug ) ) );
        exit;
    }

    /**
     * 前台拦截 /qr/{slug}.png 请求，按需生成并输出 PNG 图片。
     */
    public static function serve_qr_image() {
        $slug = get_query_var( self::QUERY_VAR );
        $type = get_query_var( self::TYPE_VAR );
        $route = self::get_request_route();
        if ( ! $slug ) {
            $slug = $route['slug'];
        }
        if ( ! $type ) {
            $type = $route['type'];
        }

        if ( ! $slug || 'image' !== $type ) {
            return;
        }

        self::serve_qr_image_by_slug( $slug );
    }

    /**
     * 按 slug 将无扩展名地址以 302 跳转到二维码内容中的 HTTP(S) 链接。
     *
     * @param string $slug 固定 URL 标识。
     */
    private static function redirect_by_slug( $slug ) {
        $slug = sanitize_title( $slug );
        $item = self::get_item( $slug );

        if ( ! $item ) {
            status_header( 404 );
            nocache_headers();
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'QR not found';
            exit;
        }

        $target = esc_url_raw( trim( $item['content'] ), array( 'http', 'https' ) );
        $parts  = $target ? wp_parse_url( $target ) : false;
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
            status_header( 400 );
            nocache_headers();
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo '二维码内容不是有效的 HTTP(S) 链接，无法跳转。';
            exit;
        }

        nocache_headers();
        wp_redirect( $target, 302, 'Fixed QR Manager' );
        exit;
    }

    /**
     * 按 slug 输出二维码 PNG，并确保响应状态与图片内容一致。
     *
     * @param string $slug 固定 URL 标识。
     */
    private static function serve_qr_image_by_slug( $slug ) {
        $slug = sanitize_title( $slug );
        $item = self::get_item( $slug );

        if ( ! $item ) {
            status_header( 404 );
            nocache_headers();
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'QR not found';
            exit;
        }

        $path = self::get_cache_path( $slug );

        // 缓存不存在或为空文件时按需本地生成。
        if ( ! file_exists( $path ) || 0 === filesize( $path ) ) {
            $result = self::generate_qr_png( $slug, $item['content'] );
            if ( is_wp_error( $result ) ) {
                status_header( 502 );
                nocache_headers();
                header( 'Content-Type: text/plain; charset=utf-8' );
                echo esc_html( $result->get_error_message() );
                exit;
            }
        }

        global $wp_query;
        if ( $wp_query instanceof WP_Query ) {
            $wp_query->is_404 = false;
        }

        status_header( 200 );
        http_response_code( 200 );
        header( 'Content-Type: image/png' );
        header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $slug ) . '.png"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        readfile( $path );
        exit;
    }

    /**
     * 使用 chillerlan/php-qrcode 本地生成二维码 PNG，并写入站点根目录 /qr/。
     *
     * @param string $slug    固定 URL 标识。
     * @param string $content 二维码实际内容。
     * @return true|WP_Error
     */
    private static function generate_qr_png( $slug, $content ) {
        $path = self::get_cache_path( $slug );

        if ( ! class_exists( '\chillerlan\QRCode\QRCode' ) || ! class_exists( '\chillerlan\QRCode\QROptions' ) ) {
            return new WP_Error( 'fqm_qr_dependency_missing', '二维码生成依赖缺失，请确认插件 vendor 目录已完整上传。' );
        }

        if ( ! extension_loaded( 'gd' ) ) {
            return new WP_Error( 'fqm_qr_gd_missing', '二维码生成失败：服务器 PHP 未启用 gd 扩展。' );
        }

        $dir = self::get_cache_dir();
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            return new WP_Error( 'fqm_qr_save_failed', '二维码图片保存失败，请检查站点根目录 /qr/ 是否可写。' );
        }

        try {
            $options = new \chillerlan\QRCode\QROptions(
                array(
                    'outputType'    => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                    'eccLevel'      => \chillerlan\QRCode\QRCode::ECC_M,
                    'scale'         => 12,
                    'quietzoneSize' => 2,
                    'imageBase64'   => false,
                )
            );

            ( new \chillerlan\QRCode\QRCode( $options ) )->render( $content, $path );
        } catch ( Throwable $e ) {
            return new WP_Error( 'fqm_qr_failed', '二维码生成失败：' . $e->getMessage() );
        }

        if ( ! file_exists( $path ) || 0 === filesize( $path ) ) {
            return new WP_Error( 'fqm_qr_save_failed', '二维码图片保存失败，请检查站点根目录 /qr/ 是否可写。' );
        }

        return true;
    }

    /**
     * 根据跳转参数显示后台操作结果提示。
     */
    private static function admin_notice_message() {
        if ( empty( $_GET['fqm_message'] ) ) {
            return;
        }

        $message = sanitize_key( wp_unslash( $_GET['fqm_message'] ) );
        $map     = array(
            'saved'     => array( 'updated', '已保存，固定 URL 不变。' ),
            'deleted'   => array( 'updated', '已删除。' ),
            'refreshed' => array( 'updated', '已刷新二维码图片缓存。' ),
            'missing'   => array( 'error', '标题、slug、内容都不能为空。' ),
            'duplicate' => array( 'error', '这个 slug 已存在，请换一个。' ),
            'generate_failed' => array( 'error', '二维码图片生成失败，请检查 PHP gd 扩展、vendor 目录和 /qr/ 目录权限。' ),
        );

        if ( isset( $map[ $message ] ) ) {
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr( $map[ $message ][0] ),
                esc_html( $map[ $message ][1] )
            );
        }
    }

    /**
     * 渲染“设置 > 固定二维码”后台管理页面。
     */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        $items     = self::get_items();
        $edit_slug = isset( $_GET['edit'] ) ? sanitize_title( wp_unslash( $_GET['edit'] ) ) : '';
        $editing   = $edit_slug && isset( $items[ $edit_slug ] ) ? $items[ $edit_slug ] : null;

        self::admin_notice_message();
        ?>
        <div class="wrap">
            <h1>固定二维码管理</h1>
            <p>每个二维码都有固定图片 URL；当内容为 HTTP(S) 链接时，也可使用无扩展名 URL 直接 302 跳转。后续只改标题或内容，两个 URL 都不需要改。</p>

            <h2><?php echo $editing ? '编辑二维码' : '新增二维码'; ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 820px;">
                <?php wp_nonce_field( 'fqm_save_qr' ); ?>
                <input type="hidden" name="action" value="fqm_save_qr">
                <input type="hidden" name="old_slug" value="<?php echo esc_attr( $editing ? $editing['slug'] : '' ); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fqm-title">标题</label></th>
                        <td><input name="title" id="fqm-title" type="text" class="regular-text" value="<?php echo esc_attr( $editing ? $editing['title'] : '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fqm-slug">固定 URL 标识</label></th>
                        <td>
                            <input name="slug" id="fqm-slug" type="text" class="regular-text" value="<?php echo esc_attr( $editing ? $editing['slug'] : '' ); ?>" <?php disabled( (bool) $editing ); ?> required>
                            <?php if ( $editing ) : ?>
                                <input type="hidden" name="slug" value="<?php echo esc_attr( $editing['slug'] ); ?>">
                            <?php endif; ?>
                            <p class="description">只建议新建时填写，例如 <code>pingan-bank</code>。创建后锁定，避免固定 URL 变化。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fqm-content">二维码内容</label></th>
                        <td>
                            <textarea name="content" id="fqm-content" rows="6" class="large-text code" required><?php echo esc_textarea( $editing ? $editing['content'] : '' ); ?></textarea>
                            <p class="description">可以是网址、文本、联系方式等。若填写 HTTP(S) 链接，访问无扩展名 URL 会 302 跳转到该链接。</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( $editing ? '保存修改' : '新增二维码' ); ?>
                <?php if ( $editing ) : ?>
                    <a class="button" href="<?php echo esc_url( self::redirect_url() ); ?>">取消编辑</a>
                <?php endif; ?>
            </form>

            <hr>

            <h2>二维码列表</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>标题</th>
                        <th>固定图片 URL</th>
                        <th>跳转 URL</th>
                        <th>更新时间</th>
                        <th style="width:160px;">预览</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="6">还没有二维码。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $slug => $item ) : ?>
                            <?php
                            $url          = self::fixed_url( $slug );
                            $redirect_url = self::redirect_link_url( $slug );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $item['title'] ); ?></strong><br><code><?php echo esc_html( $slug ); ?></code></td>
                                <td>
                                    <input type="text" class="large-text code" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();">
                                </td>
                                <td>
                                    <input type="text" class="large-text code" readonly value="<?php echo esc_attr( $redirect_url ); ?>" onclick="this.select();">
                                </td>
                                <td><?php echo esc_html( isset( $item['updated_at'] ) ? $item['updated_at'] : '' ); ?></td>
                                <td><img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" style="width:120px;height:120px;border:1px solid #ccd0d4;background:#fff;"></td>
                                <td>
                                    <a class="button" href="<?php echo esc_url( self::redirect_url( array( 'edit' => $slug ) ) ); ?>">编辑</a>
                                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fqm_refresh_qr&slug=' . rawurlencode( $slug ) ), 'fqm_refresh_qr' ) ); ?>">刷新缓存</a>
                                    <a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fqm_delete_qr&slug=' . rawurlencode( $slug ) ), 'fqm_delete_qr' ) ); ?>" onclick="return confirm('确定删除这个二维码？');">删除</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

// 初始化插件，并在启用/停用时维护 rewrite 规则。
Fixed_QR_Manager::init();
register_activation_hook( __FILE__, array( 'Fixed_QR_Manager', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fixed_QR_Manager', 'deactivate' ) );
