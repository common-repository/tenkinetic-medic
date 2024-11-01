<?php

class TkWordPressMedicAdminPages
{
	// database
	protected $connectionFactory;
	protected $pdo;

	public $status;

	public function __construct(TkWordPressMedicConnectionFactory $factory = null)
	{
		$this->status = TkWordPressMedicPluginStatus::Healthy;

			if (!$factory)
			{
					$factory = new TkWordPressMedicConnectionFactory;
			}
			$this->connectionFactory = $factory;

			$this->pdo = $this->connectionFactory->getConnection();
		if (!$this->pdo)
		{
			$this->status = TkWordPressMedicPluginStatus::DatabaseConnectFailed;
			return;
		}

		add_action('admin_enqueue_scripts', array($this, 'load_admin_components'));
	}

	public function load_admin_components()
	{
		TkWordPressMedic::load_dynatable();
		TkWordPressMedic::load_wpm();
	}

	private function get_new_message_count()
	{
		$statement = $this->pdo->prepare("SELECT COUNT(*) FROM tk_wpm_message WHERE status = :unread_status AND is_medic = 1");
		$sql_status = $statement->execute(
			array(
				':unread_status' => TkWordPressMedicMessageStatus::Unread
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-new-message-count', 'Could not get unread message count. '.$error[2]);
			return 0;
		}
		$count = $statement->fetchColumn();
		return $count;
	}

	private function get_job_new_message_count($job_id)
	{
		$statement = $this->pdo->prepare("SELECT COUNT(*) FROM tk_wpm_message WHERE status = :unread_status AND job_id = :job_id AND is_medic = 1");
		$sql_status = $statement->execute(
			array(
				':unread_status' => TkWordPressMedicMessageStatus::Unread,
				':job_id' => $job_id
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-new-message-count', 'Could not get unread message count. '.$error[2]);
			return 0;
		}
		$count = $statement->fetchColumn();
		return $count;
	}

	function tk_wpm_menu()
  {
  	// get new message count
  	$new_message_count = $this->get_new_message_count();
  	$new_message_html = $new_message_count == 0 ? '<div class="tk-wpm-new-item-placeholder" title="No new messages"></div>' : '<div class="tk-wpm-new-item-count" title="'.$new_message_count.($new_message_count > 1 ? ' new messages':' new message').'">'.$new_message_count.'</div>';

  	add_menu_page('tk-wpm', 'tenKinetic Medic', 'manage_options', 'tk-wpm', array($this, 'tk_wpm_overview'), plugins_url('../Images/wpm-logo-greyscale-20.png', __FILE__));

		add_submenu_page('tk-wpm',						'Overview',					'Overview'.$new_message_html,					'manage_options',	'tk-wpm',							array($this, 'tk_wpm_overview'));
		//add_submenu_page('tk-wpm',						'Patch Repository',	'Patch Repository'.$new_message_html,	'manage_options',	'tk-wpm-repository',	array($this, 'tk_wpm_repository'));
		add_submenu_page('tk-wpm', 						'Usage', 				'Usage', 									'manage_options', 'tk-wpm-usage', 			array($this, 'tk_wpm_usage'));
		add_submenu_page('tk-wpm', 						'Activity Log', 'Activity Log',						'manage_options', 'tk-wpm-activity', 		array($this, 'tk_wpm_activity'));
		add_submenu_page('tk-wpm',						'Support',			'Support',								'manage_options',	'tk-wpm-support',			array($this, 'tk_wpm_support'));
	}

	private function get_jobs()
	{
		$statement = $this->pdo->prepare("SELECT j.*, (SELECT COUNT(*) FROM tk_wpm_message m WHERE m.job_id = j.id) AS messages_total,
			(SELECT COUNT(*) FROM tk_wpm_message m WHERE m.job_id = j.id AND m.status = 'Unread' AND m.is_medic = 1) AS messages_unread,
			(SELECT COUNT(*) FROM tk_wpm_patch p WHERE p.job_id = j.id AND p.is_active = 1) AS active_patches,
			(SELECT COUNT(*) FROM tk_wpm_patch p WHERE p.job_id = j.id AND p.is_active = 0) AS inactive_patches,
			(SELECT COUNT(*) FROM tk_wpm_parameter m WHERE m.patch_key IN (SELECT patch_key FROM tk_wpm_patch WHERE job_id = j.id)) AS parameter_count
			FROM tk_wpm_job j
			ORDER BY j.create_date DESC");
		$sql_status = $statement->execute();
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-jobs', 'Could not get jobs. '.$error[2]);
			return [];
		}
		$jobs = $statement->fetchAll(PDO::FETCH_ASSOC);
		return $jobs;
	}

	private function get_job($job_id)
	{
		$statement = $this->pdo->prepare("SELECT * FROM tk_wpm_job WHERE id = :job_id");
		$sql_status = $statement->execute(
			array(
				':job_id' => $job_id
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-job', 'Could not get job. '.$error[2]);
			return null;
		}
		$job = $statement->fetch(PDO::FETCH_ASSOC);
		return $job;
	}

	private function get_account()
	{
		$statement = $this->pdo->prepare("SELECT * FROM tk_wpm_account LIMIT 1");
		$sql_status = $statement->execute();
		$account = $statement->fetch(PDO::FETCH_ASSOC);
		return $account;
	}

	private function set_account_verified($account)
	{
		$statement = $this->pdo->prepare("UPDATE tk_wpm_account SET verified = 1 WHERE account_key = :account_key");
		$sql_status = $statement->execute(
			array(
				':account_key' => $account['account_key']
			)
		);
	}

	private function validate_migrated_job_key($job_key)
	{
		// if the key doesn't exist return it, if it does return a new unique one.
		// In order to be able to sync these migrated jobs, the key must come from the
		// medic server.
		$key_is_unique = false;
		while (!$key_is_unique && $job_key != null)
		{
			$statement = $this->pdo->prepare("SELECT COUNT(*) FROM tk_wpm_job WHERE job_key = :job_key");
			$sql_status = $statement->execute(array(':job_key'=>$job_key));
			if (!$sql_status)
			{
				$error = $statement->errorInfo();
				TKWordPressMedicAdmin::activity_log('error', 'validate-migrated-job-key', 'Could not validate key: '.$error[2]);
				$job_key = null;
			}
			$existing_key_count = $statement->fetch(PDO::FETCH_COLUMN);
			$key_is_unique = $existing_key_count == 0;
			if (!$key_is_unique)
			{
				$job_key = TkWordPressMedicAdmin::request_job_key($job_key);
			}
		}
		return $job_key;
	}

	private function insert_migrated_data($migrated_data)
	{
		//var_dump(json_encode($migrated_data));
		foreach ($migrated_data as $job)
		{
			$this->pdo->beginTransaction();

			// insert the job (ensure the key which is from the old account is unique)
			$job['job_key'] = $this->validate_migrated_job_key($job['job_key']);
			if ($job['job_key'] == null)
			{
				// something went wrong and that will have been logged
				$this->pdo->rollBack();
				return;
			}

			// insert the job
			$statement = $this->pdo->prepare("INSERT INTO tk_wpm_job (create_date,job_key,name,description,url,status)
				VALUES (:create_date, :job_key, :name, :description, :url, :status)");
			$sql_status = $statement->execute(
				array(
					':create_date' => $job['create_date'],
					':job_key' => $job['job_key'],
					':name' => $job['name'],
					':description' => $job['description'],
					':url' => $job['url'],
					':status' => $job['status']
				)
			);
			if (!$sql_status)
			{
				$this->pdo->rollBack();
				$error = $statement->errorInfo();
				TKWordPressMedicAdmin::activity_log('error', 'insert-migrated-data', 'Could not insert job: '.$error[2]);
				return;
			}

			// insert messages
			foreach ($job['messages'] as $message)
			{
				$statement = $this->pdo->prepare("INSERT INTO tk_wpm_message (job_id,message,is_medic,status)
					VALUES ((SELECT id FROM tk_wpm_job WHERE job_key = :job_key), :message, true, :status)");
				$sql_status = $statement->execute(
					array(
						':job_key' => $job['job_key'],
						':message' => $message['message'],
						':status' => TkWordPressMedicMessageStatus::Read
					)
				);
				if (!$sql_status)
				{
					$this->pdo->rollBack();
					$error = $statement->errorInfo();
					TKWordPressMedicAdmin::activity_log('error', 'insert-migrated-data', 'Could not insert message data: '.$error[2]);
					return;
				}
			}

			// insert patches
			foreach ($job['patches'] as $patch)
			{
				$statement = $this->pdo->prepare("INSERT INTO tk_wpm_patch (job_id,patch_key,patch_type,patch_description,content)
					VALUES ((SELECT id FROM tk_wpm_job WHERE job_key = :job_key),:patch_key,:patch_type,:patch_description,:content)");
				$sql_status = $statement->execute(
					array(
						':job_key' => $job['job_key'],
						':patch_key' => $patch['patch_key'],
						':patch_type' => $patch['patch_type'],
						':patch_description' => $patch['patch_description'],
						':content' => $patch['content']
					)
				);
				if (!$sql_status)
				{
					$this->pdo->rollBack();
					$error = $statement->errorInfo();
					TKWordPressMedicAdmin::activity_log('error', 'insert-migrated-data', 'Could not insert patch data: '.$error[2]);
					return;
				}
			}

			// insert parameters
			foreach ($job['parameters'] as $parameter)
			{
				$statement = $this->pdo->prepare("INSERT INTO tk_wpm_parameter (patch_key,name,description)
					VALUES (:patch_key,:name,:description)");
				$sql_status = $statement->execute(
					array(
						':patch_key' => $parameter['patch_key'],
						':name' => $parameter['name'],
						':description' => $parameter['description']
					)
				);
				if (!$sql_status)
				{
					$this->pdo->rollBack();
					$error = $statement->errorInfo();
					TKWordPressMedicAdmin::activity_log('error', 'insert-migrated-data', 'Could not insert parameter data: '.$error[2]);
					return;
				}
				// set the default
				update_option($parameter['name'], 	$parameter['default_value']);
			}

			$this->pdo->commit();
		}
	}

	private function get_messages($job_id)
	{
		$statement = $this->pdo->prepare("SELECT * FROM tk_wpm_message WHERE job_id = :job_id ORDER BY create_date DESC");
		$sql_status = $statement->execute(
			array(
				':job_id' => $job_id
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-messages', 'Could not get messages for job '.$job_id.': '.$error[2]);
			return [];
		}
		$messages = $statement->fetchAll(PDO::FETCH_ASSOC);
		return $messages;
	}

	private function get_activity()
	{
		$statement = $this->pdo->prepare("SELECT * FROM tk_wpm_activity_log ORDER BY create_date DESC, id DESC");
		$sql_status = $statement->execute();
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-activity', 'Could not get activity. '.$error[2]);
			return [];
		}
		$activity = $statement->fetchAll(PDO::FETCH_ASSOC);
		return $activity;
	}

	private function get_patches($job_id)
	{
		$statement = $this->pdo->prepare("SELECT p.*, (SELECT name FROM tk_wpm_job WHERE id = p.job_id) as job_name,
			(SELECT COUNT(*) FROM tk_wpm_patch_page WHERE patch_id = p.id) as page_selection_count,
			(SELECT COUNT(*) FROM tk_wpm_parameter WHERE patch_key = p.patch_key) as parameter_count
			FROM tk_wpm_patch p ORDER BY create_date DESC");
		$sql_status = $statement->execute();
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-patches', 'Could not get patches. '.$error[2]);
			return [];
		}
		$patches = $statement->fetchAll(PDO::FETCH_ASSOC);
		if ($job_id != null)
		{
			$patches = array_filter($patches, function($el) use($job_id){return $el['job_id'] == $job_id;});
		}
		return $patches;
	}

	function sanitiseSetting($input) { return $input; }

	/* OBJECT VIEW */

	function tk_wpm_overview()
	{
		if (!current_user_can('manage_options'))
		{
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		global $medic_api_base_url, $medic_payments_url;
		$jobs = $this->get_jobs();
		$unaccepted = array_filter($jobs, function($el){return $el['status'] != TkWordPressMedicJobStatus::Accepted && $el['status'] != TkWordPressMedicJobStatus::Demo;});
		$accepted = array_filter($jobs, function($el){return $el['status'] == TkWordPressMedicJobStatus::Accepted;});
		$demo = array_filter($jobs, function($el){return $el['status'] == TkWordPressMedicJobStatus::Demo;});
		$account = $this->get_account();
		?>
		<script>
		jQuery(document).ready(function()
		{
			jQuery('.tk-wpm-job-messages').click(function()
			{
				// get messages and display message manager
				var container = jQuery(this).closest('.tk-wpm-job');
				var status = jQuery('.tk-wpm-job-status span', container).html();
				var manager_panel = jQuery('.tk-wpm-job-manager', container);
				jQuery(manager_panel).html('<div class="tk-wpm-job-manager-header"><div>Messages (newest first)</div><img class="tk-wpm-job-manager-close" src="<?php echo plugins_url('../Images/icon-close.png', __FILE__) ?>" alt="Close messages" title="Close messages" /><img class="tk-wpm-job-manager-expand" data-expand-state="expand" src="<?php echo plugins_url('../Images/icon-expand.png', __FILE__) ?>" alt="Expand messages" title="Expand messages" /></div>');
				if (status != '<?php echo TkWordPressMedicJobStatus::Created ?>' && status != '<?php echo TkWordPressMedicJobStatus::Accepted ?>' && status != '<?php echo TkWordPressMedicJobStatus::Demo ?>')
				{
					var submit_message = jQuery('<img class="tk-wpm-job-manager-send-message" src="<?php echo plugins_url('../Images/icon-submit.png', __FILE__) ?>" alt="Send message" title="Send message" />').appendTo('.tk-wpm-job-manager-header', manager_panel);
					var new_message = jQuery('<textarea class="tk-wpm-job-new-message" placeholder="New message"></textarea>').appendTo(manager_panel);
				}
				var content = jQuery('<div class="tk-wpm-job-manager-content"></div>').appendTo(manager_panel);
				var data = {
					'action':'tk_wpm_Admin',
					'command':'get-messages',
					'job-key':jQuery(this).attr('data-job-key')
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						if (jsonResults['messages'].length == 0)
						{
							jQuery('.tk-wpm-job-manager-content',container).append('<div class="tk-wpm-job-messages-none">No messages</div>');
						}
						jQuery(jsonResults['messages']).each(function()
						{
							jQuery('.tk-wpm-job-manager-content',container).append('<div class="tk-wpm-message-type-'+this.is_medic+'" data-message-status="'+this.status+'" data-message-id="'+this.id+'">'+(this.is_medic == 0 ? 'You: ' : 'Medic: ')+this.message+'</div>');
						});
					}
					else
					{
						jQuery(manager_panel).append('<div class="tk-wpm-job-manager-content">'+jsonResults['diagnostic']+'</div>');
					}
				});
			});
			jQuery('.tk-wpm-job-patches').click(function()
			{
				// get patches and display patch manager
				var container = jQuery(this).closest('.tk-wpm-job');
				var manager_panel = jQuery('.tk-wpm-job-manager', container);
				jQuery(manager_panel).html('<div class="tk-wpm-job-manager-header"><div>Patches</div> <img class="tk-wpm-job-manager-close" src="<?php echo plugins_url('../Images/icon-close.png', __FILE__) ?>" alt="Close patches" title="Close patches" /><img class="tk-wpm-job-manager-expand" data-expand-state="expand" src="<?php echo plugins_url('../Images/icon-expand.png', __FILE__) ?>" alt="Expand patches" title="Expand patches" /><img class="tk-wpm-job-manager-save-patches" src="<?php echo plugins_url('../Images/icon-save.png', __FILE__) ?>" alt="Save patches" title="Save patches" /></div>');
				var data = {
					'action':'tk_wpm_Admin',
					'command':'get-patches',
					'job-key':jQuery(this).attr('data-job-key')
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						var content = jQuery('<div class="tk-wpm-job-manager-content"></div>').appendTo(manager_panel);
						var item_index = 1;
						var page_selector = '<div class="tk-wpm-job-patch-page-selector"><div>Pages (selecting none will apply to all):</div><?php $args = array('post_type' => 'page', 'name' => 'tk-wpm-job-patch-pages-pidx', 'sort_column' => 'menu_order, post_title', 'echo' => 1); ob_start(); wp_dropdown_pages($args); $control = preg_replace('/\r|\n/', '', str_replace("'", '"', ob_get_contents())); ob_end_clean(); echo $control; ?></div>';
						if (jsonResults['patches'] == null || jsonResults['patches'].length == 0)
						{
							jQuery('<div>No patches</div>').appendTo(content);
						}
						jQuery(jsonResults['patches']).each(function()
						{
							var patch = jQuery('<div class="tk-wpm-job-patch" data-patch-id="'+this.id+'"></div>').appendTo(content);
							var patch_number = jQuery('<div class="tk-wpm-job-manager-number tk-wpm-job-text-invert">'+item_index+'</div>').appendTo(patch);
							var patch_remove = jQuery('<img class="tk-wpm-job-patch-remove" alt="Remove patch" src="<?php echo plugins_url('../Images/icon-delete.png', __FILE__) ?>" />').appendTo(patch);
							var patch_description = jQuery('<div class="tk-wpm-job-patch-description">Description:<br/>'+this.patch_description+'</div>').appendTo(patch);
							var patch_type = jQuery('<div class="tk-wpm-job-patch-type">Type: '+this.patch_type+'</div>').appendTo(patch);
							var patch_enable = jQuery('<div class="tk-wpm-job-patch-enable">Enable this patch: <input class="tk-wpm-job-patch-enable" type="checkbox" '+(this.is_active == 1 ? 'checked="checked"' : '')+'/></div>').appendTo(patch);
							var patch_pages_editor = jQuery(page_selector.replace(/pidx/g,item_index++)).appendTo(patch);
							//var apply_to_all = jQuery('<div>Apply changes to all<br/>patches in this job: <input class="tk-wpm-job-patch-apply-to-all" type="checkbox" checked="checked"/></div>').appendTo(patch);
							var patch_parameters = jQuery('<div class="tk-wpm-job-patch-parameters"><div>Parameters:</div></div>').appendTo(patch);
							if (this.parameters.length == 0)
							{
								jQuery('<div>There are no parameters for this patch</div>').appendTo(patch_parameters);
							}
							for (var i = 0; i < this.parameters.length; i++)
							{
								jQuery('<div>'+this.parameters[i].parameter_description+'<textarea data-parameter-name="'+this.parameters[i].parameter_name+'">'+this.parameters[i].parameter_value+'</textarea></div>').appendTo(patch_parameters);
							}

							// configure the page selector
							// turn the WP page dropdown into a multiple selection box
							jQuery('select', patch_pages_editor).attr('multiple','multiple');
							jQuery('select', patch_pages_editor).attr('size', jQuery('option', patch_pages_editor).length > 12 ? 12 : jQuery('option', patch_pages_editor).length);

							// apply pre-selections to the drop down
							jQuery('option', patch_pages_editor).removeAttr('selected');
							var pages = this.pages;
							jQuery('option', patch_pages_editor).each(function()
							{
								if (jQuery.inArray(jQuery(this).val(), pages) > -1)
								{
									jQuery(this).attr('data-test','test');
									jQuery(this).attr('selected', 'selected');
								}
							});
						});
					}
					else
					{
						jQuery(manager_panel).append('<div class="tk-wpm-job-manager-content">'+jsonResults['diagnostic']+'</div>');
					}
				});
			});
			jQuery('#tk-wpm-job-create').click(function()
			{
				jQuery('.tk-wpm-job-form').show();
				jQuery('.tk-wpm-job-create-name').focus();
			});
			jQuery('#tk-wpm-job-cancel').click(function()
			{
				jQuery('.tk-wpm-job-form').hide();
			});
			jQuery('#tk-wpm-job-submit').click(function()
			{
				var form = jQuery(this).closest('.tk-wpm-job-form');
				var data = {
					'action':'tk_wpm_Admin',
					'command':'create-job',
					'name':jQuery('.tk-wpm-job-create-name', form).val(),
					'description':jQuery('.tk-wpm-job-create-description', form).val(),
					'url':jQuery('.tk-wpm-job-create-url', form).val()
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						document.location.reload();
					}
					else
					{
						jQuery('.tk-wpm-notifications').first().html(jsonResults['diagnostic']);
					}
				});
			});
			// when toggling patches, if apply-to-all enabled, make sure all the other patches have the same value
			jQuery(document).on('click', 'input.tk-wpm-job-patch-enable', function()
			{
				var patch = jQuery(this).closest('.tk-wpm-job-patch');
				//var apply_to_all = jQuery('.tk-wpm-job-patch-apply-to-all', patch)[0].checked;
				var apply_to_all = jQuery('#tk-wpm-patch-behaviour')[0].checked;
				if (apply_to_all)
				{
					var container = jQuery(this).closest('.tk-wpm-job-manager-content');
					var checked = this.checked;
					jQuery('.tk-wpm-job-patch-enable', container).each(function()
					{
						this.checked = checked;
					});
				}
			});
			jQuery(document).on('click', '.tk-wpm-job-patch-remove', function()
			{
				var patch = jQuery(this).closest('.tk-wpm-job-patch');
				var job = jQuery(this).closest('.tk-wpm-job');
				confirm('Are you sure?', function()
				{
					var data = {
						'action':'tk_wpm_Admin',
						'command':'remove-patch',
						'patch-id':jQuery(patch).attr('data-patch-id'),
					};
					jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
					{
						var jsonResults = jQuery.parseJSON(response);
						if (jsonResults['success'])
						{
							jQuery(patch).remove();
							// re-number the remaining patches
							var counter = 1;
							jQuery('.tk-wpm-job-patch .tk-wpm-job-manager-number', job).each(function()
							{
								jQuery(this).html(counter++);
							});
							// update the active patch indicator
							// var patch_counts = jQuery('.tk-wpm-job-managers .tk-wpm-job-patches div', job).html().split(' / ');
							// patch_counts[0] = parseInt(patch_counts[0])-1;
							// patch_counts[1] = parseInt(patch_counts[1])-1;
							// jQuery('.tk-wpm-job-managers .tk-wpm-job-patches div', job).html(patch_counts[0]+' / '+patch_counts[1]);
							var total_patches = jQuery('.tk-wpm-job-patch', job).length;
							var active_patches = jQuery('input.tk-wpm-job-patch-enable:checked', job).length
							jQuery('.tk-wpm-job-managers .tk-wpm-job-patches div', job).html(active_patches+' / '+total_patches);
						}
					});
				});
			});
			jQuery('.tk-wpm-job-payment').click(function()
			{
				jQuery('<form>', {
					"action": '<?php echo $medic_payments_url ?>',
					"html": '<input type="hidden" id="job-key" name="job-key" value="' + jQuery(this).attr('data-job-key') + '" /><input type="hidden" id="account-key" name="account-key" value="' + jQuery(this).attr('data-account-key') + '" /><input type="hidden" id="site-url" name="site-url" value="<?php echo site_url(); ?>" />',
					"method": 'POST',
					"target": '_blank'
				}).appendTo(document.body).submit();
			});
			jQuery(document).on('click', '.tk-wpm-job-manager-save-patches', function ()
			{
				var container = jQuery(this).closest('.tk-wpm-job');
				var notifications = jQuery('.tk-wpm-notifications', container);
				jQuery(notifications).html('<div class="tk-wpm-patch-save-success"></div><div class="tk-wpm-patch-save-error"></div>');
				// for each patch
				jQuery('.tk-wpm-job-patch', container).each(function()
				{
					// TODO: create an admin command to submit a patch as a single object
					var patch_number = jQuery('.tk-wpm-job-manager-number', this).html();
					// save the pages
					var data = {
						'action':'tk_wpm_Admin',
						'command':'set-pages',
						'patch-id':	jQuery(this).attr('data-patch-id'),
						'pages': jQuery('.tk-wpm-job-patch-page-selector select', this).val(),
						'apply-to-all': false //jQuery('.tk-wpm-job-patch-apply-to-all', this)[0].checked ? true : false (patch pages now saved individually)
					};
					jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
					{
						var jsonResults = jQuery.parseJSON(response);
						if (jsonResults['success'])
						{
							jQuery('.tk-wpm-patch-save-success', notifications).append('<img src="<?php echo plugins_url('../Images/icon-tick.png', __FILE__) ?>" alt="Pages saved for patch '+patch_number+'" title="Pages saved for patch '+patch_number+'"/>');
						}
						else
						{
							//jQuery(patch_save_notifications).html(jsonResults['diagnostic']);
							jQuery('.tk-wpm-patch-save-error', notifications).append('<div class="tk-wpm-patch-save-error-details">Error saving pages for patch '+patch_number+': '+jsonResults['diagnostic']+'</div>');
						}
					});

					// save activation state
					data = {
						'action':'tk_wpm_Admin',
						'command':'toggle-patch',
						'state':jQuery('input.tk-wpm-job-patch-enable', this)[0].checked ? 'on' : 'off',
						'patch-id':jQuery(this).attr('data-patch-id')
					};
					jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
					{
						var jsonResults = jQuery.parseJSON(response);
						if (jsonResults['success'])
						{
							jQuery('.tk-wpm-patch-save-success', notifications).append('<img src="<?php echo plugins_url('../Images/icon-tick.png', __FILE__) ?>" alt="Activation saved for patch '+patch_number+'" title="Activation saved for patch '+patch_number+'"/>');
						}
						else
						{
							//jQuery(patch_save_notifications).html(jsonResults['diagnostic']);
							jQuery('.tk-wpm-patch-save-error', notifications).append('<div class="tk-wpm-patch-save-error-details">Error saving activation for patch '+patch_number+': '+jsonResults['diagnostic']+'</div>');
						}
					});

					jQuery('.tk-wpm-job-patch-parameters textarea', this).each(function()
					{
						var data = {
							'action':'tk_wpm_Admin',
							'command':'set-option',
							'option-key':	jQuery(this).attr('data-parameter-name'),
							'option-value': jQuery(this).val()
						};
						jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
						{
							var jsonResults = jQuery.parseJSON(response);
							if (jsonResults['success'])
							{
								jQuery('.tk-wpm-patch-save-success', notifications).append('<img src="<?php echo plugins_url('../Images/icon-tick.png', __FILE__) ?>" alt="Parameter saved for patch '+patch_number+'" title="Parameter saved for patch '+patch_number+'"/>');
							}
							else
							{
								//jQuery(patch_save_notifications).html(jsonResults['diagnostic']);
								jQuery('.tk-wpm-patch-save-error', notifications).append('<div class="tk-wpm-patch-save-error-details">Error saving parameter for patch '+patch_number+': '+jsonResults['diagnostic']+'</div>');
							}
						});
					});

					// update the enabled patch count for the job
					var enabled_patch_count = jQuery('.tk-wpm-job-patch-enable:checked', container).length;
					var patch_count = jQuery('.tk-wpm-job-patch', container).length;
					jQuery('.tk-wpm-job-patches div', container).html(enabled_patch_count+' / '+patch_count);

				});
			});
			jQuery('.tk-wpm-job-edit').click(function()
			{
				var container = jQuery(this).closest('.tk-wpm-job');
				var manager_panel = jQuery('.tk-wpm-job-manager', container);
				var status = jQuery('.tk-wpm-job-status span').html();
				jQuery(manager_panel).html('<div class="tk-wpm-job-manager-header"><div>Edit job</div><img class="tk-wpm-job-manager-close" src="<?php echo plugins_url('../Images/icon-close.png', __FILE__) ?>" alt="Close job editor" title="Close job editor" /><img class="tk-wpm-job-manager-expand" data-expand-state="expand" src="<?php echo plugins_url('../Images/icon-expand.png', __FILE__) ?>" alt="Expand job editor" title="Expand job editor" /><img class="tk-wpm-job-manager-save-job" src="<?php echo plugins_url('../Images/icon-save.png', __FILE__) ?>" alt="Save" title="Save" /></div>');
				if (status != '<?php echo TkWordPressMedicJobStatus::Created ?>')
				{
					jQuery('<p>NOTE: This job has already been submitted to the medic. You can update the job for your own reference. If there is something the medic should know, send a message to ensure the information update is flagged</p>').appendTo(manager_panel);
				}
				jQuery('<div>Name:<br/><input class="tk-wpm-job-edit-name" type="text" value="'+jQuery('.tk-wpm-job-name',container).html()+'" /></div>').appendTo(manager_panel);
				jQuery('<div>Description:<br/><textarea class="tk-wpm-job-edit-description">'+br2nl(jQuery('.tk-wpm-job-description',container).html())+'</textarea></div>').appendTo(manager_panel);
				jQuery('<div>URL:<br/><input class="tk-wpm-job-edit-url" type="text" value="'+jQuery('.tk-wpm-job-url span a',container).html()+'" /></div>').appendTo(manager_panel);
			});
			jQuery('.tk-wpm-job-remove').click(function()
			{
				var job = jQuery(this).closest('.tk-wpm-job');
				confirm('Are you sure?', function()
				{
					var data = {
						'action':'tk_wpm_Admin',
						'command':'delete-job',
						'job-id':	jQuery('.tk-wpm-job-manager', job).attr('data-job-id')
					};
					jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
					{
						var jsonResults = jQuery.parseJSON(response);
						if (jsonResults['success'])
						{
							document.location.reload();
						}
						else
						{
							jQuery('.tk-wpm-notifications', job).html(jsonResults['diagnostic']);
						}
					});
				});
			});
			jQuery(document).on('click', '.tk-wpm-job-manager-expand', function()
			{
				var state = jQuery(this).attr('data-expand-state');
				var source = jQuery(this).attr('src');
				var alt = jQuery(this).attr('alt');
				var title = jQuery(this).attr('title');
				var manager = jQuery(this).closest('.tk-wpm-job-manager');
				jQuery(this).attr('data-expand-state', state == 'expand' ? 'collapse' : 'expand');
				if (state == 'expand')
				{
					jQuery(this).attr('src', source.replace('expand', 'collapse')).attr('alt', source.replace('Expand', 'Collapse')).attr('title', title.replace('Expand', 'Collapse'));
					jQuery('.tk-wpm-job-manager-content', manager).css('max-height','inherit');
				}
				else
				{
					jQuery(this).attr('src', source.replace('collapse', 'expand')).attr('alt', source.replace('Collapse', 'Expand')).attr('title', title.replace('Collapse', 'Expand'));
					jQuery('.tk-wpm-job-manager-content', manager).css('max-height',240);
				}
			});
			jQuery(document).on('click', '.tk-wpm-message-type-1', function()
			{
				var current_status = jQuery(this).attr('data-message-status');
				var target = this;
				var job = jQuery(this).closest('.tk-wpm-job');
				var data = {
					'action':'tk_wpm_Admin',
					'command':'set-message-status',
					'message-id':	jQuery(this).attr('data-message-id'),
					'current-status': current_status
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					var message_count = parseInt(jQuery('.tk-wpm-job-messages div', job).html());
					if (jsonResults['success'])
					{
						if (current_status == 'Unread')
						{
							jQuery(target).attr('data-message-status','Read');
							// decrement tk-wpm-new-item-count in menu
							updateNewItemCount(-1);
							// decrement message count in job panel
							jQuery('.tk-wpm-job-messages div', job).html(--message_count);
						}
						else
						{
							jQuery(target).attr('data-message-status','Unread');
							// increment tk-wpm-new-item-count in menu
							updateNewItemCount(1);
							// incrememnt message count in job panel
							jQuery('.tk-wpm-job-messages div', job).html(++message_count);
						}
						jQuery('.tk-wpm-job-messages', job).attr('title', message_count + (message_count == 1 ? ' message' : ' messages'));
					}
				});
			});
			jQuery(document).on('click', '.tk-wpm-job-manager-close', function()
			{
				var container = jQuery(this).closest('.tk-wpm-job');
				jQuery('.tk-wpm-job-manager', container).html('');
			});
			jQuery(document).on('click', '.tk-wpm-job-manager-send-message', function()
			{
				var container = jQuery(this).closest('.tk-wpm-job');
				var data = {
					'action':'tk_wpm_Admin',
					'command':'send-message',
					'job-id':jQuery('.tk-wpm-job-manager', container).attr('data-job-id'),
					'message':jQuery('.tk-wpm-job-new-message', container).val()
				};
				if (data['message'].length == 0) return;
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						jQuery('.tk-wpm-job-messages-none', container).remove();
						jQuery('.tk-wpm-job-manager-content', container).prepend('<div class="tk-wpm-message-type-0">You: '+data.message+'</div>');
						jQuery('.tk-wpm-notifications', container).html('Message sent');
						jQuery('.tk-wpm-job-new-message', container).val('');
					}
					else
					{
						jQuery('.tk-wpm-notifications', container).html(jsonResults['diagnostic']);
					}
				});
			});
			jQuery(document).on('click', '.tk-wpm-job-manager-save-job', function()
			{
				var container = jQuery(this).closest('.tk-wpm-job');
				var data = {
					'action':'tk_wpm_Admin',
					'command':'update-job',
					'job-id':jQuery('.tk-wpm-job-manager', container).attr('data-job-id'),
					'name':jQuery('.tk-wpm-job-edit-name', container).val(),
					'description':jQuery('.tk-wpm-job-edit-description', container).val(),
					'url':jQuery('.tk-wpm-job-edit-url', container).val()
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						jQuery('.tk-wpm-job-name', container).html(data.name);
						jQuery('.tk-wpm-job-description', container).html(nl2br(data.description, false));
						jQuery('.tk-wpm-job-url', container).html('URL: <span><a href="'+data.url+'" target="_blank">'+data.url+'</a></span>');
						jQuery('.tk-wpm-notifications',container).html('Job saved');
					}
					else
					{
						jQuery('.tk-wpm-notifications',container).html(jsonResults['diagnostic']);
					}
				});
			});
			jQuery('.tk-wpm-job-submit').click(function()
			{
				var data = {
					'action':'tk_wpm_Admin',
					'command':'submit-job',
					'job-key':jQuery(this).attr('data-job-key')
				};
				var container = jQuery(this).closest('.tk-wpm-job');
				var status = jQuery('.tk-wpm-job-status span', container);
				var action_item = jQuery(this);
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						status.html('<?php echo TkWordPressMedicJobStatus::Submitted;?>');
						jQuery(container).removeClass('tk-wpm-job-created').addClass('tk-wpm-job-submitted');
						action_item.remove();
					}
					else
					{
						jQuery('.tk-wpm-notifications',container).html(jsonResults['diagnostic']);
					}
				});
			});
			jQuery('.tk-wpm-job-accept').click(function()
			{
				var job = jQuery(this).closest('.tk-wpm-job');
				var button = this;
				confirm('Are you sure?\nOnce accepted the job will be finalised and a new job required for further work.', function()
				{
					var status = jQuery('.tk-wpm-job-status span', job);
					var data = {
						'action':'tk_wpm_Admin',
						'command':'accept-job',
						'job-key': jQuery(button).attr('data-job-key')
					};
					jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
					{
						var jsonResults = jQuery.parseJSON(response);
						if (jsonResults['success'])
						{
							jQuery(job).removeClass('tk-wpm-job-'+jQuery(status).html().toLowerCase());
							jQuery(job).addClass('tk-wpm-job-accepted');
							jQuery(status).html(jsonResults['status']);
							jQuery(button).remove();
						}
					});
				});
			});
			jQuery('#tk-wpm-manual-sync').click(function()
			{
				var data = {
					'action':'tk_wpm_Admin',
					'command':'manual-sync'
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						if (jsonResults['updates-received'])
						{
							jQuery('.tk-wpm-notifications').first().html('Sync Completed and updates were received. <a href="">Reload</a> to view.');
						}
						else
						{
							jQuery('.tk-wpm-notifications').first().html('Sync Completed. There were no updates at this time.');
						}
					}
					else
					{
						jQuery('.tk-wpm-notifications').first().html(jsonResults['diagnostic']);
					}
				});
			});
			jQuery('#tk-wpm-patch-behaviour').change(function()
			{
				var data = {
					'action':'tk_wpm_Admin',
					'command':'set-patch-behaviour',
					'sync':this.checked
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (!jsonResults['success'])
					{
						jQuery('.tk-wpm-notifications').first().html(jsonResults['diagnostic']);
					}
				});
			});
			// get the medic system status
			jQuery.get('<?php echo $medic_api_base_url; ?>system-status/', function(response)
			{
				var jsonResults = jQuery.parseJSON(response);
				jQuery('.tk-wpm-system-status').html(jsonResults['status'] + '<br/>' + jsonResults['submissions-message']);
				jQuery('.tk-wpm-system-status').css({'background-color':jsonResults['status-colour'],'color':'white'});
			}).fail(function()
			{
				jQuery('.tk-wpm-system-status').html('System status request failed.<br/>Are you well connected?');
			});
			// account verification
			jQuery('.tk-wpm-verify-account').click(function()
			{
				var container = jQuery(this).closest('.tk-wpm-account-verification-notice');
				var data = {
					'action':'tk_wpm_Admin',
					'command':'verify-account',
					'email':jQuery('.tk-wpm-verify-account-email', container).val()
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						jQuery(container).html('Click the link in the email we have sent to ' + data.email + '. The form you just used will be redisplayed whilst your account remains unverified so you can try again if you didn\'t manage to verify.');
					}
					else
					{
						jQuery(container).html(jsonResults['diagnostic']);
					}
				});
			});
		});
		function updateNewItemCount(change)
		{
			var itemCount = parseInt(jQuery('.wp-submenu .tk-wpm-new-item-count').html());
			if (isNaN(itemCount)) itemCount = 0;
			var newItemCount = itemCount + change;
			if (newItemCount == 0)
			{
				jQuery('.tk-wpm-new-item-count').html(newItemCount).hide();
			}
			else
			{
				if (jQuery('.tk-wpm-new-item-count').length == 0)
				{
					jQuery('.tk-wpm-new-item-placeholder').removeClass('tk-wpm-new-item-placeholder').addClass('tk-wpm-new-item-count').html(newItemCount).attr('title',newItemCount+' new message'+newItemCount>1?'s':'').show();
				}
				else
				{
					jQuery('.tk-wpm-new-item-count').html(newItemCount).attr('title',newItemCount+' new message'+newItemCount>1?'s':'').show();
				}
			}
		}
		function nl2br(str, is_xhtml)
		{
		  var breakTag = (is_xhtml || typeof is_xhtml === 'undefined') ? '<br />' : '<br>';
		  return (str + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1'+ breakTag +'$2');
		}
		function br2nl(str)
		{
			return (str).replace(/<br \/>/g, '\n').replace(/<br>/g, '\r');
		}
		function confirm(message, handler)
		{
			var confirm = jQuery('<div class="tk-wpm-confirm"><div><p>'+message+'</p><button class="tk-wpm-confirm-ok">Continue</button><button class="tk-wpm-confirm-cancel">Cancel</button></div></div>').appendTo('body');
			jQuery('.tk-wpm-confirm-cancel', confirm).click(function()
			{
				jQuery(confirm).remove();
			});
			jQuery('.tk-wpm-confirm-ok', confirm).click(function()
			{
				handler();
				jQuery(confirm).remove();
			});
		}
		</script>
		<div class="tk-wpm-admin-page-container">
			<?php
			if ($account['verified'] == 0)
			{
				$verification = TkWordPressMedicAdmin::update_account_verification();
				if ($verification === null)
				{
					?>
					<p class="tk-wpm-account-verification-notice">
						There was an issue getting your account status from the medic server:<br/>
						Invalid response.<br/>
						The request will be automatically retried the next time you load this page.
					</p>
					<?php
				}
				else if (!$verification['success'])
				{
					?>
					<p class="tk-wpm-account-verification-notice">
						There was an issue getting your account status from the medic server:<br/>
						<?php echo $verification['diagnostic'] ?><br/>
						The request will be automatically retried the next time you load this page.
					</p>
					<?php
				}
				else
				{
					if ($verification['account'] == null || !$verification['account']['verified'])
					{
					?>
					<p class="tk-wpm-account-verification-notice">
						Your account is not verified.<br/>
						Verifying your email address will allow us to ensure you keep the patches
						you've paid for if you delete and re-install the plugin.<br/>
						Email address:<br/><input type="text" value="<?php echo get_option('admin_email') ?>" class="tk-wpm-verify-account-email"/>
						<button class="tk-wpm-verify-account">Verify account</button>
					</p>
					<?php
					}
					if ($verification['account']['verified'])
					{
						// update the status locally
						$this->set_account_verified($account);
						if ($this->insert_migrated_data($verification['migration-data']))
						{
							?>
							<script type="text/javascript">
								document.location.reload();
							</script>
							<?php
						}
					}
				}
			}
			?>
			<p class="tk-wpm-system-status">Getting tenKinetic Medic system status</p>
			<p class="tk-wpm-system-options">
				<label for="tk-wpm-patch-behaviour">Enable and disable patches for the same job together</label> <input type="checkbox" id="tk-wpm-patch-behaviour" <?php echo (get_option('tk_wpm_patch_sync') == 'true' ? 'checked="checked"' : '') ?>/><br/>
				Time since last job synchronisation: <?php echo gmdate('H:i:s', time()-get_option('tk_wpm_last_sync')) ?>
			</p>
			<h1><img src="<?php echo plugins_url('../Images/wpm-logo-32.png', __FILE__) ?>" alt="tenKinetic Medic Logo" /> tenKinetic Medic</h1>
			<p class="tk-wpm-system-header"><button id="tk-wpm-job-create">Create Job</button> <button id="tk-wpm-manual-sync">Check for medic updates now</button></p>
			<div class="tk-wpm-job-form">
				<h1>Create Job</h1>
				<span>Name:</span> <span class="note">Name your job so you can quickly and easily identify it</span><br/>
				<input type="text" class="tk-wpm-job-create-name" /><br/>
				<span>Description:</span> <span class="note">More detail on what this job needs to address, remember to ensure this includes everything the medic will need to complete the work</span><br/>
				<textarea class="tk-wpm-job-create-description"></textarea><br/>
				<span>URL:</span> <span class="note">Provide a publicly accessible URL for the page where the update is required</span><br/>
				<input type="text" class="tk-wpm-job-create-url"/><br/>
				<button id="tk-wpm-job-cancel">Cancel</button><button id="tk-wpm-job-submit">Submit</button>
			</div>
			<p class="tk-wpm-notifications"></p>
			<div class="tk-wpm-jobs-container">
			<?php
				if (count($jobs) == 0)
				{
					?>No Jobs<?php
				}
				$float = 'left';
				foreach (array_merge($unaccepted,$accepted,$demo) as $job)
				{
					?>
					<div class="tk-wpm-job tk-wpm-job-<?php echo strtolower($job['status']) ?> tk-wpm-job-<?php echo $float ?>">
						<div class="tk-wpm-job-managers">
							<div class="tk-wpm-job-messages" data-job-key="<?php echo $job['job_key'] ?>" title="<?php echo $job['messages_unread'] ?> new <?php echo ($job['messages_unread'] == 1 ? 'message' : 'messages') ?>">
								<div><?php echo $job['messages_unread'] ?></div>
								<img src="<?php echo plugins_url('../Images/icon-mail.png', __FILE__) ?>" alt="" />
							</div>
							<div class="tk-wpm-job-patches" data-job-key="<?php echo $job['job_key'] ?>" title="<?php echo $job['active_patches'] ?> / <?php echo ($job['active_patches'] + $job['inactive_patches']) ?> <?php echo ($job['active_patches'] + $job['inactive_patches'] == 1 ? 'patch' : 'patches') ?> activated">
								<div><?php echo $job['active_patches'] ?> / <?php echo ($job['active_patches'] + $job['inactive_patches']) ?></div>
								<img src="<?php echo plugins_url('../Images/icon-patch.png', __FILE__) ?>" alt="" />
							</div>
							<div class="tk-wpm-job-remove" title="Remove job">
								<img src="<?php echo plugins_url('../Images/icon-delete.png', __FILE__) ?>" alt="" />
							</div>
							<div class="tk-wpm-job-edit" title="Edit job">
								<img src="<?php echo plugins_url('../Images/icon-edit.png', __FILE__) ?>" alt="" />
							</div>
							<?php
							if ($job['status'] == TkWordPressMedicJobStatus::Created)
							{
							?>
							<div class="tk-wpm-job-submit" data-job-key="<?php echo $job['job_key'] ?>" title="Submit this job to the medic">
								<img src="<?php echo plugins_url('../Images/icon-submit.png', __FILE__) ?>" alt="" />
							</div>
							<?php
							}
							if ($job['status'] == TkWordPressMedicJobStatus::Quoted)
							{
								?>
								<div class="tk-wpm-job-payment" data-job-key="<?php echo $job['job_key'] ?>" data-account-key="<?php echo $account['account_key'] ?>" title="Complete payment for this job">
									<img src="<?php echo plugins_url('../Images/icon-payments.png', __FILE__) ?>" alt="" />
								</div>
								<?php
							}
							if ($job['status'] == TkWordPressMedicJobStatus::Completed)
							{
								?>
								<div class="tk-wpm-job-accept" data-job-key="<?php echo $job['job_key']; ?>" title="Accept the solution for this job">
									<img src="<?php echo plugins_url('../Images/icon-accept.png', __FILE__) ?>" alt="" />
								</div>
								<?php
							}
							?>
						</div>
						<div class="tk-wpm-job-name"><?php echo $job['name'] ?></div>
						<div class="tk-wpm-job-creation">Created: <?php echo $job['create_date'] ?></div>
						<div class="tk-wpm-job-status">Status: <span><?php echo $job['status'] ?></span></div>
						<div class="tk-wpm-job-description"><?php echo nl2br($job['description']) ?></div>
						<div class="tk-wpm-job-url">URL: <?php if ($job['url'] == '') { echo 'Not supplied'; } else { ?><span><a href="<?php echo $job['url'] ?>" target="_blank"><?php echo $job['url'] ?></a></span><?php } ?></div>
						<div class="tk-wpm-notifications"></div>
						<div class="tk-wpm-job-manager" data-job-id="<?php echo $job['id'] ?>"></div>
					</div>
					<?php
					$float = $float == 'left' ? 'right' : 'left';
				}
			?>
			</div>
			<div id="tk-wpm-freepik"><span>All icons by </span><a href="http://freepik.com" target="_blank"><img src="<?php echo plugins_url('../Images/logo-freepik.png', __FILE__) ?>" alt="freepik" /></a></div>
		</div>
		<?php
	}

	/* USAGE */

	function tk_wpm_usage()
	{
		if (!current_user_can( 'manage_options'))
		{
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		?>
		<h1>Usage</h1>
		<div class="tk-wpm-admin-page-container">
			<p>tenKinetic Medic is designed for small fixes and enhancements to your WordPress installation.<br/>
			The work is done by an experienced WordPress developer and is totally under your control.<br/>
			Whatever work is done is delivered to your server and you control how and when to deploy it.</p>
			<h2>Basic procedure:</h2>

			<div class="tk-wpm-procedure-number">1</div>
			<div class="tk-wpm-procedure-step">Create a job</div>
			<ul class="tk-wpm-procedure-details">
				<li class="tk-wpm-emphasis">All job details need to be provided in English. Engrish, Singlish and American are also acceptable
				as long as it's clear for an English speaking medic to quickly and easily determine what is required.</li>
				<li>Provide a name. This is mostly for your own purposes. A quick way to tell your jobs apart.</li>
				<li>Provide a description for the medic. The more detail and accuracy you can provide the easier it is for the Medic to understand
				the problem.</li>
				<li>Optionally, provide a URL where the medic can have a look at a specific part of your site
					in order to assess the situation.</li>
				<li>You will be able to modify these details after you have created the job and before you submit the job
				to the medic (once the job is submitted you'll need to use the mesaging system to make sure any extra information
				is received loud and clear by the medic).</li>
			</ul>

			<div class="tk-wpm-procedure-number">2</div>
			<div class="tk-wpm-procedure-step">Submit the job</div>
			<ul class="tk-wpm-procedure-details">
				<li>When you're happy with the job details, submit it to the medic.</li>
				<li>Once the job is submitted you are able to add messages to the job which will be sent to the medic.
				You should make sure you have included everything you need to in the job details though since the medic
				may prepare a response before a message is sent.</li>
			</ul>

			<div class="tk-wpm-procedure-number">3</div>
			<div class="tk-wpm-procedure-step">Receive response</div>
			<ul class="tk-wpm-procedure-details">
				<li>The medic will respond to your job submission.</li>
				<li>This would be either: a message, probably to gain clarification; notification that a quote has been
				provided for the work; notice that the work has been declined.</li>
				<li>If work is declined a message will be provided by way of explanation.</li>
				<li>Ultimately a quote will be provided.</li>
			</ul>

			<div class="tk-wpm-procedure-number">4</div>
			<div class="tk-wpm-procedure-step">Complete payment</div>
			<ul class="tk-wpm-procedure-details">
				<li>When a job has been quoted you can view the quote on the medic server and pay using PayPal.</li>
				<li>Once payment is made the work will be scheduled for implementation.</li>
				<li>All payments are through Paypal express checkout.</li>
				<li>All quotes and payments are in $AU</li>
			</ul>

			<div class="tk-wpm-procedure-number">5</div>
			<div class="tk-wpm-procedure-step">Receive patches and parameters.</div>
			<ul class="tk-wpm-procedure-details">
				<li>tenKinetic Medic works by applying extra CSS and JavaScript to your page(s).</li>
				<li>JavaScript patches are also able to use simple data storage which are provided in the form of parameters.</li>
				<li>Parameters are very limited for security reasons. Currently patches may only read parameter values which can be
				set by a site administrator.</li>
				<li>The job status will be set to Complete by the medic when all patches and parameters have been provided.</li>
			</ul>

			<div class="tk-wpm-procedure-number">6</div>
			<div class="tk-wpm-procedure-step">Configure and activate patches and parameters.</div>
			<ul class="tk-wpm-procedure-details">
				<li>Patches are site-wide by default but can be limited to certain pages if required.
					Selecting no pages for the patch effectively enables it for all pages.
					Disable the patch to stop it from displaying on any pages.</li>
				<li>Parameters can have a default value but will usually require a value provided by you, the site administrator.</li>
				<li>By default all patches for a job are toggled together. When you activate one, the rest are also activated.
				Patches can be applied individually if required.</li>
				<li>Patches can be deleted if they are no longer required. Previously delivered patches are not updated since they
				may be active and modifying them without testing first is a bit risky. It's safer to deliver new, inactive patches
				to replace or enhance current patches. Replacements patches can be tested then activated and the original patches
				turned off and, optionally, deleted.</li>
			</ul>

			<div class="tk-wpm-procedure-number">7</div>
			<div class="tk-wpm-procedure-step">Accept job.</div>
			<ul class="tk-wpm-procedure-details">
				<li>When you have verified the patches as working, you can accept the job.</li>
				<li class="tk-wpm-emphasis">IMPORTANT: This is done automatically seven days after the job has been set to complete, if something isn't
				right it's important to message the medic before the seven days is up.</li>
			</ul>
			<p>
				Jobs are colour coded so you know where they're at.<br/>
				The main thing to remember is that orange jobs need your attention.
			</p>
			<div class="tk-wpm-job-colour-legend">
				<div class="tk-wpm-job-demo">Demo</div>
				<div class="tk-wpm-job-created">Created</div>
				<div class="tk-wpm-job-submitted">Submitted</div>
				<div class="tk-wpm-job-declined">Declined</div>
				<div class="tk-wpm-job-quoted">Quoted</div>
				<div class="tk-wpm-job-paid">Paid</div>
				<div class="tk-wpm-job-inprogress">In Progress</div>
				<div class="tk-wpm-job-completed">Completed</div>
				<div class="tk-wpm-job-accepted">Accepted</div>
			</div>

			<h2>Job controls</h2>
			<p class="tk-wpm-procedure-details">
				At the top-right of each job there are a set of functions. They will change depending on the state of the
				job. The illustrations below show the functions available for each job state.
			</p>
			<p>
				<img src="<?php echo plugins_url('../Images/job-functions.png', __FILE__) ?>" title="Job Functions" alt="Job Functions" />
			</p>

			<h2>Patch delivery:</h2>
			<p class="tk-wpm-procedure-details">
				Updates from the medic are fetched automatically.<br/>
				This is done hourly and only for jobs that have been submitted and are not yet accepted.<br/>
				To force a manual job synchronisation at any time there is a button in the jobs view.
			</p>

			<h2>Account verification:</h2>
			<p class="tk-wpm-procedure-details">
				The medic system works without verifying your account with an email address but it's recommended you do so. Once you verify your account all jobs are
				associated with that email address. If you uninstall the plugin or lose your data somehow you can re-install the plugin and verify using the same email
				address. Any jobs for the same site (determined by the URL as reported by WordPress) registered to that email address will be dellivered to your new
				account when it is verified.
			</p>
			<p class="tk-wpm-procedure-details">
				Both the original and the new accounts must be verified with the same email address and they must both be for the same site. This means you can use the
				same email address to verify the plugin on multiple sites. What can't currently be automated with this setup is migration of job data between two accounts
				with different domains. This will be an automated feature at some point but for now a support request would be in order.
			</p>
		</div>
		<?php
	}

	/* ACTIVITY LOG */

	function tk_wpm_activity()
	{
		if (!current_user_can( 'manage_options'))
		{
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		$activity = $this->get_activity();
		$activity_types = array_unique(array_map(function($row){return $row['type'];}, $activity));
		?>
		<script>
		jQuery(document).ready(function()
		{
			jQuery('button#tk-wpm-clear-activity').click(function()
			{
				var data = {
					'action':'tk_wpm_Admin',
					'command':'clear-activity'
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						document.location.reload();
					}
					else
					{
						jQuery('.tk-wpm-notifications').html(jsonResults['diagnostic']);
					}
				});
			});
			jQuery('button#tk-wpm-clear-activity-type').click(function()
			{
				var data = {
					'action':'tk_wpm_Admin',
					'command':'clear-activity-type',
					'type':jQuery(this).attr('data-type')
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						document.location.reload();
					}
					else
					{
						jQuery('.tk-wpm-notifications').html(jsonResults['diagnostic']);
					}
				});
			});
		});
		</script>
		<h1>Activity Log</h1>
		<div class="tk-wpm-admin-page-container">
			<p><button id="tk-wpm-clear-activity">Clear Activity Log</button>
			<?php
			// render a button to clear logs by each type encountered
			foreach ($activity_types as $activity_type)
			{
				echo '<button id="tk-wpm-clear-activity-type" data-type="'.$activity_type.'">Clear '.$activity_type.' entries</button> ';
			}
			?>
			</p>
			<p><div class="tk-wpm-notifications"></div></p>
			<table class="dynatable tk-wpm-activity-log">
				<thead>
					<tr>
						<th>Time</th>
						<th>Type</th>
						<th>Function</th>
						<th>Details</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ($activity as $row)
					{
					?>
					<tr>
						<td><?php echo $row['create_date']; ?></td>
						<td><?php echo $row['type']; ?></td>
						<td><?php echo $row['function']; ?></td>
						<td><?php echo $row['details']; ?></td>
					</tr>
					<?php
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* PATCH REPOSITORY */

	function tk_wpm_repository()
	{
		if (!current_user_can( 'manage_options'))
		{
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		$repository_patches = TkWordPressMedicRepository::get_patches();
		?>
		<h1>Patch Repository</h1>
		<div class="tk-wpm-admin-page-container tk-wpm-repository">
			<p>
				The Patch Repository is a library of pre-written patches you can purchase and apply to your site.
			</p>
			<p>
				Currently only the demo patches are available and are free. Just incase you decided to test deleting patches
				and now want them back ;)
			</p>
			<p>
				All prices are in $AU.
			</p>
			<div class="tk-wpm-repository">
			<?php
			foreach ($repository_patches as $patch)
			{
				?>
				<div class="tk-wpm-repository-patch">
					<div class="tk-wpm-repository-patch-name"><?php echo $patch['name'] ?></div>
					<div class="tk-wpm-repository-patch-description"><?php echo $patch['description'] ?></div>
					<div class="tk-wpm-repository-patch-image"><?php echo $patch['image'] ?></div>
					<div class="tk-wpm-repository-patch-price"><?php echo $patch['price'] ?></div>
					<div class="tk-wpm-repository-patch-purchase"><?php echo $patch['purchase'] ?></div>
				</div>
				<?php
			}
			?>
		 	</div>
		</div>
		<?php
	}

	/* SUPPORT */

	function tk_wpm_support()
	{
		if (!current_user_can( 'manage_options'))
		{
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		?>
		<script>
		jQuery(document).ready(function()
		{
			jQuery('.tk-wpm-support-form-submit').click(function()
			{
				var data = {
					'action':'tk_wpm_Admin',
					'command':'support',
					'message':jQuery('.tk-wpm-support-message'),
					'email':jQuery('.tk-wpm-support-email')
				};
				jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response)
				{
					var jsonResults = jQuery.parseJSON(response);
					if (jsonResults['success'])
					{
						jQuery('.tk-wpm-notifications').html('Support request sent');
					}
					else
					{
						jQuery('.tk-wpm-notifications').html(jsonResults['diagnostic']);
					}
				});
			});
		});
		</script>
		<h1>Support</h1>
		<div class="tk-wpm-admin-page-container tk-wpm-support-form">
			<p>
				If you're having an issue with the plugin in general or have a query that doesn't relate to a job, this form is for you.
			</p>
			<p>
				Email address:<br/>
				<input class="tk-wpm-support-email" type="text" value="<?php echo get_option('admin_email') ?>" />
			</p>
			<p>
				Message:<br/><textarea class="tk-wpm-support-message" placeholder="Write stuff here"></textarea>
			</p>
			<button class="tk-wpm-support-form-submit">Submit</button>
			<div class="tk-wpm-notifications"></div>
		</div>
		<?php
	}
}
?>
