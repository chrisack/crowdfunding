<?php
/**
 * Plugin Name: Crowd Funding by AppThemer
 * Plugin URI:  https://github.com/AppThemer/crowdfunding/
 * Description: A crowd funding platform in the likes of Kickstarter and Indigogo
 * Author:      AppThemer
 * Author URI:  http://appthemer.com
 * Version:     0.8.1
 * Text Domain: atcf
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/** Check if Easy Digital Downloads is active */
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

/**
 * Main Crowd Funding Class
 *
 * @since Appthemer CrowdFunding 0.1-alpha
 */
final class ATCF_CrowdFunding {

	/**
	 * @var crowdfunding The one true AT_CrowdFunding
	 */
	private static $instance;

	/**
	 * Main Crowd Funding Instance
	 *
	 * Ensures that only one instance of Crowd Funding exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since Appthemer CrowdFunding 0.1-alpha
	 *
	 * @return The one true Crowd Funding
	 */
	public static function instance() {
		if ( ! isset ( self::$instance ) ) {
			self::$instance = new ATCF_CrowdFunding;
			self::$instance->setup_globals();
			self::$instance->includes();
			self::$instance->setup_actions();
		}

		return self::$instance;
	}

	/** Private Methods *******************************************************/

	/**
	 * Set some smart defaults to class variables. Allow some of them to be
	 * filtered to allow for early overriding.
	 *
	 * @since Appthemer CrowdFunding 0.1-alpha
	 *
	 * @return void
	 */
	private function setup_globals() {
		/** Versions **********************************************************/

		$this->version    = '0.3-alpha';
		$this->db_version = '1';

		/** Paths *************************************************************/

		$this->file         = __FILE__;
		$this->basename     = apply_filters( 'atcf_plugin_basenname', plugin_basename( $this->file ) );
		$this->plugin_dir   = apply_filters( 'atcf_plugin_dir_path',  plugin_dir_path( $this->file ) );
		$this->plugin_url   = apply_filters( 'atcf_plugin_dir_url',   plugin_dir_url ( $this->file ) );

		$this->template_url = apply_filters( 'atcf_plugin_template_url', 'crowdfunding/' );

		// Includes
		$this->includes_dir = apply_filters( 'atcf_includes_dir', trailingslashit( $this->plugin_dir . 'includes'  ) );
		$this->includes_url = apply_filters( 'atcf_includes_url', trailingslashit( $this->plugin_url . 'includes'  ) );

		// Languages
		$this->lang_dir     = apply_filters( 'atcf_lang_dir',     trailingslashit( $this->plugin_dir . 'languages' ) );

		/** Misc **************************************************************/

		$this->domain       = 'atcf'; 
	}

	/**
	 * Include required files.
	 *
	 * @since Appthemer CrowdFunding 0.1-alpha
	 *
	 * @return void
	 */
	private function includes() {
		require( $this->includes_dir . 'settings.php' );
		require( $this->includes_dir . 'campaign.php' );
		require( $this->includes_dir . 'theme-stuff.php' );
		require( $this->includes_dir . 'shipping.php' );
		require( $this->includes_dir . 'logs.php' );
		require( $this->includes_dir . 'export.php' );
		require( $this->includes_dir . 'roles.php' );
		require( $this->includes_dir . 'shortcode-submit.php' );
		require( $this->includes_dir . 'shortcode-profile.php' );

		do_action( 'atcf_include_files' );

		if ( ! is_admin() )
			return;

		do_action( 'atcf_include_admin_files' );
	}

	/**
	 * Setup the default hooks and actions
	 *
	 * @since Appthemer CrowdFunding 0.1-alpha
	 *
	 * @return void
	 */
	private function setup_actions() {
		add_action( 'init', array( $this, 'is_edd_activated' ), 1 );

		add_filter( 'template_include', array( $this, 'template_loader' ) );
		add_action( 'init', array( $this, 'endpoints' ) );
		
		do_action( 'atcf_setup_actions' );

		$this->load_textdomain();
	}

	
	/**
	 * Easy Digital Downloads
	 *
	 * @since Appthemer CrowdFunding 0.2-alpha
	 *
	 * @return void
	 */
	function is_edd_activated() {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			if ( is_plugin_active( $this->basename ) ) {
				deactivate_plugins( $this->basename );
				unset ($_GET[ 'activate' ] ); // Ghetto

				add_action( 'admin_notices', array( $this, 'edd_notice' ) );
			}
		}
	}

	/**
	 * Admin notice.
	 *
	 * @since Appthemer CrowdFunding 0.2-alpha
	 *
	 * @return void
	 */
	function edd_notice() {
?>
		<div class="updated">
			<p><?php printf( 
						__( '<strong>Notice:</strong> Crowdfunding by AppThemer requires <a href="%s">Easy Digital Downloads</a> in order to function properly.', 'atcf' ), 
						wp_nonce_url( network_admin_url( 'update.php?action=install-plugin&plugin=easy-digital-downloads' ), 'install-plugin_easy-digital-downloads' )
				); ?></p>
		</div>
<?php
	}

	/**
	 * Add Endpoint for backers. This allows us to monitor
	 * the query to create "fake" URLs for seeing backers.
	 *
	 * @since Appthemer CrowdFunding 0.1-alpha
	 *
	 * @return void
	 */
	function endpoints() {
		add_rewrite_endpoint( 'backers', EP_PERMALINK | EP_PAGES );
		add_rewrite_endpoint( 'edit', EP_PERMALINK | EP_PAGES );
	}

	/**
	 * Load a template.
	 *
	 * Handles template usage so that we can use our own templates instead of the themes.
	 *
	 * Templates are in the 'templates' folder. AT_CrowdFunding looks for theme
	 * overides in /theme_directory/crowdfunding/ by default
	 *
	 * @see https://github.com/woothemes/woocommerce/blob/master/woocommerce.php
	 *
	 * @access public
	 * @param mixed $template
	 * @return string $template The path of the file to include
	 */
	public function template_loader( $template ) {
		global $wp_query;
		
		$find  = array();
		$files = array();

		if ( isset ( $wp_query->query_vars[ 'edit' ] ) && is_singular( 'download' ) && ( $wp_query->queried_object->post_author == get_current_user_id() || current_user_can( 'manage_options' ) ) ) {
			$files = apply_filters( 'atcf_crowdfunding_templates_edit', array( 'single-campaign-edit.php' ) );
		} else if ( isset ( $wp_query->query_vars[ 'backers' ] ) && is_singular( 'download' ) ) {
			$files = apply_filters( 'atcf_crowdfunding_templates_backers', array( 'single-campaign-backers.php' ) );
		} else if ( is_singular( 'download' ) ) {
			$files = apply_filters( 'atcf_crowdfunding_templates_campaign', array( 'single-campaign.php', 'single-download.php', 'single.php' ) );
		} else if ( is_post_type_archive( 'download' ) ) {
			$files = apply_filters( 'atcf_crowdfunding_templates_archive', array( 'archive-campaigns.php', 'archive-download.php', 'archive.php' ) );
		}

		foreach ( $files as $file ) {
			$find[] = $file;
			$find[] = $this->template_url . $file;
		}

		if ( ! empty( $files ) ) {
			$template = locate_template( $find );

			if ( ! $template ) 
				$template = $this->plugin_dir . '/templates/' . $file;
		}

		return $template;
	}

	/**
	 * Loads the plugin language files
	 *
	 * @since Appthemer CrowdFunding 0.1-alpha
	 */
	public function load_textdomain() {
		// Traditional WordPress plugin locale filter
		$locale        = apply_filters( 'plugin_locale', get_locale(), $this->domain );
		$mofile        = sprintf( '%1$s-%2$s.mo', $this->domain, $locale );

		// Setup paths to current locale file
		$mofile_local  = $this->lang_dir . $mofile;
		$mofile_global = WP_LANG_DIR . '/' . $this->domain . '/' . $mofile;

		// Look in global /wp-content/languages/atcf folder
		if ( file_exists( $mofile_global ) ) {
			return load_textdomain( $this->domain, $mofile_global );

		// Look in local /wp-content/plugins/appthemer-crowdfunding/languages/ folder
		} elseif ( file_exists( $mofile_local ) ) {
			return load_textdomain( $this->domain, $mofile_local );
		}

		return false;
	}
}

/**
 * The main function responsible for returning the one true Crowd Funding Instance
 * to functions everywhere.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 * Example: <?php $crowdfunding = crowdfunding(); ?>
 *
 * @since Appthemer CrowdFunding 0.1-alpha
 *
 * @return The one true Crowd Funding Instance
 */
function crowdfunding() {
	return ATCF_CrowdFunding::instance();
}
crowdfunding();