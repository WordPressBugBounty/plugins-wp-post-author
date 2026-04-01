<?php
/**
 * AWPA Local Avatars
 *
 * Media-Library-only local avatar handling for WP Post Author.
 *
 * @package WP_Post_Author
 * @subpackage Local_Avatars
 * @since 3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWPA_Local_Avatars' ) ) :

class AWPA_Local_Avatars {

    /**
     * Singleton instance.
     *
     * @var AWPA_Local_Avatars|null
     */
    private static $instance = null;

    /**
     * Meta key where avatar data is stored.
     *
     * @var string
     */
    private $meta_key = 'awpa_local_avatar';

    /**
     * Nonce action strings.
     *
     * @var string
     */
    private $nonce_remove_action = 'awpa_remove_local_avatar';
    private $nonce_assign_action = 'awpa_assign_local_avatar';
    private $nonce_save_action   = 'awpa_local_avatar_save';

    /**
     * Init the singleton.
     *
     * @param array $args Optional config.
     * @return AWPA_Local_Avatars
     */
    public static function init( $args = array() ) {
        if ( null === self::$instance ) {
            self::$instance = new self( $args );
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct( $args = array() ) {
        if ( ! empty( $args['meta_key'] ) ) {
            $this->meta_key = sanitize_key( $args['meta_key'] );
        }

        // Hooks.
        add_filter( 'pre_get_avatar_data', array( $this, 'filter_pre_get_avatar_data' ), 10, 2 );
        add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
        add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
        add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_awpa_assign_local_avatar_media', array( $this, 'ajax_assign_local_avatar_media' ) );
        add_action( 'wp_ajax_awpa_remove_local_avatar', array( $this, 'ajax_remove_local_avatar' ) );

        // Fallback remove handler
        add_action( 'admin_post_awpa_remove_local_avatar', array( $this, 'action_remove_local_avatar' ) );
    }

    /**
     * Filter avatar data early.
     */
    public function filter_pre_get_avatar_data( $args, $id_or_email ) {
        if ( ! empty( $args['force_default'] ) ) {
            return $args;
        }

        $user_id = $this->get_user_id_from_mixed( $id_or_email );
        if ( empty( $user_id ) ) {
            return $args;
        }

        $local = $this->get_user_local_avatar( $user_id );
        if ( empty( $local ) || empty( $local['full'] ) ) {
            return $args;
        }

        $size = isset( $args['size'] ) ? (int) $args['size'] : 96;
        $url  = $this->get_avatar_url_for_size( $user_id, $local, $size );

        if ( $url ) {
            $args['url'] = $url;
            $args['found_avatar'] = true;

            if ( empty( $args['alt'] ) ) {
                $args['alt'] = $this->get_avatar_alt_for_user( $user_id );
            }
        }

        return $args;
    }

    /**
     * Helper to get User ID.
     */
    private function get_user_id_from_mixed( $id_or_email ) {
        if ( is_numeric( $id_or_email ) ) {
            return (int) $id_or_email;
        }
        if ( $id_or_email instanceof WP_User ) {
            return (int) $id_or_email->ID;
        }
        if ( $id_or_email instanceof WP_Post && ! empty( $id_or_email->post_author ) ) {
            return (int) $id_or_email->post_author;
        }
        if ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
            return (int) $id_or_email->user_id;
        }
        if ( is_string( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            return $user ? (int) $user->ID : false;
        }
        return false;
    }

    /**
     * Get local avatar meta.
     */
    public function get_user_local_avatar( $user_id ) {
        $local = get_user_meta( $user_id, $this->meta_key, true );
        return is_array( $local ) ? $local : array();
    }

    /**
     * Get avatar alt text.
     */
    private function get_avatar_alt_for_user( $user_id ) {
        $default_alt = __( 'Avatar', 'wp-post-author' );
        $local = $this->get_user_local_avatar( $user_id );
        if ( ! empty( $local['media_id'] ) ) {
            $alt = get_post_meta( $local['media_id'], '_wp_attachment_image_alt', true );
            if ( $alt ) {
                return $alt;
            }
        }
        return $default_alt;
    }

    /**
     * Lazily generate resized avatar URL.
     */
    private function get_avatar_url_for_size( $user_id, $local_meta, $size ) {
        $size = (int) $size;

        if ( ! empty( $local_meta[ $size ] ) ) {
            return esc_url_raw( $local_meta[ $size ] );
        }

        $upload_dir = wp_upload_dir();

        if ( ! empty( $local_meta['media_id'] ) ) {
            $path = get_attached_file( $local_meta['media_id'] );
        } else {
            if ( empty( $local_meta['full'] ) ) {
                return false;
            }
            $path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $local_meta['full'] );
        }

        if ( ! $path || ! file_exists( $path ) ) {
            return false;
        }

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $resized = $editor->resize( $size, $size, true );
        if ( is_wp_error( $resized ) ) {
            return false;
        }

        $dest_file = $editor->generate_filename();
        $saved     = $editor->save( $dest_file );

        if ( is_wp_error( $saved ) ) {
            return false;
        }

        $dest_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $dest_file );
        
        $local_meta[ $size ] = $dest_url;
        update_user_meta( $user_id, $this->meta_key, $local_meta );

        return esc_url_raw( $dest_url );
    }

    /**
     * Render the UI on profile pages.
     */
    public function render_profile_field( $profileuser ) {
        $user_id = $profileuser->ID;
        $local   = $this->get_user_local_avatar( $user_id );

        if ( ! empty( $local['full'] ) ) {
            $avatar_html = sprintf(
                '<img src="%1$s" style="width:100px;height:100px;object-fit:cover;border-radius:4px" alt="%2$s" />',
                esc_url( $local['full'] ),
                esc_attr( $this->get_avatar_alt_for_user( $user_id ) )
            );
        } else {
            $avatar_html = get_avatar( $user_id, 100 );
        }

        $remove_nonce = wp_create_nonce( $this->nonce_remove_action );
        $assign_nonce = wp_create_nonce( $this->nonce_assign_action );
        ?>
        <h2><?php esc_html_e( 'Local Avatar', 'wp-post-author' ); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Avatar', 'wp-post-author' ); ?></th>
                <td>
                    <div style="display:flex;align-items:center;gap:16px;">
                        <div id="awpa-local-avatar-preview"><?php echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

                        <div>
                            <?php wp_nonce_field( $this->nonce_save_action, '_awpa_local_avatar_nonce' ); ?>

                            <?php if ( current_user_can( 'upload_files' ) ) : ?>
                                <button type="button" class="button button-primary" id="awpa-local-avatar-media"><?php esc_html_e( 'Set Local Avatar', 'wp-post-author' ); ?></button>
                                <input type="hidden" id="awpa-local-avatar-media-id" name="awpa_local_avatar_media_id" value="<?php echo ! empty( $local['media_id'] ) ? esc_attr( $local['media_id'] ) : ''; ?>"/>
                            <?php endif; ?>

                            <?php if ( ! empty( $local['full'] ) ) : ?>
                                <button type="button" class="button awpa-local-avatar-remove" id="awpa-local-avatar-remove" data-user-id="<?php echo esc_attr( $user_id ); ?>" data-nonce="<?php echo esc_attr( $remove_nonce ); ?>">
                                    <?php esc_html_e( 'Remove Local Avatar', 'wp-post-author' ); ?>
                                </button>

                                <noscript>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-top:8px;">
                                        <input type="hidden" name="action" value="awpa_remove_local_avatar" />
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
                                        <?php wp_nonce_field( $this->nonce_remove_action, '_wpnonce' ); ?>
                                        <button type="submit" class="button"><?php esc_html_e( 'Remove Local Avatar', 'wp-post-author' ); ?></button>
                                    </form>
                                </noscript>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e( 'Local avatars are used instead of Gravatar when present.', 'wp-post-author' ); ?></p>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
        <div id="awpa-local-avatar-js-bridge" data-assign-nonce="<?php echo esc_attr( $assign_nonce ); ?>" data-remove-nonce="<?php echo esc_attr( $remove_nonce ); ?>"></div>
        <?php
    }

    /**
     * Save the field with strict security.
     */
    public function save_profile_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        // 🚨 SECURITY FIX: Check nonce before saving
        if ( ! isset( $_POST['_awpa_local_avatar_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_awpa_local_avatar_nonce'] ), $this->nonce_save_action ) ) {
            return;
        }

        $media_id = ! empty( $_POST['awpa_local_avatar_media_id'] ) ? intval( wp_unslash( $_POST['awpa_local_avatar_media_id'] ) ) : 0;
        if ( $media_id && current_user_can( 'upload_files' ) ) {
            if ( wp_attachment_is_image( $media_id ) ) {
                $this->assign_new_user_avatar( $media_id, $user_id );
            }
        }
    }

    /**
     * Assign logic.
     */
    public function assign_new_user_avatar( $url_or_media_id, $user_id ) {
        $this->avatar_delete( $user_id );
        $meta = array();

        if ( is_numeric( $url_or_media_id ) ) {
            $meta['media_id'] = (int) $url_or_media_id;
            $meta['full']     = wp_get_attachment_url( $url_or_media_id );
        } else {
            $meta['full'] = esc_url_raw( $url_or_media_id );
        }

        update_user_meta( $user_id, $this->meta_key, $meta );
        do_action( 'awpa_local_avatar_updated', $user_id );
    }

    /**
     * Cleanup files properly.
     */
    public function avatar_delete( $user_id ) {
        $old_avatars = $this->get_user_local_avatar( $user_id );
        if ( empty( $old_avatars ) ) {
            return;
        }

        $upload_dir = wp_upload_dir();

        foreach ( $old_avatars as $k => $old_avatar ) {
            if ( is_numeric( $k ) && 0 === strpos( $old_avatar, $upload_dir['baseurl'] ) ) {
                $path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $old_avatar );
                if ( file_exists( $path ) && is_writable( $path ) ) {
                    wp_delete_file( $path ); // 🚨 GUIDELINE FIX: Use WP function instead of @unlink
                }
            }
        }

        delete_user_meta( $user_id, $this->meta_key );
        do_action( 'awpa_local_avatar_deleted', $user_id );
    }

    /**
     * Enqueue with inline script (Keep logic as requested).
     */
    public function admin_enqueue( $hook ) {
        if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
            return;
        }

        if ( current_user_can( 'upload_files' ) ) {
            wp_enqueue_media();
        }

        wp_register_script( 'awpa-local-avatar-js', '', array( 'jquery' ), false, true );
        wp_enqueue_script( 'awpa-local-avatar-js' );

        $assign_nonce = wp_create_nonce( $this->nonce_assign_action );
        $remove_nonce = wp_create_nonce( $this->nonce_remove_action );

        $inline = "(function($){
            var file_frame;
            $('#awpa-local-avatar-media').on('click', function(e){
                e.preventDefault();
                if ( file_frame ) { file_frame.open(); return; }
                file_frame = wp.media({
                    title: '". esc_js( __( 'Select avatar', 'wp-post-author' ) ) ."',
                    library: { type: 'image' },
                    button: { text: '". esc_js( __( 'Use as avatar', 'wp-post-author' ) ) ."' },
                    multiple: false
                });
                file_frame.on('select', function(){
                    var attachment = file_frame.state().get('selection').first().toJSON();
                    if ( attachment && attachment.id ) {
                        var data = {
                            action: 'awpa_assign_local_avatar_media',
                            media_id: attachment.id,
                            user_id: $('input[name=\"user_id\"]').val() || $('#user_id').val() || 0,
                            _wpnonce: '{$assign_nonce}'
                        };
                        $.post(ajaxurl, data, function(r){
                            if ( r.success && r.data.html ) {
                                $('#awpa-local-avatar-preview').html( r.data.html );
                                $('#awpa-local-avatar-media-id').val( attachment.id );
                            }
                        });
                    }
                });
                file_frame.open();
            });

            $(document).on('click', '.awpa-local-avatar-remove', function(e){
                e.preventDefault();
                if ( ! confirm('". esc_js( __( 'Remove local avatar?', 'wp-post-author' ) ) ."') ) return;
                var btn = $(this);
                var data = {
                    action: 'awpa_remove_local_avatar',
                    user_id: btn.data('user-id'),
                    _wpnonce: btn.data('nonce')
                };
                $.post(ajaxurl, data, function(r){
                    if ( r.success ) {
                        $('#awpa-local-avatar-preview').html( r.data.html );
                        btn.hide();
                        $('#awpa-local-avatar-media-id').val('');
                    }
                });
            });
        })(jQuery);";

        wp_add_inline_script( 'awpa-local-avatar-js', $inline );
    }

    public function ajax_assign_local_avatar_media() {
        check_ajax_referer( $this->nonce_assign_action );
        $media_id = intval( $_POST['media_id'] );
        $user_id  = intval( $_POST['user_id'] );

        if ( ! current_user_can( 'edit_user', $user_id ) || ! wp_attachment_is_image( $media_id ) ) {
            wp_send_json_error();
        }

        $this->assign_new_user_avatar( $media_id, $user_id );
        $local = $this->get_user_local_avatar( $user_id );
        $html = sprintf( '<img src="%s" style="width:100px;height:100px;object-fit:cover;border-radius:4px" />', esc_url( $local['full'] ) );
        wp_send_json_success( array( 'html' => $html ) );
    }

    public function ajax_remove_local_avatar() {
        check_ajax_referer( $this->nonce_remove_action );
        $user_id = intval( $_POST['user_id'] );
        if ( ! current_user_can( 'edit_user', $user_id ) ) wp_send_json_error();

        $this->avatar_delete( $user_id );
        wp_send_json_success( array( 'html' => get_avatar( $user_id, 100 ) ) );
    }

    public function action_remove_local_avatar() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) return;
        check_admin_referer( $this->nonce_remove_action );
        
        $user_id = intval( $_POST['user_id'] );
        if ( current_user_can( 'edit_user', $user_id ) ) {
            $this->avatar_delete( $user_id );
        }
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'profile.php' ) );
        exit;
    }
}
endif;

AWPA_Local_Avatars::init();