<?php
/**
 * Plugin Name: Jetpack Stats View Counter
 * Plugin URI: https://github.com/lophas/jetpack-view-counter
 * GitHub Plugin URI: https://github.com/lophas/jetpack-view-counter
 * Description:
 * Version: 2.0
 * Author: Attila Seres
 * Author URI:
 * License: GPLv2
 */
//Based on Adam Capriola's WordPress Stats View Counter @ https://wordpress.org/plugins/wp-stats-view-counter/
if (!class_exists('Jetpack_View_Counter')) :
class Jetpack_View_Counter
{
    const OPTIONS = 'view_counter';
    const HOOK = __CLASS__;
  	const SCHEDULE = 'hourly';
//    const META_KEY = '_jetpack_post_views_count';//apply_filters( 'view_counter_meta_key', self::META_KEY )

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
        // Settings page
        add_action('admin_init', array( $this, 'settings_page_init' ));
        add_action('admin_menu', array( $this, 'add_settings_page' ));
        add_filter('plugin_action_links', array( $this, 'add_settings_link' ), 10, 2);

        // Shortcode
        add_shortcode('view-count', array( $this, 'view_count_shortcode' ));

        if (!wp_next_scheduled ( self::HOOK )) wp_schedule_event(time(), self::SCHEDULE, self::HOOK);
     		add_action(self::HOOK, array($this, 'get_views'));
        if(!get_transient(__CLASS__)) $this->get_views();
//        add_filter( "get_post_metadata", function($meta_value, $post_id, $meta_key, $single ) {return $meta_key == $this->get_meta_key() ? $this->get_view_count($post_id) : $meta_value;}, PHP_INT_MAX, 4);
    }

    public function get_views($settings = false) {
      if(!$settings) $settings = $this->get_option();
      $views = [];
      foreach((array)$settings['post_types'] as $post_type) {
        $ids = get_posts(array(
            'fields'          => 'ids', // Only get post IDs
            'posts_per_page'  => -1,
            'post_type' => $post_type,
        ));
        for($i=0;$i<count($ids);$i=$i+500) {
          $chunk = array_slice($ids,$i,500);
          $posts = stats_get_csv('postviews', array('days' => -1, 'limit' => -1, 'post_id' => implode(',',$chunk)));
          foreach($posts as $post) $views[$post['post_id']] = $post['views'];
        }
      }
      if(!empty($views)) set_transient(__CLASS__, $views, 0);
  	}
    /**
     * Save WordPress.com views as post meta data
     */

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
    public function view_counter_validate($settings)
    {
        $this->get_views($settings);
        return $settings;
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


        add_filter('manage_edit-'.$GLOBALS['typenow'].'_sortable_columns', function ($columns) {
            $columns['pageviews'] = array('post_views',1);
            return $columns;
        });
        add_filter('manage_'.$GLOBALS['typenow'].'_posts_columns', function ($cols) {
            if(in_array($_GET['post_status'],['future','trash','draft'])) return $cols;
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
//            $view_count = get_post_meta($post_id, $this->get_meta_key(), true);
            // Print Jetpack post views
            if ($view_count) {
                echo  number_format(absint($view_count)) ;
            }
            //if(is_super_admin()) echo ' ['.date('H:i', apply_filters( 'view_counter_expiration', self::CACHE_HOURS )*HOUR_IN_SECONDS - time() + get_post_meta($post_id, $this->get_meta_key().'_created', true)).']';
        }, 10, 2);
            add_action('load-edit.php', function(){
              add_filter('posts_clauses', function($clauses) {
                if($_GET['orderby'] !== 'post_views') return $clauses;
                  if(!$views = get_transient(__CLASS__)) return $clauses;
                  asort($views, SORT_NUMERIC);
                  global $wpdb;
                  $clauses['orderby'] = 'FIELD('.$wpdb->posts.'.ID, '.implode(',',array_keys($views)).') '.$_GET['order'];
                  return $clauses;
              });
            });
    } //admin_init

    public function get_view_count($post_id = null)
    {
        if (!isset($post_id)) {
            $post_id = get_the_ID();
        }
        $views = get_transient(__CLASS__);
        return $views[$post_id];
    }

    public function get_option()
    {
        $options = get_option(self::OPTIONS, ['post_types' => ['post']]);
        return $options;
    }
} //class

Jetpack_View_Counter::instance();
endif;
