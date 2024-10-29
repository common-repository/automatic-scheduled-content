<?php
/**
 * Plugin Name: Automatic Scheduled Content
 * Description: This plugin schedules automatic content updates, compatible with Elementor.
 * Version:     1.0.0
 * Author:      Camilo Camargo
 * Author URI:  https://concamilo.com/
 * License: GPLv2 or later
 * Text Domain: automatic-scheduled-content
 *
 * @package con
 */


/**
 * Content Update Scheduler main class
 */
class AutomaticScheduledContent {


	/**
	 * Label to be displayed to the user
	 *
	 * @access public
	 * @var string
	 */
	public static $asc_publish_label         = 'Automatic Scheduled Content';

	/**
	 * Title for the Publish Metabox
	 *
	 * @access protected
	 * @var string
	 */
	protected static $_asc_publish_metabox    = 'Automatic Scheduled Content';

	/**
	 * Status for wordpress posts
	 *
	 * @access protected
	 * @var string
	 */
	protected static $_asc_publish_status     = 'asc_sc_publish';


	/**
	 * Initializes asc_publish_label and _asc_publish_metabox with their localized strings.
	 *
	 * This method initializes asc_publish_label and _asc_publish_metabox with their localized
	 * strings and registers the asc_sc_publish post status.
	 *
	 * @return void
	 */
	public static function init() {
		require_once dirname( __FILE__ ) . '/options.php';

		self::load_plugin_textdomain();
		self::$asc_publish_label   = __( 'Automatic Scheduled Content', 'automatic-scheduled-content' );
		self::$_asc_publish_metabox = __( 'Automatic Scheduled Content', 'automatic-scheduled-content' );
		self::register_post_status();

		$pt = AutomaticScheduledContent::get_post_types();
		foreach ( $pt as $type ) {
			add_action( 'manage_edit-' . $type->name . '_columns', array( 'AutomaticScheduledContent', 'manage_pages_columns' ) );
			add_filter( 'manage_' . $type->name . '_posts_custom_column', array( 'AutomaticScheduledContent', 'manage_pages_custom_column' ), 10, 2 );
			add_action( 'add_meta_boxes', array( 'AutomaticScheduledContent', 'add_meta_boxes_page' ), 10, 2 );
		}
	}

	/**
	 * Wrapper for wp's own load_plugin_textdomain.
	 *
	 * @access private
	 *
	 * @return void
	 */
	private static function load_plugin_textdomain() {
		load_plugin_textdomain( 'automatic-scheduled-content', false, dirname( plugin_basename( __FILE__ ) ) . '/language/' );
	}

	/**
	 * Retreives all currently registered posttypes.
	 *
	 * @access private
	 *
	 * @return array Array of all registered post type as objects
	 */
	private static function get_post_types() {
		return get_post_types( array(
			'public' => true,
		), 'objects' );
	}


	/**
	 * Displays a post's publishing date.
	 *
	 * @see get_post_meta
	 *
	 * @return void
	 */
	public static function load_pubdate() {
		if ( isset( $_REQUEST['postid'] ) ) { // WPCS: CSRF okay.
			$stamp = get_post_meta( absint( wp_unslash( $_REQUEST['postid'] ) ), self::$_asc_publish_status . '_pubdate', true ); // WPCS: CSRF okay.
			if ( $stamp ) {
				$str  = '<div style="margin-left:20px">';
				$str .= TaoPublish::get_pubdate( $stamp );
				$str .= '</div>';
				die( $str ); // WPCS: XSS okay.
			}
		}
	}

	/**
	 * Registers the post status asc_sc_publish.
	 *
	 * @see register_post_status
	 *
	 * @return void
	 */
	public static function register_post_status() {
		$public = false;
		if ( AutomaticScheduledContent_Options::get( 'tsu_visible' ) ) {
			// we only want to register as public if we're not on the search page.
			$public = ! is_search();
		}

		// compatibility with CMS Tree Page View.
		$exclude_from_search = ! is_admin();

		$args = array(
			'label'                     => _x( 'Automatic Scheduled Content', 'Status General Name', 'default' ),
			'public'                    => $public,
			'internal'                  => true,
			'publicly_queryable'        => true,
			'protected'                 => true,
			'exclude_from_search'       => $exclude_from_search,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			// translators: number of posts.
			'label_count'               => _n_noop( 'Automatic Scheduled Content <span class="count">(%s)</span>', 'Automatic Scheduled Content <span class="count">(%s)</span>', 'automatic-scheduled-content' ),
		);

		register_post_status( self::$_asc_publish_status, $args );
	}

	/**
	 * Adds the cus-schedule-update post status to the list of displayable stati in the parent dropdown
	 *
	 * @param array $args arguments passed by the filter.
	 *
	 * @return array Array of parameters
	 */
	public static function parent_dropdown_status( $args ) {
		if ( ! isset( $args['post_status'] ) || ! is_array( $args['post_status'] ) ) {
			$args['post_status'] = array( 'publish' );
		}

		$args['post_status'][] = 'asc_sc_publish';

		return $args;
	}

	/**
	 * Adds post's state to 'scheduled updates'-posts.
	 *
	 * @param array $states Array of post states.
	 *
	 * @global $post
	 */
	public static function display_post_states( $states ) {
		global $post;
		$arg = get_query_var( 'post_status' );
		$the_post_types = self::get_post_types();
		
		// default states for non public posts.
		
		if(is_object($post)){
			
			if ( ! isset( $the_post_types[ $post->post_type ] ) ) {
			return $states;
			}
			$type = $the_post_types[ $post->post_type ];

			if ( $arg !== self::$asc_publish_label && $post->post_status === self::$_asc_publish_status ) {
				$states = array( self::$asc_publish_label );
				if ( ! $type->hierarchical ) {
					$orig = get_post( get_post_meta( $post->ID, self::$_asc_publish_status . '_original', true ) );
					array_push( $states, __( 'Original', 'automatic-scheduled-content' ) . ': ' . $orig->post_title );
				}
			}
		}
		
		return $states;
	}


	/**
	 * Adds links for scheduled updates.
	 *
	 * Adds a link for immediate publishing to all sheduled posts. Adds a link to schedule a change
	 * to all non-scheduled posts.
	 *
	 * @param array $actions Array of available actions added by previous hooks.
	 * @param post  $post    the post for which to add actions.
	 *
	 * @return array Array of available actions for the given post
	 */
	public static function page_row_actions( $actions, $post ) {
		$copy = '?action=workflow_copy_to_publish&post=' . $post->ID . '&n=' . wp_create_nonce( 'workflow_copy_to_publish' . $post->ID );
		if ( $post->post_status === self::$_asc_publish_status ) {
			
			$action = '?action=workflow_publish_now&post=' . $post->ID . '&n=' . wp_create_nonce( 'workflow_publish_now' . $post->ID );
			$actions['publish_now'] = '<a href="' . admin_url( 'admin.php' . $action ) . '">' . __( 'Publish Now', 'automatic-scheduled-content' ) . '</a>';
			$actions['copy_to_publish'] = '<a href="' . admin_url( 'admin.php' . $copy ) . '">' . self::$asc_publish_label . '</a>';
			if ( AutomaticScheduledContent_Options::get( 'tsu_recursive' ) ) {
				$actions['copy_to_publish'] = '<a href="' . admin_url( 'admin.php' . $copy ) . '">' . __( 'Schedule recursive', 'automatic-scheduled-content' ) . '</a>';
			}
		} elseif ( 'trash' !== $post->post_status ) {
			$actions['copy_to_publish'] = '<a href="' . admin_url( 'admin.php' . $copy ) . '">' . self::$asc_publish_label . '</a>';
		}

		return $actions;
	}


	/**
	 * Adds a column to the pages overview.
	 *
	 * @param array $columns Array of available columns added by previous hooks.
	 *
	 * @return array Array of available columns
	 */
	public static function manage_pages_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $val ) {
			$new[ $key ] = $val;
			if ( 'title' === $key ) {
				$new['cus_publish'] = esc_html__( 'Release Date', 'automatic-scheduled-content' );
			}
		}
		return $new;
	}


	/**
	 * Manages the content of previously added custom columns.
	 *
	 * @see AutomaticScheduledContent::manage_pages_columns()
	 *
	 * @param string $column  Name of the column.
	 * @param int    $post_id id of the current post.
	 *
	 * @return void
	 */
	public static function manage_pages_custom_column( $column, $post_id ) {
		if ( 'cus_publish' === $column ) {
			$stamp = get_post_meta( $post_id, self::$_asc_publish_status . '_pubdate', true );

			if ( $stamp ) {
				echo esc_html( self::get_pubdate( $stamp ) );
			}
		}
	}


	/**
	 * Handles the admin action workflow_copy_to_publish.
	 * redirects to post edit screen if successful
	 *
	 * @return void
	 */
	public static function admin_action_workflow_copy_to_publish() {
		if ( isset( $_REQUEST['n'], $_REQUEST['post'] ) && wp_verify_nonce( sanitize_key( $_REQUEST['n'] ), 'workflow_copy_to_publish' . absint( $_REQUEST['post'] ) ) ) {
			$post = get_post( absint( wp_unslash( $_REQUEST['post'] ) ) );
			$publishing_id = self::create_publishing_post( $post );
			if ( $publishing_id ) {
				wp_redirect( admin_url( 'post.php?action=edit&post=' . $publishing_id ) );
			} else {
				// translators: %1$s: post type, %2$s: post title.
				$html  = sprintf( __( 'Could not schedule %1$s %2$s', 'automatic-scheduled-content' ), $post->post_type, '<i>' . htmlspecialchars( $post->post_title ) . '</i>' );
				$html .= '<br><br>';
				$html .= '<a href="' . esc_attr( admin_url( 'edit.php?post_type=' . $post->post_type ) ) . '">' . __( 'Back' ) . '</a>';
				wp_die( $html ); // WPCS: XSS okay.
			}
		}
	}

	/**
	 * Handles the admin action workflow_publish_now
	 *
	 * @return void
	 */
	public static function admin_action_workflow_publish_now() {
		if ( isset( $_REQUEST['n'], $_REQUEST['post'] ) && wp_verify_nonce( sanitize_key( $_REQUEST['n'] ), 'workflow_publish_now' . absint( $_REQUEST['post'] ) ) ) {
			$post = get_post( absint( wp_unslash( $_REQUEST['post'] ) ) );
			$msgid = $_GET['msgid'];			
			self::publish_post( $post->ID );
			wp_redirect( admin_url( 'edit.php?post_type=' . $post->post_type ) );
		}
	}


	/**
	 * Adds the 'scheduled update'-metabox to the edit-page screen.
	 *
	 * @see add_meta_box
	 *
	 * @param string $post_type The post type of the post being edited.
	 * @param post   $post      The post being currently edited.
	 *
	 * @return void
	 */
	public static function add_meta_boxes_page( $post_type, $post ) {
		if ( $post->post_status !== self::$_asc_publish_status ) {
			return;
		}

		// hides everything except the 'publish' button in the 'publish'-metabox
		echo '<style> #duplicate-action, #delete-action, #minor-publishing-actions, #misc-publishing-actions, #preview-action {display:none;} </style>'; // WPCS: XSS okay.

		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_style( 'jquery-ui-blitzer', plugin_dir_url( __FILE__ ) . 'jquery-ui.min.css' );
		wp_enqueue_script( self::$_asc_publish_status . '-datepicker.js', plugins_url( 'js/publish-datepicker.js', __FILE__ ), array( 'jquery-ui-datepicker' ) );

		$months = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$months[] = date_i18n( 'F', strtotime( '2014-' . $i . '-01 00:00:00' ) );
		}
		$days = array();
		for ( $i = 23;$i <= 29;$i++ ) {
			$days[] = date_i18n( 'D', strtotime( '2014-03-' . $i . ' 00:00:00' ) );
		}

		// Get WP date format and make it usable in the datepicker.
		$df = get_option( 'date_format' );
		$df = str_replace(
			array( 'd',  'j', 'S', 'l',  'D', 'm',  'n', 'F',  'M', 'Y',  'y', 'c',        'r',         'U' ),
			array( 'dd', 'd', '',  'DD', 'D', 'mm', 'm', 'MM', 'M', 'yy', 'y', 'yy-mm-dd', 'D, d M yy', '@' ),
			$df
		);

		$js_data = array(
			'datepicker' => array(
				'daynames'   => $days,
				'monthnames' => $months,
				'elementid'  => self::$_asc_publish_status . '_pubdate',
				'displayid'  => self::$_asc_publish_status . '_pubdate_display',
				'dateformat' => $df,
			),
			'text' => array(
				'save' => __( 'Save' ),
			),
		);

		wp_localize_script( self::$_asc_publish_status . '-datepicker.js', 'CUSScheduleUpdate', $js_data );

		add_meta_box( 'meta_' . self::$_asc_publish_status, self::$_asc_publish_metabox, array( 'AutomaticScheduledContent', 'create_meta_box' ), $post_type, 'side' );
	}

	/**
	 * Creates the HTML-Code for the 'scheduled update'-metabox
	 *
	 * @param post $post The post being currently edited.
	 *
	 * @return void
	 */
	public static function create_meta_box( $post ) {
		wp_nonce_field( basename( __FILE__ ), self::$_asc_publish_status . '_nonce' );
		$metaname = self::$_asc_publish_status . '_pubdate';
		$stamp = get_post_meta( $post->ID, $metaname, true );
		$date = '';
		$time = '';
		$offset = get_option( 'gmt_offset' ) * 3600;
		$dateo = new DateTime( 'now', self::get_timezone_object() );
		if ( $stamp ) {
			$dateo->setTimestamp( $stamp );
		}
		$time = $dateo->format( 'H:i' );
		$date = date_i18n( get_option( 'date_format' ), $dateo->getTimestamp() + $offset );
		$date2 = $dateo->format( 'd.m.Y' );

		if ( ! $stamp && AutomaticScheduledContent_Options::get( 'tsu_nodate' ) === 'nothing' ) {
			$date = '';
		}
		$dec_time = floatval( get_option( 'gmt_offset' ) );
		$gmt_hour = floor( $dec_time );
		$gmt_min = round( 60 * ($dec_time -$gmt_hour) );
?>
			<p>
				<strong><?php esc_html_e( 'Release Date', 'automatic-scheduled-content' ); ?></strong>
			</p>
			<label class="screen-reader-text" for="<?php echo esc_attr( $metaname ); ?>"><?php esc_html_e( 'Release Date', 'automatic-scheduled-content' ); ?></label>
			<input type="hidden" name="<?php echo esc_attr( $metaname ); ?>" id="<?php echo esc_attr( $metaname ); ?>" value="<?php echo esc_attr( $date2 ); ?>"/>
			<input type="text" class="widefat" name="<?php echo esc_attr( $metaname ); ?>_display" id="<?php echo esc_attr( $metaname ); ?>_display" value="<?php echo esc_attr( $date ); ?>"/>
			<p>
				<strong><?php esc_html_e( 'Time', 'automatic-scheduled-content' ); ?></strong>
			</p>
			<label class="screen-reader-text" for="<?php echo esc_attr( $metaname ); ?>_time"><?php esc_html_e( 'Time', 'automatic-scheduled-content' ); ?></label>
			<select name="<?php echo esc_attr( $metaname ); ?>_time_hrs" id="<?php echo esc_attr( $metaname ); ?>_time">
				<?php for ( $i = 0; $i < 24; $i++ ) : ?>
				<option value="<?php echo esc_attr( sprintf( '%02d', $i ) ); ?>" <?php echo intval( $dateo->format( 'H' ) ) === $i ? 'selected' : ''; ?>><?php echo esc_html( sprintf( '%02d', $i ) ); ?></option>
				<?php endfor; ?>
			</select>:
			<select name="<?php echo esc_attr( $metaname ); ?>_time_mins">
				<?php for ( $i = 0; $i < 60; $i += 5 ) : ?>
				<option value="<?php echo esc_attr( sprintf( '%02d', $i ) ); ?>" <?php echo intval( ceil( $dateo->format( 'i' ) / 10 ) * 10 ) === $i ? 'selected' : ''; ?>><?php echo esc_html( sprintf( '%02d', $i ) ); ?></option>
				<?php endfor; ?>
			</select>
			<input type="hidden" name="cus_added_minutes" id="cus_used_gmt" value="<?php echo esc_attr( $gmt_hour >= 0 ? '+' : '-' ); echo esc_attr( sprintf( '%02d', $gmt_hour ) . ':' . sprintf( '%02d', $gmt_min ) ) ?>">
			<p>
				<?php
				// translators: timezone placeholder
				echo sprintf( __( 'Please enter <i>Time</i> as %s', 'automatic-scheduled-content' ), self::get_timezone_string() ); // WPCS: XSS okay.
				?>
			</p>
			<p>
				<div id="pastmsg" style="color:red; display:none;">
					<?php
					echo esc_html__( 'The releasedate is in the past.', 'automatic-scheduled-content' );
					if ( AutomaticScheduledContent_Options::get( 'tsu_nodate' ) === 'nothing' ) {
						echo esc_html__( 'This post will not be published.', 'automatic-scheduled-content' );
					} else {
						echo esc_html__( 'This post will be published 5 minutes from now.', 'automatic-scheduled-content' );
					}
?>
				</div>
			</p>
		<?php
	}


	/**
	 * Gets the currently set timezone..
	 *
	 * Retreives either the timezone_string or the gmt_offset.
	 *
	 * @see get_option
	 *
	 * @access private
	 *
	 * @return string The set timezone
	 */
	private static function get_timezone_string() {
		$current_offset = get_option( 'gmt_offset' );
		$tzstring = get_option( 'timezone_string' );

		$check_zone_info = true;

		// Remove old Etc mappings. Fallback to gmt_offset.
		if ( false !== strpos( $tzstring, 'Etc/GMT' ) ) {
			$tzstring = '';
		}

		if ( empty( $tzstring ) ) { // Create a UTC+- zone if no timezone string exists.
			$check_zone_info = false;
			if ( 0 === $current_offset ) {
				$tzstring = 'UTC+0';
			} elseif ( $current_offset < 0 ) {
				$tzstring = 'UTC' . $current_offset;
			} else {
				$tzstring = 'UTC+' . $current_offset;
			}
		}

		return $tzstring;
	}

	/**
	 * Creates a timezone object based on the option gmt_offset
	 *
	 * @see DateTimeZone
	 *
	 * @return DateTimeZone timezone specified by the gmt_offset option
	 */
	private static function get_timezone_object() {
		$offset = intval( get_option( 'gmt_offset' ) * 3600 );
		$ids = DateTimeZone::listIdentifiers();
		foreach ( $ids as $timezone ) {
			$tzo = new DateTimeZone( $timezone );
			$dt = new DateTime( 'now', $tzo );
			if ( $tzo->getOffset( $dt ) === $offset ) {
				return $tzo;
			}
		}
	}

	/**
	 * Prevents scheduled updates to switch to other post states.
	 *
	 * Prevents post with the state 'scheduled update' to switch to published after being saved
	 * clears cron hook if post is trashed
	 * restores cron hook if post us un-trashed
	 *
	 * @param string $new_status the post's new status.
	 * @param string $old_status the post's old status.
	 * @param post   $post       the post changing status.
	 *
	 * @return void
	 */
	public static function prevent_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status && $new_status === self::$_asc_publish_status ) { return;
		}

		if ( $old_status === self::$_asc_publish_status && 'trash' !== $new_status ) {
			remove_action( 'save_post', array( 'AutomaticScheduledContent', 'save_meta' ), 10 );

			$post->post_status = self::$_asc_publish_status;
			$u = wp_update_post( $post, true );

			add_action( 'save_post', array( 'AutomaticScheduledContent', 'save_meta' ), 10, 2 );
		} elseif ( 'trash' === $new_status ) {
			wp_clear_scheduled_hook( 'cus_publish_post', array(
				'ID' => $post->ID,
			) );
		} elseif ( 'trash' === $old_status && $new_status === self::$_asc_publish_status ) {
			wp_schedule_single_event( get_post_meta( $post->ID, self::$_asc_publish_status . '_pubdate', true ), 'cus_publish_post', array(
				'ID' => $post->ID,
			) );
		}
	}

	/**
	 * Copies an entire post and sets it's status to 'scheduled update'
	 *
	 * @param post $post the post to be copied.
	 *
	 * @return int ID of the newly created post
	 */
	public static function create_publishing_post( $post ) {
		$new_author = wp_get_current_user();

		$original = $post->ID;
		if ( $post->post_status === self::$_asc_publish_status ) {
			$original = get_post_meta( $post->ID, self::$_asc_publish_status . '_original', true );
		}

		// create the new post.
		$new_post = array(
			'menu_order'     => $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_author'    => $new_author->ID,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_mime_type' => $post->mime_type,
			'post_parent'    => $post->ID,
			'post_password'  => $post->post_password,
			'post_status'    => self::$_asc_publish_status,
			'post_title'     => $post->post_title,
			'post_type'      => $post->post_type,
		);

		// insert the new post.
		$new_post_id = wp_insert_post( $new_post );
				

		// copy meta and terms over to the new post.
		self::copy_meta_and_terms( $post->ID, $new_post_id );

		// and finally referencing the original post.
		update_post_meta( $new_post_id, self::$_asc_publish_status . '_original', $original );

		/**
		 * Fires when a post has been duplicated.
		 *
		 * @param int     $new_post_id ID of the newly created post.
		 * @param int     $original    ID of the original post.
		 */
		do_action( 'AutomaticScheduledContent\\create_publishing_post', $new_post_id, $original );

		return $new_post_id;
	}

	/**
	 * Copies meta and terms from one post to another
	 *
	 * @param int $source_post_id      the post from which to copy.
	 * @param int $destination_post_id the post which will get the meta and terms.
	 *
	 * @return void
	 */
		public static function copy_meta_and_terms( $post, $id ) {

		 global $wpdb;

        $meta = $wpdb->get_results(
            $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post )
        );

        if ( ! empty( $meta ) && is_array( $meta ) ) {
            
            $query = "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES ";
            
            $_records = [];
            
            foreach ( $meta as $meta_info ) {
                $_value = wp_slash( $meta_info->meta_value );
                $_records[] = "( $id, '{$meta_info->meta_key}', '{$_value}' )";
            }
            
            $query .= implode( ', ', $_records ) . ';';
            $wpdb->query( $query  );
        }


        $taxonomies = get_object_taxonomies( get_post_type($post) );
        
        if ( ! empty( $taxonomies ) && is_array( $taxonomies ) ) {
            foreach ( $taxonomies as $taxonomy ) {
                $terms = wp_get_object_terms( $post, $taxonomy, [ 'fields' => 'slugs' ] );
                wp_set_object_terms( $id, $terms, $taxonomy, false );
            }
        }


	}


	/**
	 * Actualiza los metadatos
	 *
	 * @param array $meta arreglo con los datos a actualizar
	 * @param int $id identificador del post a actualizar
	 *
	 * @return void
	 */
		public static function update_meta_and_terms( $meta, $id ) {

		 global $wpdb;

        if ( ! empty( $meta ) && is_array( $meta ) ) {
            
            $query = "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES ";
            
            $_records = [];
            
            foreach ( $meta as $meta_info ) {
                $_value = wp_slash( $meta_info->meta_value );
                $_records[] = "( $id, '{$meta_info->meta_key}', '{$_value}' )";
            }
            
            $query .= implode( ', ', $_records ) . ';';
            $wpdb->query( $query  );
        }


        $taxonomies = get_object_taxonomies( get_post_type($post) );
        
        if ( ! empty( $taxonomies ) && is_array( $taxonomies ) ) {
            foreach ( $taxonomies as $taxonomy ) {
                $terms = wp_get_object_terms( $post, $taxonomy, [ 'fields' => 'slugs' ] );
                wp_set_object_terms( $id, $terms, $taxonomy, false );
            }
        }


	}


	 /**
	 * Retorna un arreglo con todos los metadatos del post.
	 *
	 * @param int $post_id id del post.
	 *
	 * @return array $meta arreglo de metadatos.
	 */
	public static function get_meta_data( $post_id ) {

		global $wpdb;

        $meta = $wpdb->get_results(
            $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id )
        );

        if ( ! empty( $meta ) && is_array( $meta ) ) {
            
            return $meta;

        }


	}


	 /**
	 * Elimina todos los metadatos de un post
	 *
	 * @param int $post_id id del post.
	 *
	 * @return void.
	 */
	public static function delete_meta_data( $post_id ) {

		global $wpdb;

        $query = "DELETE FROM {$wpdb->postmeta} WHERE post_id =" . $post_id;
        $wpdb->query( $query );


	}

	/**
	 * Saves a post's publishing date.
	 *
	 * @param int  $post_id the post's id.
	 * @param post $post    the post being saved.
	 *
	 * @return mixed
	 */
	public static function save_meta( $post_id, $post ) {
		if ( $post->post_status === self::$_asc_publish_status || get_post_meta( $post_id, self::$_asc_publish_status . '_original', true ) ) {
			$nonce = AutomaticScheduledContent::$_asc_publish_status . '_nonce';
			$pub = AutomaticScheduledContent::$_asc_publish_status . '_pubdate';
			$stampchange = false;

			if ( isset( $_POST[ $nonce ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce ] ) ), basename( __FILE__ ) ) !== 1 ) {
				return $post_id;
			}
			if ( ! current_user_can( get_post_type_object( $post->post_type )->cap->edit_post, $post_id ) ) {
				return $post_id;
			}

			if ( isset( $_POST[ $pub ] ) && isset( $_POST[ $pub . '_time_hrs' ] ) && isset( $_POST[ $pub . '_time_mins' ] ) && ! empty( $_POST[ $pub ] ) ) {
				$tz = self::get_timezone_object();
				$stamp = DateTime::createFromFormat( 'd.m.Y H:i', sanitize_text_field( wp_unslash( $_POST[ $pub ] ) ) . ' ' . sanitize_text_field( wp_unslash( $_POST[ $pub . '_time_hrs' ] ) ) . ':' . sanitize_text_field( wp_unslash( $_POST[ $pub . '_time_mins' ] ) ), $tz )->getTimestamp(); // WPCS: XSS okay.
				if ( ! $stamp || $stamp <= time() ) {
					$stamp = strtotime( '+5 minutes' );
					$stampchange = true;
				}

				wp_clear_scheduled_hook( 'cus_publish_post', array(
					'ID' => $post_id,
				) );
				if ( ! $stampchange || AutomaticScheduledContent_Options::get( 'tsu_nodate' ) === 'publish' ) {
					update_post_meta( $post_id, $pub, $stamp );
					wp_schedule_single_event( $stamp, 'cus_publish_post', array(
						'ID' => $post_id,
					) );
				}
			}
		}
	}

	/**
	 * Publishes a scheduled update
	 *
	 * Copies the original post's contents and meta into it's "scheduled update" and then deletes
	 * the original post. This function is either called by wp_cron or if the user hits the
	 * 'publish now' action
	 *
	 * @param int $post_id the post's id.
	 *
	 * @return int the original post's id
	 */
	public static function publish_post( $post_id ) {

		$orig_id = get_post_meta( $post_id, self::$_asc_publish_status . '_original', true );
		
		// break early if given post is not an actual scheduled post created by this plugin.
		if ( ! $orig_id ) {
			return $post_id;
		}

		//Original
		$orig = get_post( $orig_id );

		//Copia
		$post = get_post( $post_id );
		
		
		/**
		 * Fires before a scheduled post is being updated
		 *
		 * @param WP_Post $post the scheduled update post.
		 * @param WP_post $orig the original post.
		 */
		do_action( 'AutomaticScheduledContent\\before_publish_post', $post, $orig );
		delete_post_meta( $post->ID, self::$_asc_publish_status . '_pubdate' );

		//self::copy_meta_and_terms(  $post->ID,  $orig->ID );

		//obtiene los metadatos del post modificado.
		$meta = self::get_meta_data( $post->ID );

		//elimina los metadatos del original.
		self::delete_meta_data( $orig_id );

		//inserta los metadatos actualizados
		self::update_meta_and_terms( $meta, $orig_id );

		$post->ID = $orig->ID;
		$post->post_name = $orig->post_name;
		$post->guid = $orig->guid;
		$post->post_parent = $orig->post_parent;
		$post->post_status = $orig->post_status;
		$post_date = date_i18n( 'Y-m-d H:i:s' );

		/**
		 * Filter the new posts' post date
		 *
		 * @param string  $post_date the date to be used, must be in the form of `Y-m-d H:i:s`.
		 * @param WP_Post $post      the scheduled update post.
		 * @param WP_Post $orig      the original post.
		 */
		$post_date = apply_filters( 'AutomaticScheduledContent\\publish_post_date', $post_date, $post, $orig );

		$post->post_date = $post_date; // we need this to get wp to recognize this as a newly updated post.
		$post->post_date_gmt = get_gmt_from_date( $post_date );

		//delete_post_meta( $orig->ID, self::$_asc_publish_status . '_original' );
		//delete_post_meta( $orig->ID, self::$_asc_publish_status . '_pubdate' );

		//Convertir la copia en el original
		wp_update_post( $post );

		//Borrar la copia
		wp_delete_post( $post_id, true );

		return $orig->ID;
	}

	/**
	 * Wrapper function for cron automated publishing
	 * disables the kses filters before and reenables them after the post has been published
	 *
	 * @param int $post_id the post's id.
	 *
	 * @return void
	 */
	public static function cron_publish_post( $post_id ) {
		kses_remove_filters();
		self::publish_post( $post_id );
		kses_init_filters();
	}


	/**
	 * Reformats a timestamp into human readable publishing date and time
	 *
	 * @see date_i18n, DateTime, AutomaticScheduledContent::get_timezone_object
	 *
	 * @param int $stamp unix timestamp to be formatted.
	 *
	 * @return string the formatted timestamp
	 */
	public static function get_pubdate( $stamp ) {
		$date = new DateTime( 'now', self::get_timezone_object() );
		$date->setTimestamp( $stamp );
		$offset = get_option( 'gmt_offset' ) * 3600;
		$str = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' \U\T\CO', $date->getTimestamp() + $offset );
		return $str;
	}
	
	/* bhullar custom code */
	public static function override_static_front_page_and_post_option($html, $arg)
	{
		$arg['post_status'] = array('publish', 'draft','future','asc_sc_publish');
		echo self::co_wp_dropdown_pages($arg);
		?>
		<style type="text/css">
			select#page_on_front, select#page_for_posts {float: right;margin-left: 10px;}
		</style>
		<?php
	}

	public static function co_wp_dropdown_pages( $args = '' )
	{
	    $defaults = array(
	        'depth'                 => 0,
	        'child_of'              => 0,
	        'selected'              => 0,
	        'echo'                  => 1,
	        'name'                  => 'page_id',
	        'id'                    => '',
	        'class'                 => '',
	        'show_option_none'      => '',
	        'show_option_no_change' => '',
	        'option_none_value'     => '',
	        'value_field'           => 'ID',
	    );
	 
	    $parsed_args = wp_parse_args( $args, $defaults );
	 
	    $pages  = get_pages( $parsed_args );
	    $output = '';
	    // Back-compat with old system where both id and name were based on $name argument
	    if ( empty( $parsed_args['id'] ) ) {
	        $parsed_args['id'] = $parsed_args['name'];
	    }
	 
	    if ( ! empty( $pages ) ) {
	        $class = '';
	        if ( ! empty( $parsed_args['class'] ) ) {
	            $class = " class='" . esc_attr( $parsed_args['class'] ) . "'";
	        }
	 
	        $output = "<select name='" . esc_attr( $parsed_args['name'] ) . "'" . $class . " id='" . esc_attr( $parsed_args['id'] ) . "'>\n";
	        if ( $parsed_args['show_option_no_change'] ) {
	            $output .= "\t<option value=\"-1\">" . $parsed_args['show_option_no_change'] . "</option>\n";
	        }
	        if ( $parsed_args['show_option_none'] ) {
	            $output .= "\t<option value=\"" . esc_attr( $parsed_args['option_none_value'] ) . '">' . $parsed_args['show_option_none'] . "</option>\n";
	        }
	        $output .= walk_page_dropdown_tree( $pages, $parsed_args['depth'], $parsed_args );
	        $output .= "</select>\n";
	    }
	 
	    /**
	     * Filters the HTML output of a list of pages as a drop down.
	     *
	     * @since 2.1.0
	     * @since 4.4.0 `$parsed_args` and `$pages` added as arguments.
	     *
	     * @param string $output      HTML output for drop down list of pages.
	     * @param array  $parsed_args The parsed arguments array.
	     * @param array  $pages       List of WP_Post objects returned by `get_pages()`
	     */
	    $html = apply_filters( 'co_wp_dropdown_pages', $output, $parsed_args, $pages );
	 
	    if ( $parsed_args['echo'] ) {
	        echo $html;
	    }
	    return $html;
	}

	public static function user_restriction_scheduled_content()
	{
		//ini_set('memory_limit', '256M');
		global $post;
		
		if(!current_user_can('administrator')){
			$asc_sc_publish_pubdate = get_post_meta($post->ID, 'asc_sc_publish_pubdate', true);
			if(!empty($asc_sc_publish_pubdate)){
				global $wp_query;
				$wp_query->set_404();
				status_header(404);
				get_template_part(404); 
				//wp_redirect( esc_url(get_permalink($post->post_parent)) 
				exit();
				/*if (!empty($post->post_parent) && get_post_status($post->post_parent) != 'publish') {
					global $wp_query;
					$wp_query->set_404();
					status_header(404);
					get_template_part(404); 
					//wp_redirect( esc_url(get_permalink($post->post_parent)) 
					exit(); 
				}*/
			}
		}
	}
}

add_action( 'save_post', array( 'AutomaticScheduledContent', 'save_meta' ), 10, 2 );
add_action( 'cus_publish_post', array( 'AutomaticScheduledContent', 'cron_publish_post' ) );

add_action( 'wp_ajax_load_pubdate', array( 'AutomaticScheduledContent', 'load_pubdate' ) );
add_action( 'init', array( 'AutomaticScheduledContent', 'init' ), PHP_INT_MAX );
add_action( 'admin_action_workflow_copy_to_publish', array( 'AutomaticScheduledContent', 'admin_action_workflow_copy_to_publish' ) );
add_action( 'admin_action_workflow_publish_now', array( 'AutomaticScheduledContent', 'admin_action_workflow_publish_now' ) );
add_action( 'transition_post_status', array( 'AutomaticScheduledContent', 'prevent_status_change' ), 10, 3 );

add_filter( 'display_post_states', array( 'AutomaticScheduledContent', 'display_post_states' ) );
add_filter( 'page_row_actions', array( 'AutomaticScheduledContent', 'page_row_actions' ), 10, 2 );
add_filter( 'post_row_actions', array( 'AutomaticScheduledContent', 'page_row_actions' ), 10, 2 );
add_filter( 'manage_pages_columns', array( 'AutomaticScheduledContent', 'manage_pages_columns' ) );
add_filter( 'page_attributes_dropdown_pages_args', array( 'AutomaticScheduledContent', 'parent_dropdown_status' ) );

/* bhullar custom code */
add_action('admin_init', function(){
	//add_filter( 'wp_dropdown_pages', array( 'AutomaticScheduledContent', 'override_static_front_page_and_post_option' ), 1 , 2);
});

add_action('admin_footer', function(){ ?>
	<style>div#ui-datepicker-div {
    z-index: 99 !important;
}</style>
	<?php
});

add_filter( 'template_redirect', array( 'AutomaticScheduledContent', 'user_restriction_scheduled_content' ), 1);
