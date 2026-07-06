<?php
/**
 * Plugin Name: Fixed QR Manager
 * Description: 在后台管理二维码标题和内容，并通过固定 URL 动态输出二维码 PNG 图片。
 * Version: 1.0.0
 * Author: MAOMOMO
 * License: GPL-2.0-or-later
 * Text Domain: fixed-qr-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Fixed_QR_Manager {
    const OPTION_KEY = 'fqm_qr_items';
    const QUERY_VAR  = 'fqm_qr_slug';
    const MENU_SLUG  = 'fixed-qr-manager';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'serve_qr_image' ) );

        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_post_fqm_save_qr', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_fqm_delete_qr', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_fqm_refresh_qr', array( __CLASS__, 'handle_refresh' ) );
    }

    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule(
            '^qr/([^/]+)\.png$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function add_admin_menu() {
        add_options_page(
            '固定二维码管理',
            '固定二维码',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    private static function get_items() {
        $items = get_option( self::OPTION_KEY, array() );
        return is_array( $items ) ? $items : array();
    }

    private static function update_items( $items ) {
        update_option( self::OPTION_KEY, $items, false );
    }

    private static function get_item( $slug ) {
        $items = self::get_items();
        return isset( $items[ $slug ] ) && is_array( $items[ $slug ] ) ? $items[ $slug ] : null;
    }

    private static function get_upload_dir() {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'fixed-qr-manager';
        $url    = trailingslashit( $upload['baseurl'] ) . 'fixed-qr-manager';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        return array(
            'dir' => $dir,
            'url' => $url,
        );
    }

    private static function get_cache_path( $slug ) {
        $upload = self::get_upload_dir();
        return trailingslashit( $upload['dir'] ) . sanitize_file_name( $slug ) . '.png';
    }

    private static function delete_cache( $slug ) {
        $path = self::get_cache_path( $slug );
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    private static function fixed_url( $slug ) {
        return home_url( '/qr/' . rawurlencode( $slug ) . '.png' );
    }

    private static function redirect_url( $extra = array() ) {
        return add_query_arg(
            array_merge(
                array( 'page' => self::MENU_SLUG ),
                $extra
            ),
            admin_url( 'options-general.php' )
        );
    }

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

        wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'saved', 'edit' => $slug ) ) );
        exit;
    }

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
                self::generate_qr_png( $slug, $item['content'] );
            }
        }

        wp_safe_redirect( self::redirect_url( array( 'fqm_message' => 'refreshed', 'edit' => $slug ) ) );
        exit;
    }

    public static function serve_qr_image() {
        $slug = get_query_var( self::QUERY_VAR );
        if ( ! $slug ) {
            return;
        }

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

        status_header( 200 );
        header( 'Content-Type: image/png' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        readfile( $path );
        exit;
    }

    private static function generate_qr_png( $slug, $content ) {
        $path = self::get_cache_path( $slug );

        $api_url = add_query_arg(
            array(
                'text'   => $content,
                'size'   => 512,
                'margin' => 2,
                'ecLevel'=> 'M',
                'format' => 'png',
            ),
            'https://quickchart.io/qr'
        );

        $response = wp_remote_get(
            $api_url,
            array(
                'timeout'     => 20,
                'redirection' => 2,
                'headers'     => array(
                    'Accept' => 'image/png',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( 200 !== (int) $code || empty( $body ) ) {
            return new WP_Error( 'fqm_qr_failed', '二维码生成失败，请检查服务器是否能访问 quickchart.io。' );
        }

        $saved = file_put_contents( $path, $body );
        if ( false === $saved ) {
            return new WP_Error( 'fqm_qr_save_failed', '二维码图片保存失败，请检查 uploads 目录权限。' );
        }

        return true;
    }

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
        );

        if ( isset( $map[ $message ] ) ) {
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr( $map[ $message ][0] ),
                esc_html( $map[ $message ][1] )
            );
        }
    }

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
            <p>每个二维码都有一个固定图片 URL。后续只改标题或内容，文章里引用的图片 URL 不需要改。</p>

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
                            <p class="description">可以是网址、文本、联系方式等。保存后旧 URL 输出新二维码。</p>
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
                        <th>更新时间</th>
                        <th style="width:160px;">预览</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="5">还没有二维码。</td></tr>
                    <?php else : ?>
                        <?php foreach ( $items as $slug => $item ) : ?>
                            <?php $url = self::fixed_url( $slug ); ?>
                            <tr>
                                <td><strong><?php echo esc_html( $item['title'] ); ?></strong><br><code><?php echo esc_html( $slug ); ?></code></td>
                                <td>
                                    <input type="text" class="large-text code" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();">
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

Fixed_QR_Manager::init();
register_activation_hook( __FILE__, array( 'Fixed_QR_Manager', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fixed_QR_Manager', 'deactivate' ) );
