<?php
/**
 * Plugin Name: Jetpack Stats View Counter
 * Plugin URI: https://github.com/lophas/jetpack-view-counter
 * GitHub Plugin URI: https://github.com/lophas/jetpack-view-counter
 * Description:
 * Version: 2.4
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
  	const CACHE_HOURS = 1;
    private $schedule;
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
            if (!defined('JETPACK__VERSION')) return;
            add_action('init', array( $this, 'init' ));
        });
    } //__construct
    public function init()
    {
        if ( ! class_exists( 'Jetpack' ) || ! Jetpack::is_active()) return;
        // Settings page
        if (is_admin()) {
            add_action('admin_init', [$this, 'admin_init']);
            add_action('admin_init', array( $this, 'settings_page_init' ));
            add_action('admin_menu', array( $this, 'add_settings_page' ));
            add_filter('plugin_action_links', array( $this, 'add_settings_link' ), 10, 2);
        }
        // Shortcode
        add_shortcode('view-count', array( $this, 'view_count_shortcode' ));
        add_filter('cron_schedules', function($schedules){
           if(!isset($schedules[$this->schedule])) $schedules[$this->schedule] = array( 'interval' => intval($this->schedule) * 60, 'display' => 'Every '.$this->schedule);
           return $schedules;
         });
     		add_action(self::HOOK, array($this, 'get_views'));
        $this->schedule = absint(apply_filters('view_counter_expiration', self::CACHE_HOURS) * 60); //minutes
        if($this->schedule < 5) $this->schedule = 5; //Jetpack cache time
        $this->schedule .= ' minutes';
        if($cron = wp_get_scheduled_event( self::HOOK )) if($cron->schedule != $this->schedule) wp_clear_scheduled_hook(self::HOOK);
        if (!wp_next_scheduled ( self::HOOK )) wp_schedule_event(time(), $this->schedule, self::HOOK);
        $settings = $this->get_option();
        foreach((array)$settings['post_types'] as $post_type) if(!get_transient(__CLASS__.'_'.$post_type)) {
          $this->get_views($settings);
          break;
        }
    }
    public function get_views($settings = false) {
      if(!$settings) $settings = $this->get_option();
      foreach((array)$settings['post_types'] as $post_type) {
        $views = [];
        $ids = get_posts(array(
            'fields'          => 'ids', // Only get post IDs
            'posts_per_page'  => -1,
            'post_type' => $post_type,
        ));
        for($i=0;$i<count($ids);$i=$i+500) {
          $chunk = array_slice($ids,$i,500);
          $posts = $this->stats_get_csv('postviews', array('days' => -1, 'limit' => -1, 'post_id' => implode(',',$chunk)));
          foreach($posts as $post) $views[$post['post_id']] = $post['views'];
        }
        set_transient(__CLASS__.'_'.$post_type, $views, empty($views) ? HOUR_IN_SECONDS : DAY_IN_SECONDS);
      }
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
            // Print Jetpack post views
            if ($view_count) {
                echo  number_format(absint($view_count)) ;
            }
        }, 10, 2);
            add_action('load-edit.php', function(){
              add_action('pre_get_posts', [$this, 'pre_get_posts']);
            });
    } //admin_init
    public function pre_get_posts() {
      if($_GET['orderby'] !== 'post_views') return;
      add_filter('posts_clauses', [$this, 'posts_clauses'], 10, 2);
      remove_action('pre_get_posts', [$this,__FUNCTION__]);
    }
    public function posts_clauses($clauses, $query) {
        if(!$views = get_transient(__CLASS__.'_'.$query->query_vars['post_type'])) return $clauses;
        remove_filter('posts_clauses', [$this,__FUNCTION__]);
        asort($views, SORT_NUMERIC);
        global $wpdb;
        $clauses['orderby'] = 'FIELD('.$wpdb->posts.'.ID, '.implode(',',array_keys($views)).') '.$_GET['order'];
        return $clauses;
    }
    public function get_view_count($post_id = null)
    {
        if (!isset($post_id)) {
            $post_id = get_the_ID();
        }
        if($views = get_transient(__CLASS__.'_'.get_post_type($post_id))) return $views[$post_id];
    }
    public function get_option()
    {
        $options = get_option(self::OPTIONS, ['post_types' => ['post']]);
        return $options;
    }
    public function stats_get_csv( $table, $args = null ) {
    	$defaults = array( 'end' => false, 'days' => false, 'limit' => 3, 'post_id' => false, 'summarize' => '' );

    	$args = wp_parse_args( $args, $defaults );
    	$args['table'] = $table;
    	$args['blog_id'] = Jetpack_Options::get_option( 'id' );

    	$stats_csv_url = add_query_arg( $args, 'https://stats.wordpress.com/csv.php' );
/*
    	$key = md5( $stats_csv_url );

    	// Get cache.
    	$stats_cache = get_option( 'stats_cache' );
    	if ( ! $stats_cache || ! is_array( $stats_cache ) ) {
    		$stats_cache = array();
    	}

    	// Return or expire this key.
    	if ( isset( $stats_cache[ $key ] ) ) {
    		$time = key( $stats_cache[ $key ] );
    		if ( time() - $time < 300 ) {
    			return $stats_cache[ $key ][ $time ];
    		}
    		unset( $stats_cache[ $key ] );
    	}
*/
    	$stats_rows = array();
    	do {
    		if ( ! $stats = stats_get_remote_csv( $stats_csv_url ) ) {
    			break;
    		}

    		$labels = array_shift( $stats );

    		if ( 0 === stripos( $labels[0], 'error' ) ) {
    			break;
    		}

    		$stats_rows = array();
    		for ( $s = 0; isset( $stats[ $s ] ); $s++ ) {
    			$row = array();
    			foreach ( $labels as $col => $label ) {
    				$row[ $label ] = $stats[ $s ][ $col ];
    			}
    			$stats_rows[] = $row;
    		}
    	} while ( 0 );
/*
    	// Expire old keys.
    	foreach ( $stats_cache as $k => $cache ) {
    		if ( ! is_array( $cache ) || 300 < time() - key( $cache ) ) {
    			unset( $stats_cache[ $k ] );
    		}
    	}

    		// Set cache.
    		$stats_cache[ $key ] = array( time() => $stats_rows );
    	update_option( 'stats_cache', $stats_cache );
*/
    	return $stats_rows;
    }
} //class
Jetpack_View_Counter::instance();
endif;
