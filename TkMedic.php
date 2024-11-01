<?php

/*
	Plugin Name: tenKinetic Medic
	Plugin URI: http://tenkinetic.com/wordpress-medic/
	Description: Get some CSS and JavaScript. Requires PHP >= 5.3.0 & MYSQL 5.6.5
	Author: tenKinetic
	Version: 1.0
	Author URI: http://tenkinetic.com
	Text Domain: tk-medic
	Domain Path: /lang
 */

include 'Classes/TkMedicConnectionFactory.php';
include 'Classes/TkMedicAdminPages.php';
include 'Classes/TkMedicLoader.php';
include 'Classes/TkMedicAdmin.php';
include 'Classes/TkMedicRepository.php';

global $tk_wpm_db_version;
$tk_wpm_db_version = '1.0.0';

global $tk_wpm_tables;
$tk_wpm_tables = ['tk_wpm_message','tk_wpm_patch_page','tk_wpm_patch','tk_wpm_parameter','tk_wpm_job','tk_wpm_account','tk_wpm_activity_log'];

global $requirements;
$requirements = array('mysql'=>'5.6.5','php'=>'5.3.0');

global $medic_api_base_url;
$medic_api_base_url = 'http://tenkinetic.com/wordpress-medic/api/';
// $medic_api_base_url = 'http://wp43.local/wordpress-medic/api/';

global $medic_payments_url;
$medic_payments_url = 'http://tenkinetic.com/wordpress-medic/payments/';
// $medic_payments_url = 'http://wp43.local/wordpress-medic/payments/';

class TkWordPressMedic
{
	protected $AdminPages;
  protected $Settings;
  protected $Loader;
  protected $Admin;

	protected $admin_notices;

	public $status = TkWordPressMedicPluginStatus::FailedRequirements;

	public function __construct()
	{
		// validate PHP & MYSQL
		$this->admin_notices = $this->validate_plugin();
		if ($this->admin_notices != '')
		{
			add_action( 'load-index.php',
		    function()
				{
		      add_action('admin_notices', array($this, 'render_notices'));
		    }
			);
			return;
		}

		$this->AdminPages = new TkWordPressMedicAdminPages;
		$this->Loader = new TkWordPressMedicLoader;
		$this->Admin = new TkWordPressMedicAdmin;

		// Add shortcode support for widgets

		add_filter('widget_text', 'do_shortcode');

		// register shortcodes

		// Allows HTML to be loaded inline as the page renders. Requires user to include the shortcode
		// with parameters: [tkwpm_shortcode_loader id=""]. MAY be used for CSS or JS but that would be
		// unusual and not the way to go. CSS & JS should use Loader::
		add_shortcode('tk_wpm_shortcode_loader',		array($this->Loader,		'tk_wpm_RenderShortcode'));

		// register AJAX

		// Plugin AJAX functions (only accessible when logged in to the CMS)
		add_action('wp_ajax_tk_wpm_Admin', array($this->Admin, 'tk_wpm_Admin'));

		// Plugin Public AJAX functions (also add to admin)
		add_action('wp_ajax_tk_wpm_Public', array($this->Admin, 'tk_wpm_Public'));
		add_action('wp_ajax_nopriv_tk_wpm_Public', array($this->Admin, 'tk_wpm_Public'));

		// Public AJAX for medic access
		// removed: account security will be far better if only the admin script is used
		// 					and all data is pulled by the plugin rather than the plugin pushing
		//					data to the plugin.
		//add_action('wp_ajax_nopriv_trak_Medic', array($this->Admin, 'tk_wpm_Medic'));

		// register the admin screen menu
		add_action('admin_menu', array($this->AdminPages, 'tk_wpm_menu'));

		// sync jobs
		//add_action('plugins_loaded', 'tk_wpm_sync');
		// do this at most every hour (this is mainly for paramedics who also have WPM installed
		// for testing. Having both on the same server will cause a sync loop)
		// also, only do this if the current user is an admin
		$last_sync = get_option('tk_wpm_last_sync');
		$sync_threshold = time() - 300 * 12;
		if ($last_sync < $sync_threshold)
		{
			add_action('wp_loaded', array($this, 'tk_wpm_sync'));
		}
		/*else
		{
			TKWordPressMedicAdmin::activity_log('info', 'job-sync', 'bypassing: last_sync:'.$last_sync.' threshold:'.$sync_threshold);
		}*/

		// set defaults
		if (!get_option('tk_wpm_patch_sync'))
		{
			update_option('tk_wpm_patch_sync', 'true');
		}

		$this->status = TkWordPressMedicPluginStatus::Healthy;
	}

	function validate_plugin()
	{
		global $requirements;

		$notices = '';

		// verify mysql & php versions
		$mysql_check = $this->verify_mysql();
		if (!$mysql_check['success'])
		{
			$notices .= TkWordPressMedic::plugin_message('Minimum version of MYSQL ('.$requirements['mysql'].') not met. Your version is '.$mysql_check['version'], E_USER_ERROR);
		}
		$php_check = $this->verify_php();
		if (!$php_check['success'])
		{
			$notices .= TkWordPressMedic::plugin_message('Minimum version of PHP ('.$requirements['php'].') not met. Your version is '.$php_check['version'], E_USER_ERROR);
		}
		// if (!$mysql_check['success'] || !$php_check['success'])
		// {
		// 	return;
		// }
		return $notices;
	}

	function render_notices()
	{
		echo $this->admin_notices;
	}

	function tk_wpm_sync()
	{
		if (current_user_can('manage_options'))
		{
			// all jobs that have been submitted but not completed, need to be synchronised
			// with the TK server. This is done if the job has not been updated for more than an hour.
			$sync_results = $this->Admin->sync_jobs(false);
			if (isset($sync_results['messages']))
			{
				foreach ($sync_results['messages'] as $message)
				{
					TKWordPressMedicAdmin::activity_log('error', 'job-sync', $message);
				}
			}
			if (isset($sync_results['job-messages']))
			{
				foreach ($sync_results['job-messages'] as $message)
				{
					TKWordPressMedicAdmin::activity_log('error', 'job-sync-processing', $message);
				}
			}
			if (isset($sync_results['updates-received']) && $sync_results['updates-received'])
			{
				// let the user know updates were received during an automatic sync
				TkWordPressMedic::plugin_message('Updates were received from the medic during automatic job sync.', 0);
			}
		}
	}

	public function add_database_tables()
	{
		global $wpdb;
		global $tk_wpm_db_version;
		global $tk_wpm_tables;

		ob_start();

		$sql_account = "CREATE TABLE tk_wpm_account (
			id int(11) unsigned NOT NULL AUTO_INCREMENT,
			create_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			account_key varchar(50) NOT NULL,
			site_url varchar(100) NOT NULL,
			email varchar(50) NOT NULL,
  		verified tinyint(1) NOT NULL DEFAULT '0',
			PRIMARY KEY  (id)
		) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;";
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql_account);

		// Create the account. If this is an upgrade, the account will exist.
		$existing_account = $wpdb->get_results("SELECT * FROM tk_wpm_account WHERE site_url = '".site_url()."'");
		if (count($existing_account) == 0)
		{
			$create_account_response = TkWordPressMedicAdmin::create_account();	// make sure the account key is generated by the medic server
			if (!$create_account_response['success'])
			{
				//TkWordPressMedic::plugin_message('Could not create account.', E_USER_ERROR);
				trigger_error('Could not create account: '.$create_account_response['diagnostic'],E_USER_ERROR);
			}
			else
			{
				$wpdb->insert(
					'tk_wpm_account',
					array(
						'account_key' => $create_account_response['account-key'],
						'site_url' => site_url(),
						'email' => get_option('admin_email')
					)
				);
			}
		}

		$sql_job = "CREATE TABLE tk_wpm_job (
			id int(11) unsigned NOT NULL AUTO_INCREMENT,
			create_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			job_key varchar(50) NOT NULL,
			name varchar(200) NOT NULL,
			description varchar(5000) NOT NULL DEFAULT '',
			url varchar(100),
			status varchar(20) NOT NULL DEFAULT '".TkWordPressMedicJobStatus::Created."',
			last_sync timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
  		UNIQUE KEY job_unique_key (job_key)
		) ENGINE=InnoDB DEFAULT CHARSET=latin1;";
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql_job);

		// add demo jobs if they dont already exist
		$existing_demo_jobs = $wpdb->get_results("SELECT * FROM tk_wpm_job WHERE name LIKE 'Demo:%'");
		if (count($existing_demo_jobs) == 0)
		{
			$wpdb->insert(
				'tk_wpm_job',
				array(
					'job_key' => TkWordPressMedic::unique_id(),
					'name' => 'Demo: Font increase',
					'description' => 'Increase text size in all paragraphs by 20%',
					'url' => null,
					'status' => TkWordPressMedicJobStatus::Demo
				)
			);
			// get the first ID of the batch which we'll need when we insert patches and parameters
			// the rest will be sequential
			$batch_id = $wpdb->insert_id;
			$wpdb->insert(
				'tk_wpm_job',
				array(
					'job_key' => TkWordPressMedic::unique_id(),
					'name' => 'Demo: Add daily message',
					'description' => 'I want a message displayed to visitors of my site. Once a user has dismissed a message it should not display again for them. I need to be able to change the message in my admin section. Once the message is changed it should be shown to all users including those that dismissed the last message.',
					'url' => null,
					'status' => TkWordPressMedicJobStatus::Demo
				)
			);
		}

		$sql_message = "CREATE TABLE tk_wpm_message (
			id int(11) unsigned NOT NULL AUTO_INCREMENT,
			create_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			job_id int(11) NOT NULL,
			message varchar(5000) NOT NULL DEFAULT '',
			is_medic tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'Unread',
			PRIMARY KEY  (id)
		) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;";
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql_message);

		$sql_patch = "CREATE TABLE tk_wpm_patch (
			id int(11) unsigned NOT NULL AUTO_INCREMENT,
			create_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			job_id int(11) NOT NULL,
			patch_key varchar(50) NOT NULL,
			patch_type varchar(4) NOT NULL,
			patch_description varchar(4000),
			content text CHARACTER SET utf8 COLLATE utf8_bin,
			is_active tinyint(1) NOT NULL DEFAULT 0,
			is_inline tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
  		UNIQUE KEY patch_unique_key (patch_key)
		) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;";
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql_patch);

		// add patches for demo jobs if they dont already exist
		$parameter_patch_key = TkWordPressMedic::unique_id();
		$existing_demo_patches = $wpdb->get_results("SELECT p.* FROM tk_wpm_patch p JOIN tk_wpm_job j ON p.job_id = j.id WHERE j.status = 'Demo'");
		if (count($existing_demo_patches) == 0)
		{
			$wpdb->insert(
				'tk_wpm_patch',
				array(
					'job_id' => $batch_id,
					'patch_key' => TkWordPressMedic::unique_id(),
					'patch_type' => 'css',
					'patch_description' => 'Demo: CSS font increase for P elements: 1.2em.',
					'content' => 'p{font-size:1.2em}'
				)
			);
			$wpdb->insert(
				'tk_wpm_patch',
				array(
					'job_id' => $batch_id+1,
					'patch_key' => TkWordPressMedic::unique_id(),
					'patch_type' => 'css',
					'patch_description' => 'Demo: Style for one-time message display',
					'content' => '#tk-wpm-demo-message-overlay{background-color:teal;color:#fff;position:absolute;top:50%;left:50%;padding:5px 10px;transform:translateX(-50%) translateY(-50%);-webkit-transform-origin-x:-50%;-webkit-transform-origin-y:-50%;border-radius:15px;cursor:pointer}'
				)
			);
			$wpdb->insert(
				'tk_wpm_patch',
				array(
					'job_id' => $batch_id+1,
					'patch_key' => $parameter_patch_key,
					'patch_type' => 'js',
					'patch_description' => 'Demo: JavaScript to drive the one-time message display',
					'content' => 'jQuery(function(a){var b={action:"tk_wpm_Public",command:"get-option","option-key":"tk-wpm-demo-site-message"};jQuery.post("/wp-admin/admin-ajax.php",b,function(b){var c=jQuery.parseJSON(b);if(c.success){var d=c["option-value"];if(""==d)return void(document.cookie="tk-wpm-demo-site-message=;domain="+document.domain+";path=/;");var e=c["option-hash"],f=document.cookie.match("(^|;) ?tk-wpm-demo-site-message=([^;]*)(;|$)")[2];if(f!=e){var g=a(\'<div id="tk-wpm-demo-message-overlay" title="Click to dismiss">\'+d+"</div>").appendTo("body");a(g).click(function(){a(this).remove(),document.cookie="tk-wpm-demo-site-message="+e+";domain="+document.domain+";path=/;"})}}})});'
				)
			);
		}

		$sql_parameter = "CREATE TABLE tk_wpm_parameter (
			id int(11) unsigned NOT NULL AUTO_INCREMENT,
			create_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			update_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			patch_key varchar(50) NOT NULL,
			name varchar(50) NOT NULL,
			description varchar(4000) NOT NULL,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;";
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql_parameter);

		// add parameters for demo jobs
		$existing_demo_patches = $wpdb->get_results("SELECT * FROM tk_wpm_parameter WHERE description LIKE 'Demo:%'");
		if (count($existing_demo_patches) == 0)
		{
			$wpdb->insert(
				'tk_wpm_parameter',
				array(
					'patch_key' => $parameter_patch_key,
					'name' => 'tk-wpm-demo-site-message',
					'description' => 'Demo: This message will be displayed to visitors until dismssed. Once changed it will display again.'
				)
			);
			// store a default value for the parameter
			update_option('tk-wpm-demo-site-message', 	'Welcome to '.get_bloginfo('name').'.<script>alert("Notice our message. Click on it to make it go away until we change it.");</script>');
		}

		$sql_patch_page = "CREATE TABLE tk_wpm_patch_page (
			id int(11) unsigned NOT NULL AUTO_INCREMENT,
			create_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			patch_id int(11) NOT NULL,
			page_id int(11) NOT NULL,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;";
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql_patch_page);

		$sql_activity_log = "CREATE TABLE tk_wpm_activity_log (
			id int(11) unsigned NOT NULL AUTO_INCREMENT,
			create_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			type varchar(50) NOT NULL,
			function varchar(255) NOT NULL,
			details varchar(4000),
			PRIMARY KEY  (id)
		) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;";
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql_activity_log);

		// if the tables haven't been created we have an activation error
		$installed_tables = $wpdb->get_results("SHOW TABLES LIKE 'tk_wpm_%'");
		if (count($installed_tables) != count($tk_wpm_tables))
		{
			TkWordPressMedic::plugin_message('Medic database install failed', E_USER_ERROR);
		}

		update_option('tk_wpm_db_version', $tk_wpm_db_version);

		// set the db connection options so we can use standard PDO
		update_option('tk_wpm_db_server', 	DB_HOST);
		update_option('tk_wpm_db_name', 		DB_NAME);
		update_option('tk_wpm_db_username', 	DB_USER);
		update_option('tk_wpm_db_password', 	DB_PASSWORD);

		if (ob_get_length() > 0)
		{
			trigger_error(ob_get_contents(),E_USER_ERROR);
		}
	}

	function upgrade_database()
	{
		global $tk_wpm_db_version;
		if (get_option('tk_wpm_db_version') != $tk_wpm_db_version )
		{
			$this->add_database_tables();
		}
	}

	static function remove_plugin()
	{
		global $wpdb, $tk_wpm_tables;

    // remove options

		// parameter data are stored as WP options, remove those by parameter name from DB
		$parameters = $wpdb->get_results("SELECT * FROM tk_wpm_parameter");
		foreach ($parameters as $parameter)
		{
			delete_option($parameter->name);
		}

    delete_option('tk_wpm_db_version');
    delete_option('tk_wpm_db_server');
    delete_option('tk_wpm_db_name');
    delete_option('tk_wpm_db_username');
    delete_option('tk_wpm_db_password');
		delete_option('tk_wpm_last_sync');
		delete_option('tk_wpm_patch_sync');

    // remove tables (if they exist)
		foreach ($tk_wpm_tables as $table)
		{
			$wpdb->query("DROP TABLE IF EXISTS {$table}");
		}
    // $wpdb->query("DROP TABLE IF EXISTS tk_wpm_message");
    // $wpdb->query("DROP TABLE IF EXISTS tk_wpm_patch_page");
    // $wpdb->query("DROP TABLE IF EXISTS tk_wpm_patch");
    // $wpdb->query("DROP TABLE IF EXISTS tk_wpm_parameter");
    // $wpdb->query("DROP TABLE IF EXISTS tk_wpm_job");
    // $wpdb->query("DROP TABLE IF EXISTS tk_wpm_account");
    // $wpdb->query("DROP TABLE IF EXISTS tk_wpm_activity_log");
	}

	public static function load_dynatable()
	{
		wp_register_script('dynatable-script', plugins_url('/JUI/jquery.dynatable.js', __FILE__), array('jquery'), '1.0', true);
		wp_register_style('dynatable-style', plugins_url('/JUI/jquery.dynatable.css', __FILE__));
		wp_enqueue_script('dynatable-script');
		wp_enqueue_style('dynatable-style');
	}

	public static function load_wpm()
	{
		wp_register_style('tk-wpm-admin-style', plugins_url('/Styles/TkMedic.css', __FILE__));
		wp_enqueue_style('tk-wpm-admin-style');

		wp_register_script('tk-wpm-admin-script', plugins_url('/Scripts/TkMedic.js', __FILE__));
		wp_enqueue_script('tk-wpm-admin-script');
	}

	public static function unique_id()
	{
		$bytes = openssl_random_pseudo_bytes(16);
		$hex   = bin2hex($bytes);
		return $hex;
	}

	function verify_mysql()
	{
		global $wpdb, $requirements;
		$mysql_client_version = $wpdb->db_version();
		$success = version_compare($wpdb->db_version(),$requirements['mysql']) >= 0;
		return array('success'=>$success,'version'=>$mysql_client_version);
	}

	function verify_php()
	{
		global $requirements;
		$php_version = phpversion();
		$success = version_compare(phpversion(),$requirements['php']) >= 0;
		return array('success'=>$success,'version'=>$php_version);
	}

	static function plugin_message($message, $errno)
	{
		// this will have to be styled manually since the plugin and its styles aren't going to be loaded
		return '<div style="text-align:justify;padding:15px;background-color:#aaaaaa;color:white;width:450px;border-radius:15px;margin:5px auto;">tenKinetic Medic unable to load: '.$message.'</div>';
  }
}

abstract class TkWordPressMedicPluginStatus
{
	const DatabaseConnectFailed = "Database connection failed";
	const Healthy = "Healthy";
	const FailedRequirements = "Failed minimum MYSQL or PHP requirements";
}

abstract class TkWordPressMedicJobStatus
{
	const Demo = "Demo";								// no sync
	const Created = "Created";					// set at plugin (no sync)
	const Submitted = "Submitted";			// set at plugin (send)
	const Declined = "Declined";				// set at TK (sync)
	const Quoted = "Quoted";						// set at TK (sync)
	const Paid = "Paid";								// set at TK (sync)
	const InProgress = "InProgress";		// set at TK (sync)
	const Completed = "Completed";			// set at TK (sync)
	const Accepted = "Accepted";				// set at plugin (send)
}

abstract class TkWordPressMedicJobPriority
{
	const High = "High";
	const Medium = "Medium";
	const Low = "Low";
}

abstract class TkWordPressMedicMessageStatus
{
	const Unread = "Unread";
	const Read = "Read";
}

abstract class TkWordPressMedicPatchType
{
	const Css = "css";
	const Js = "js";
	const Html = "html";
}

$wpTkWordPressMedic = new TkWordPressMedic;

if ($wpTkWordPressMedic->status == TkWordPressMedicPluginStatus::Healthy)
{
	// activation
	register_activation_hook(__FILE__, array($wpTkWordPressMedic, 'add_database_tables'));
	// check for DB upgrade
	add_action('plugins_loaded', array($wpTkWordPressMedic, 'upgrade_database'));
}

register_uninstall_hook(__FILE__, array('TkWordPressMedic', 'remove_plugin'));

?>
