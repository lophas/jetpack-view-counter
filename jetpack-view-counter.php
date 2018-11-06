<?php
/**
 * Plugin Name: Jetpack Stats View Counter
 * Plugin URI: https://github.com/lophas/jetpack-view-counter
 * GitHub Plugin URI: https://github.com/lophas/jetpack-view-counter
 * Description: Based on Adam Capriola's WordPress Stats View Counter @ https://wordpress.org/plugins/wp-stats-view-counter/
 * Version: 1.5.2
 * Author: Attila Seres
 * Author URI:
 * License: GPLv2
 */
if (!class_exists('Jetpack_View_Counter')) :
class Jetpack_View_Counter
{
    const META_KEY = '_jetpack_post_views_count';//apply_filters( 'view_counter_meta_key', self::META_KEY )
    const CACHE_HOURS = 2; //apply_filters( 'view_counter_expiration', self::CACHE_HOURS )
    const OPTIONS = 'view_counter';

    private static $_instance;
    public function instance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance =  new self();
        }
        return self::$_instance;
    }
    public function __construct()
    {
        add_action('plugins_loaded', function () {
            if (!defined('JETPACK__VERSION')) {
                return;
            }
            add_action('init', array( $this, 'init' ));
            if (is_admin()) {
                add_action('admin_init', [$this, 'admin_init']);
            }
        });
    } //__construct

    public function init()
    {
        // Save views
        add_action('wp_footer', array( $this, 'save_views' ));

        // Settings page
        add_action('admin_init', array( $this, 'settings_page_init' ));
        add_action('admin_menu', array( $this, 'add_settings_page' ));
        add_filter('plugin_action_links', array( $this, 'add_settings_link' ), 10, 2);

        // Shortcode
        add_shortcode('view-count', array( $this, 'view_count_shortcode' ));
    }

    /**
     * Save WordPress.com views as post meta data
     */
    public function save_views()
    {
        $settings = $this->get_option();
        if (is_singular($settings['post_types'])) {
            if (get_post_status() == 'publish') {
                $this->get_view_count();
            }
        }//refresh post counter
    }

    /**
     * Initialize plugin options
     */
    public function settings_page_init()
    {
        register_setting('view_counter_options', self::OPTIONS, array( $this, 'view_counter_validate' ));
    }

    /**
     * Add Settings Page
     *
     */
    public function add_settings_page()
    {
        add_options_page(__('View Counter Settings', 'view-counter'), __('View Counter', 'view-counter'), 'manage_options', 'wp_stats_view_counter', array( $this, 'settings_page' ));
    }

    /**
     * Build Settings Page
     *
     */
    public function settings_page()
    {
        ?>
		<div class="wrap">
			<h2><?php _e('View Counter Settings', 'view-counter'); ?></h2>
			<form method="post" action="options.php">
				<?php
                settings_fields('view_counter_options');
        $settings = $this->get_option();
        if (isset($settings['post_types'])) {
            $post_types = $settings['post_types'];
        } else {
            $post_types = array();
        } ?>
				<table class="form-table">
					<tr valign="top"><th scope="row"><?php _e('Active for Selected Post Types', 'view-counter'); ?></th>
						<td>
						<?php
                        foreach (get_post_types(array( 'public' => true ), 'objects') as $cpt) {
                            $checked = checked(in_array($cpt->name, $post_types) ? true : false, true, false);
                            $name = esc_attr($cpt->name);
                            $label = esc_html($cpt->label);

                            printf('<label><input type="checkbox" %s name="'.self::OPTIONS.'[post_types][]" value="%s" /> %s </label><br />', $checked, $name, $label);
                        } ?>
						</td>
					</tr>
				</table>
				<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Save Changes', 'view-counter'); ?>" />
				</p>
			</form>
		</div>
		<?php
    }

    /**
     * Add Settings Link
     *
     */
    public function add_settings_link($links, $file)
    {
        static $this_plugin;

        if (empty($this_plugin)) {
            $this_plugin = plugin_basename(__FILE__);
        }

        // Check to make sure we're on the right plugin
        if ($file == $this_plugin) {

            // Create link
            $settings_link = '<a href="' . admin_url('options-general.php?page=wp_stats_view_counter') . '">' . __('Settings', 'view-counter') . '</a>';

            // Add link to list
            array_unshift($links, $settings_link);
        }

        return $links;
    }

    /**
     * Validate settings
     *
     */
    public function view_counter_validate($input)
    {
        global $wpdb;
        $sql = 'DELETE FROM '.$wpdb->postmeta.' WHERE meta_key ="'.$this->get_meta_key().'" OR meta_key ="'.$this->get_meta_key().'_created"';
        $wpdb->query($sql);
        return $input;
    }

    /**
     * View count shortcode
     *
     * Example usage: [view-count before="Views: "] or [view-count after=" views"]
     *
     */
    public function view_count_shortcode($atts)
    {
        $settings = $this->get_option();
        if (!is_singular($settings['post_types'])) {
            return;
        }
        if (get_post_status() != 'publish') {
            return;
        }

        $defaults = array(
            'after'  => '',
            'before' => '',
        );
        $atts = shortcode_atts($defaults, $atts);

        //		$views = number_format_i18n( (double) get_post_meta( $post->ID, $this->get_meta_key(), true ) );
        $views = number_format_i18n((double) $this->get_view_count());

        if ($views) {
            $output = sprintf('<span class="view-count">%2$s%1$s%3$s</span>', $views, $atts['before'], $atts['after']);
        }

        return $output;
    }

    public function get_meta_key()
    {
        return apply_filters('view_counter_meta_key', self::META_KEY);
    }

    public function admin_init()
    {
        $settings = $this->get_option();
        if (!in_array($GLOBALS['typenow'], $settings['post_types'])) {
            return;
        }

        add_action('admin_head-edit.php', function () {
            ?>
<style>
#pageviews {
	width: 6em;
	padding:0;
}
</style>
<?php
        });

        add_action('pre_get_posts', function ($query) {
            $orderby = $_GET['orderby'];//$query->get( 'orderby');
            // if(is_super_admin()) echo  $orderby;
            if ('post_views' == $orderby) {
                $query->set('meta_key', $this->get_meta_key());
                $query->set('orderby', 'meta_value_num');
            }
        });


        add_filter('manage_edit-'.$GLOBALS['typenow'].'_sortable_columns', function ($columns) {
            $columns['pageviews'] = array('post_views',1);
            return $columns;
        });
        add_filter('manage_'.$GLOBALS['typenow'].'_posts_columns', function ($cols) {
            $cols['pageviews'] = 'Views';
            return $cols;
        }, 11);
        //add_action( 'manage_posts_custom_column', function( $colname ) {
        add_action("manage_".$GLOBALS['typenow']."_posts_custom_column", function ($colname, $post_id) {
            if (get_post_status($post_id) != 'publish') {
                return;
            }

            if ('pageviews' !== $colname) {
                return false;
            }

            $view_count = $this->get_view_count($post_id);
            // Print Jetpack post views
            if ($view_count) {
                echo  number_format(absint($view_count)) ;
            }
            //if(is_super_admin()) echo ' ['.date('H:i', apply_filters( 'view_counter_expiration', self::CACHE_HOURS )*HOUR_IN_SECONDS - time() + get_post_meta($post_id, $this->get_meta_key().'_created', true)).']';
        }, 10, 2);
    } //admin_init

    public function get_view_count($post_id = null)
    {
        if (!isset($post_id)) {
            $post_id = get_the_ID();
        }
        if (get_post_status($post_id) != 'publish') {
            return;
        }
        $settings = $this->get_option();
        if (!in_array(get_post_type($post_id), $settings['post_types'])) {
            return;
        }

        $view_count_created = absint(get_post_meta($post_id, $this->get_meta_key().'_created', true));

        $expiration = absint(apply_filters('view_counter_expiration', self::CACHE_HOURS));

//    if ( $view_count === false || time() > $view_count_created + ($expiration * HOUR_IN_SECONDS) ) {
        if (time() > $view_count_created + ($expiration * HOUR_IN_SECONDS)) {
            // Get the post data from Jetpack
            $random = mt_rand(36500, 2147483647); // hack to break cache bug

            $args = array(
                'days'    => $random,
                'post_id' => $post_id
            );
            $postviews = stats_get_csv('postviews', $args);

            // We have a problem if there was no data returned
            if (!isset($postviews[0]['views'])) {
                $view_count = get_post_meta($post_id, $this->get_meta_key(), true);
            } else {
                $view_count = absint($postviews[0]['views']);
                update_post_meta($post_id, $this->get_meta_key(), $view_count);
            }
            update_post_meta($post_id, $this->get_meta_key().'_created', time() + mt_rand(-1800, 1800));
        } else {
            $view_count = get_post_meta($post_id, $this->get_meta_key(), true);
        }

        return absint($view_count);
    }
    public function get_option()
    {
        $options = get_option(self::OPTIONS, ['post_types' => ['post']]);
        return $options;
    }
} //class

Jetpack_View_Counter::instance();
endif;
