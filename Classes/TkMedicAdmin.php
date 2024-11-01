<?php

class TkWordPressMedicAdmin
{
	// database
  protected $connectionFactory;
  protected $pdo;

	public function __construct(TkWordPressMedicConnectionFactory $factory = null)
	{
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
	}

	public static function unquote()
	{
		$_POST      = array_map('stripslashes_deep', $_POST);
		$_GET       = array_map('stripslashes_deep', $_GET);
		$_COOKIE    = array_map('stripslashes_deep', $_COOKIE);
		$_REQUEST   = array_map('stripslashes_deep', $_REQUEST);
	}

  function add_scheme($url, $scheme = 'http://')
  {
    return parse_url($url, PHP_URL_SCHEME) === null ?
      $scheme . $url : $url;
  }

  public static function get_account()
  {
    $factory = new TkWordPressMedicConnectionFactory;
		$pdo = $factory->getConnection();

		$statement = $pdo->prepare("SELECT * FROM tk_wpm_account ORDER BY create_date DESC LIMIT 1");
		$sql_status = $statement->execute();
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
      TKWordPressMedicAdmin::activity_log('error', 'get-account', 'Could not get account : '.$error[2]);
			return null;
		}
		$account = $statement->fetch(PDO::FETCH_ASSOC);
    return $account;
  }

  private static function regenerate_key()
  {
    $factory = new TkWordPressMedicConnectionFactory;
		$pdo = $factory->getConnection();

    $new_key = TkWordPressMedic::unique_id();

		$statement = $pdo->prepare("UPDATE tk_wpm_account SET account_key = :new_key ORDER BY create_date DESC LIMIT 1");
		$sql_status = $statement->execute(
      array(
        ':new_key' => $new_key
      )
    );
  }

  public static function create_account()
  {
    global $medic_api_base_url;

    $request_data = array('site-url'=>site_url(),'email'=>get_option('admin_email'));

    $tk_request = curl_init($medic_api_base_url.'create-account/');
    curl_setopt_array($tk_request, array(
      CURLOPT_POST => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => array(
          'Content-Type: application/json'
      ),
      CURLOPT_POSTFIELDS => json_encode($request_data)
    ));
    $response = curl_exec($tk_request);
    if($response === FALSE)
    {
      return array('success'=>false,'diagnostic'=>'Could not create account: '.curl_error($tk_request));
    }
    $response_data = json_decode($response, TRUE);
    return $response_data;
  }

  public static function update_account_verification()
  {
    $account = self::get_account();
    if ($account == null)
    {
      TKWordPressMedicAdmin::activity_log('error', 'update-account-verification', 'Could not get account');
      return json_encode(array('success'=>false,'diagnostic'=>'Could not get account to verify with medic server'));
    }

    global $medic_api_base_url;

    // get the account verification status from the medic server
    $tk_request = curl_init($medic_api_base_url.'account-status/');
    curl_setopt_array($tk_request, array(
      CURLOPT_POST => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => array(
          'Content-Type: application/json'
      ),
      CURLOPT_POSTFIELDS => json_encode($account)
    ));
    $response = curl_exec($tk_request);
    if($response === FALSE)
    {
      return array('success'=>false,'diagnostic'=>'Could not get account status: '.curl_error($tk_request));
    }
    $response_data = json_decode($response, TRUE);

    // Check response data for duplicate key notification. If it is present, regenerate the key and try again
    if (isset($response_data['diagnostic']) && $response_data['diagnostic'] == 'DUPLICATE_KEY')
    {
      self::regenerate_key();
      //return self::update_account_verification();
      return array('success'=>false,'diagnostic'=>'Account Key Invalid. Refresh to try again.');
    }
    else
    {
      return $response_data;
    }
  }

  public static function request_job_key($job_key)
  {
    // a replacement key is needed for an job (probably a migrated one since that's the only use case so far)
    // the key needs to be unique on the medic server and the same key needs to be used on the client so sync still works.
    global $medic_api_base_url;

    $request_data['job-key'] = $job_key;

    // get the account verification status from the medic server
    $tk_request = curl_init($medic_api_base_url.'job-key/');
    curl_setopt_array($tk_request, array(
      CURLOPT_POST => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => array(
          'Content-Type: application/json'
      ),
      CURLOPT_POSTFIELDS => json_encode($request_data)
    ));
    $response = curl_exec($tk_request);
    if($response === FALSE)
    {
      TKWordPressMedicAdmin::activity_log('error', 'request-job-key', 'Could not get new job key : '.$error[2]);
      return null;
    }
    $response_data = json_decode($response, TRUE);
    if (!$response_data['success'])
    {
      TKWordPressMedicAdmin::activity_log('error', 'request-job-key', 'Could not get new job key : '.$response_data['diagnostic']);
      return null;
    }
    return $response_data['job-key'];
  }

  public static function get_patches($type, $page_id)
	{
		$factory = new TkWordPressMedicConnectionFactory;
		$pdo = $factory->getConnection();

    $output_patches = [];

		$statement = $pdo->prepare("SELECT p.*, pp.page_id FROM tk_wpm_patch p LEFT JOIN tk_wpm_patch_page pp ON p.id = pp.patch_id
			WHERE p.patch_type = :type AND p.is_active = 1 AND (page_id = :page_id OR page_id IS NULL)");
		$sql_status = $statement->execute(
			array(
				':type' => $type,
				':page_id' => $page_id
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			return json_encode(array('success'=>false,'diagnostic'=>'Could not get patches. '.$error[2]));
		}
		$patches = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($patches as $patch)
    {
      $patch['parameters'] = TKWordPressMedicAdmin::get_parameters($patch['id']);
      $output_patches[] = $patch;
    }
		return $output_patches;
	}

  public static function get_parameters($patch_id)
	{
    $factory = new TkWordPressMedicConnectionFactory;
		$pdo = $factory->getConnection();

		$statement = $pdo->prepare("SELECT q.id, p.description as parameter_description, p.name as parameter_name, q.patch_type, q.patch_description, j.name as job_name
			FROM tk_wpm_parameter p
			JOIN tk_wpm_patch q ON p.patch_key = q.patch_key
			JOIN tk_wpm_job j on q.job_id = j.id
      WHERE q.id = :patch_id
			ORDER BY p.create_date DESC");
		$sql_status = $statement->execute(
      array(
        ':patch_id'=>$patch_id
      )
    );
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-parameters', 'Could not get parameters : '.$error[2]);
			return [];
		}
		$parameters = $statement->fetchAll(PDO::FETCH_ASSOC);
    return $parameters;
	}

	public static function get_sibling_patches($patch_id)
	{
		$factory = new TkWordPressMedicConnectionFactory;
		$pdo = $factory->getConnection();

		$statement = $pdo->prepare("SELECT id FROM tk_wpm_patch WHERE job_id = (SELECT job_id FROM tk_wpm_patch WHERE id = :patch_id)");
		$sql_status = $statement->execute(
			array(
				':patch_id' => $patch_id
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			return json_encode(array('success'=>false,'diagnostic'=>'Could not get patches. '.$error[2]));
		}
		$patches = $statement->fetchAll(PDO::FETCH_ASSOC);
		return $patches;
	}

  public function sync_jobs($is_manual)
	{
    global $medic_api_base_url;
    $messages = null;
    $job_messages = null;

		// get jobs that need syncing
		if (!$is_manual)
		{
			$statement = $this->pdo->prepare("SELECT job_key, last_sync, (SELECT account_key FROM tk_wpm_account WHERE site_url = :site_url) AS account_key
				FROM tk_wpm_job WHERE last_sync < timestamp(DATE_SUB(NOW(), INTERVAL 60 MINUTE))
				AND status NOT IN ('Demo','Created','Accepted')");
		}
		else
		{
			$statement = $this->pdo->prepare("SELECT job_key, last_sync, (SELECT account_key FROM tk_wpm_account WHERE site_url = :site_url) AS account_key
				FROM tk_wpm_job WHERE status NOT IN ('Demo','Created','Accepted')");
		}
		$sql_status = $statement->execute(
			array(
				':site_url' => site_url()
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			$messages[] = 'Error getting jobs to sync: '.$error[2];
		}
		$sync_jobs = $statement->fetchAll(PDO::FETCH_ASSOC);

		// abort if there's nothing to sync
		if (count($sync_jobs) == 0) return array('messages'=>[],'job-messages'=>[],'updates-received'=>false);

		TKWordPressMedicAdmin::activity_log('info', 'sync-jobs', 'sync running at:'.time());
		update_option('tk_wpm_last_sync', time());

    // get job details and data since last sync from TK
		$tk_request = curl_init($medic_api_base_url.'sync-jobs/');
		curl_setopt_array($tk_request, array(
			CURLOPT_POST => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_HTTPHEADER => array(
					'Content-Type: application/json'
			),
			CURLOPT_POSTFIELDS => json_encode($sync_jobs)
		));
		$response = curl_exec($tk_request);
		if($response === FALSE)
		{
			$messages[] = curl_error($tk_request);
		}
		$response_data = json_decode($response, TRUE);

    if (count($response_data) == 0)
    {
		  TKWordPressMedicAdmin::activity_log('info', 'sync-jobs', 'No new data was available from the medic');
    }
    else
    {
      TKWordPressMedicAdmin::activity_log('info', 'sync-jobs', 'New data was recieved from the medic');
    }

		//var_dump(count($response_data));

		if ($response_data === null) return ["Invalid response from medic server for sync-jobs"];

		$updates_received = false;

    if ($messages == null)
    {
  		// response will contain all jobs that have been updated since the last sync
  		foreach ($response_data as $job)
  		{
        $updates_received = true;
        $this->pdo->beginTransaction();

  			// insert new messages
  			foreach ($job['messages'] as $message)
  			{
  				$statement = $this->pdo->prepare("INSERT INTO tk_wpm_message (job_id,message,is_medic,status)
  					VALUES ((SELECT id FROM tk_wpm_job WHERE job_key = :job_key), :message, true, :unread_status)");
  				$sql_status = $statement->execute(
  					array(
  						':job_key' => $job['job_key'],
  						':message' => $message['message'],
  						':unread_status' => TkWordPressMedicMessageStatus::Unread
  					)
  				);
  				if (!$sql_status)
  				{
  					$error = $statement->errorInfo();
  					$job_messages[] = 'Error syncing job message: '.$error[2];
  				}
  			}

  			// insert provided patches
  			$added_patches = count($job['patches']);
  			if ($added_patches > 0)
  			{
  				// create a message to let the user know there's new patches (and fail silently)
  				$statement = $this->pdo->prepare("INSERT INTO tk_wpm_message (job_id,message,is_medic,status)
  					VALUES ((SELECT id FROM tk_wpm_job WHERE job_key = :job_key), :message, true, :unread_status)");
  				$sql_status = $statement->execute(
  					array(
  						':job_key' => $job['job_key'],
  						':message' => $added_patches.' new patch'.($added_patches > 1 ? 'es have' : ' has').' been added by the medic',
  						':unread_status' => TkWordPressMedicMessageStatus::Unread
  					)
  				);
  			}
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
  					$error = $statement->errorInfo();
  					$job_messages[] = 'Error syncing job patch: '.$error[2];
  				}
  			}

  			// insert new parameters
  			$added_parameters = count($job['parameters']);
  			if ($added_parameters > 0)
  			{
  				// create a message to let the user know there's new parameters (and fail silently)
  				$statement = $this->pdo->prepare("INSERT INTO tk_wpm_message (job_id,message,is_medic,status)
  					VALUES ((SELECT id FROM tk_wpm_job WHERE job_key = :job_key), :message, true, :unread_status)");
  				$sql_status = $statement->execute(
  					array(
  						':job_key' => $job['job_key'],
  						':message' => $added_parameters.' new parameter'.($added_parameters > 1 ? 's have' : ' has').' been added by the medic',
  						':unread_status' => TkWordPressMedicMessageStatus::Unread
  					)
  				);
  			}
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
  					$error = $statement->errorInfo();
  					$job_messages[] = 'Error syncing job parameter: '.$error[2];
  				}
  				// set the default
  				update_option($parameter['name'], 	$parameter['default_value']);
  			}

  			// update job status. do this only if there are no messages (errors) for this job so the sync can be retried
  			if (count($job_messages) == 0)
  			{
  				$statement = $this->pdo->prepare("UPDATE tk_wpm_job SET status = :job_status WHERE job_key = :job_key");
  				$sql_status = $statement->execute(
  					array(
  						':job_status' => $job['status'],
  						':job_key' => $job['job_key']
  					)
  				);
  				if (!$sql_status)
  				{
  					$error = $statement->errorInfo();
  					$job_messages[] = 'Error updating job status: '.$error[2];
  				}
          if ($statement->rowCount() > 0)
          {
            // create a message to let the user know the status was changed (and fail silently)
    				$statement = $this->pdo->prepare("INSERT INTO tk_wpm_message (job_id,message,is_medic,status)
    					VALUES ((SELECT id FROM tk_wpm_job WHERE job_key = :job_key), :message, true, :unread_status)");
    				$sql_status = $statement->execute(
    					array(
    						':job_key' => $job['job_key'],
    						':message' => 'Job status was changed to '.$job['status'],
    						':unread_status' => TkWordPressMedicMessageStatus::Unread
    					)
    				);
          }
  			}

  			// if there are any messages (errors), roll back for this job
  			if (count($job_messages) > 0)
  			{
  				$this->pdo->rollBack();
  			}
  			else
  			{
  				$this->pdo->commit();
  			}
  		}
    }

		// if there were no issues, update the last sync time for all jobs sent to the medic server.
    // last sync time is stored as UTC-7 which will match the medic server.
    // if this fails it will just trigger another sync sooner so that fail can be silent.
    if ($messages == null && $job_messages == null)
		{
			$sync_statement = $this->pdo->prepare("UPDATE tk_wpm_job SET last_sync = :now_tk WHERE job_key = :job_key");
			foreach ($sync_jobs as $job)
			{
        $utc_time = new DateTime(date('Y-m-d H:i:s', time()));
        $tk_time = $utc_time->sub(new DateInterval('PT7H'));
        $tk_time_string = $tk_time->format('Y-m-d H:i:s');
        $sql_status = $sync_statement->execute(
					array(
						':job_key' => $job['job_key'],
            ':now_tk' => $tk_time_string
					)
				);
			}
		}

    $response_data['messages'] = ($messages == null ? [] : $messages);
		$response_data['job-messages'] = ($job_messages == null ? [] : $job_messages);
		$response_data['updates-received'] = $updates_received;

		return $response_data;
	}

	public static function activity_log($type,$function,$details)
	{
		$factory = new TkWordPressMedicConnectionFactory;
		$pdo = $factory->getConnection();

		$statement = $pdo->prepare("INSERT INTO tk_wpm_activity_log (type,function,details) VALUES (:type,:function,:details)");
		$sql_status = $statement->execute(
			array(
				':type' => $type,
				':function' => $function,
				':details' => $details
			)
		);
	}

  /* Not required, SQL now targets passed page id
	public static function get_patch_pages($patch_id)
	{
		$factory = new ConnectionFactory;
		$pdo = $factory->getConnection();

		$statement = $pdo->pdo->prepare("SELECT page_id FROM tk_wpm_patch_page WHERE patch_id = :patch_id");
		$sql_status = $statement->execute(
			array(
				':patch_id' => $patch_id
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			return json_encode(array('success'=>false,'diagnostic'=>'Could not get patch pages. '.$error[2]));
		}
		$pages = $statement->fetchColumn();
		return $pages;
	}*/

  public static function get_patch_pages($patch_id)
	{
    $factory = new TkWordPressMedicConnectionFactory;
    $pdo = $factory->getConnection();

		$statement = $pdo->prepare("SELECT page_id FROM tk_wpm_patch_page WHERE patch_id = :patch_id");
		$sql_status = $statement->execute(
			array(
				':patch_id' => $patch_id
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			TKWordPressMedicAdmin::activity_log('error', 'admin-get-patch-pages', 'Could not get pages for patch '.$patch_id.': '.$error[2]);
			return [];
		}
		$pages = $statement->fetchAll(PDO::FETCH_COLUMN);
		return $pages;
	}

	public static function get_patch_by_key($key)
	{
		$factory = new TkWordPressMedicConnectionFactory;
		$pdo = $factory->getConnection();

		$statement = $pdo->prepare("SELECT * FROM tk_wpm_patch WHERE patch_key = :patch_key LIMIT 1");
		$sql_status = $statement->execute(
			array(
				':patch_key' => $key
			)
		);
		if (!$sql_status)
		{
			$error = $statement->errorInfo();
			return json_encode(array('success'=>false,'diagnostic'=>'Could not get patch: '.$error[2]));
		}
		$patch = $statement->fetch(PDO::FETCH_ASSOC);
		return $patch;
	}

  private function validate_option_key($option_key)
  {
    $statement = $this->pdo->prepare("SELECT COUNT(*) FROM tk_wpm_parameter q JOIN tk_wpm_patch p ON q.patch_key = p.patch_key
      WHERE name = :option_key AND p.is_active = 1");
    $sql_status = $statement->execute(
      array(
        ':option_key' => $option_key
      )
    );
    if (!$sql_status)
    {
      $error = $statement->errorInfo();
      TKWordPressMedicAdmin::activity_log('error', 'validate_option_key', 'Could not validate parameter: '.$error[2]);
      return false;
    }
    $valid_parameter_count = $statement->fetchColumn();
    if ($valid_parameter_count == 1)
    {
      return true;
    }
    else
    {
      if ($valid_parameter_count == 0)
      {
        TKWordPressMedicAdmin::activity_log('warning', 'validate_option_key', 'Inactive parameter '.$option_key.' was accessed');
      }
      else
      {
        TKWordPressMedicAdmin::activity_log('error', 'validate_option_key', 'Parameter '.$option_key.' was defined more than once in the database.');
      }
      return false;
    }
  }

	function tk_wpm_Admin($args)
	{
		global $medic_api_base_url;
		if (!$this->pdo)
		{
			echo json_encode(array('success'=>false,'diagnostic'=>$this->status));
			die();
		}

		TkWordPressMedicAdmin::unquote();

		if (isset($_POST['command']))
		{
			switch ($_POST['command'])
			{
        case 'verify-account':

          // update the account locally to ensure the email address is correct
          $account = $this->get_account();
          $statement = $this->pdo->prepare("UPDATE tk_wpm_account SET email = :email WHERE account_key = :account_key");
					$sql_status = $statement->execute(
            array(
              ':email' => $_POST['email'],
              ':account_key' => $account['account_key']
            )
          );
          $account['email'] = $_POST['email'];

          // request email from the medic server
          $tk_request = curl_init($medic_api_base_url.'verify-account/');
					curl_setopt_array($tk_request, array(
						CURLOPT_POST => TRUE,
						CURLOPT_RETURNTRANSFER => TRUE,
						CURLOPT_HTTPHEADER => array(
								'Content-Type: application/json',
								'Content-Length: '.strlen(json_encode($account))
						),
						CURLOPT_POSTFIELDS => json_encode($account)
					));
					$response = curl_exec($tk_request);
					if($response === FALSE)
					{
						TKWordPressMedicAdmin::activity_log('error', 'submit-job', 'Could not submit job verification: '.curl_error($tk_request));
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not submit job verification: '.curl_error($tk_request)));
						die();
					}

          //$response_data = json_decode($response, TRUE);
          //echo json_encode($response_data);
          echo $response;

          break;

				case 'create-job':

					$name = wp_kses($_POST['name'],[]);
					$description = wp_kses($_POST['description'],[]);
					$url = $this->add_scheme(wp_kses($_POST['url'],[]));

					//$budget = $_POST['budget'];

					$statement = $this->pdo->prepare("INSERT INTO tk_wpm_job (job_key,name,description,url,status)
						VALUES (:job_key,:name,:description,:url,:status)");
					$sql_status = $statement->execute(
						array(
							':job_key' => TkWordPressMedic::unique_id(),
							':name' => $name,
							':description' => $description,
							':url' => $url,
							':status' => TkWordPressMedicJobStatus::Created
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'create-job', 'Could not create job. '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not create job. '.$error[2]));
						die();
					}

					TKWordPressMedicAdmin::activity_log('success', 'create-job', 'Job created successfully');
					echo json_encode(array('success' => true));
					die();

					break;

				case 'update-job':

					$job_id = $_POST['job-id'];
					$name = wp_kses($_POST['name'],[]);
					$description = wp_kses($_POST['description'], array());
					$url = wp_kses($_POST['url'],[]);

					$statement = $this->pdo->prepare("UPDATE tk_wpm_job SET name = :name, description = :description, url = :url
						WHERE id = :job_id");
					$sql_status = $statement->execute(
						array(
							':job_id' => $job_id,
							':name' => $name,
							':description' => $description,
							':url' => $url
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'update-job', 'Could not update job. '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not update job. '.$error[2]));
						die();
					}

					TKWordPressMedicAdmin::activity_log('success', 'update-job', 'Job updated successfully');

					echo json_encode(array('success'=>true));
					break;

				case 'delete-job':

					if (isset($_POST['job-id']) && !empty($_POST['job-id']))
					{
            $this->pdo->beginTransaction();

            // remove messages
            $statement = $this->pdo->prepare("DELETE FROM tk_wpm_message WHERE job_id = :job_id");
						$sql_status = $statement->execute(
							array(
								':job_id' => $_POST['job-id']
							)
						);
						if (!$sql_status)
						{
              $this->pdo->rollBack();
							$error = $statement->errorInfo();
							TKWordPressMedicAdmin::activity_log('error', 'delete-job', 'Could not delete messages for job '.$_POST['job-id'].': '.$error[2]);
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not delete messages for job '.$_POST['job-id'].': '.$error[2]));
							die();
						}

            // remove parameters
            $statement = $this->pdo->prepare("DELETE FROM tk_wpm_parameter WHERE patch_key IN (SELECT patch_key FROM tk_wpm_patch WHERE job_id = :job_id)");
						$sql_status = $statement->execute(
							array(
								':job_id' => $_POST['job-id']
							)
						);
						if (!$sql_status)
						{
              $this->pdo->rollBack();
							$error = $statement->errorInfo();
							TKWordPressMedicAdmin::activity_log('error', 'delete-job', 'Could not delete parameters for job '.$_POST['job-id'].': '.$error[2]);
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not delete parameters for job '.$_POST['job-id'].': '.$error[2]));
							die();
						}

            // remove patch pages
            $statement = $this->pdo->prepare("DELETE FROM tk_wpm_patch_page WHERE patch_id IN (SELECT id FROM tk_wpm_patch WHERE job_id = :job_id)");
						$sql_status = $statement->execute(
							array(
								':job_id' => $_POST['job-id']
							)
						);
						if (!$sql_status)
						{
              $this->pdo->rollBack();
							$error = $statement->errorInfo();
							TKWordPressMedicAdmin::activity_log('error', 'delete-job', 'Could not delete patch pages for job '.$_POST['job-id'].': '.$error[2]);
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not delete patch pages for job '.$_POST['job-id'].': '.$error[2]));
							die();
						}

            // remove patches
            $statement = $this->pdo->prepare("DELETE FROM tk_wpm_patch WHERE job_id = :job_id");
						$sql_status = $statement->execute(
							array(
								':job_id' => $_POST['job-id']
							)
						);
						if (!$sql_status)
						{
              $this->pdo->rollBack();
							$error = $statement->errorInfo();
							TKWordPressMedicAdmin::activity_log('error', 'delete-job', 'Could not delete patches for job '.$_POST['job-id'].': '.$error[2]);
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not delete patches for job '.$_POST['job-id'].': '.$error[2]));
							die();
						}

            // remove the job
            $statement = $this->pdo->prepare("DELETE FROM tk_wpm_job WHERE id = :job_id");
						$sql_status = $statement->execute(
							array(
								':job_id' => $_POST['job-id']
							)
						);
						if (!$sql_status)
						{
              $this->pdo->rollBack();
							$error = $statement->errorInfo();
							TKWordPressMedicAdmin::activity_log('error', 'delete-job', 'Could not delete job. '.$error[2]);
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not delete job. '.$error[2]));
							die();
						}
						if ($statement->rowCount() == 0)
						{
							TKWordPressMedicAdmin::activity_log('error', 'delete-job', 'Job '.$_POST['job-id'].' was not found');
							echo json_encode(array('success'=>false,'diagnostic'=>'Job '.$_POST['job-id'].' was not found'));
							die();
						}
            $this->pdo->commit();
						TKWordPressMedicAdmin::activity_log('success', 'delete-job', 'Job '.$_POST['job-id'].' was deleted');
						echo json_encode(array('success' => true));
						die();
					}
          else
          {
            echo json_encode(array('success'=>false,'diagnostic'=>'Job ID not supplied'));
            die();
          }

					break;

				/*case 'add-message':

					// add message to job
					$message = wp_kses($_POST['message'],[]);
					$job_id = $_POST['job-id'];

					$statement = $this->pdo->prepare("INSERT INTO tk_wpm_message (job_id,message,is_medic,status)
						VALUES (:job_id,:message,:is_medic,status)");
					$sql_status = $statement->execute(
						array(
							':job_id' => $job_id,
							':message' => $message,
							':is_medic' => 0
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'add-message', 'Could not add message for job '.$job_id.': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not add message for job '.$job_id.': '.$error[2]));
						die();
					}
					TKWordPressMedicAdmin::activity_log('success', 'add-message', 'Message was added for job '.$job_id);
					echo json_encode(array('success' => true));
					die();

					break;*/

				case 'submit-job':

					$job_key = $_POST['job-key'];

					// get the job details by key as well as the account details
					// the account needs to be matched to site_url since this database
					// may be used for more than one site, the url may have changed.
					// this and possibly more will result in more than one account record.
					$statement = $this->pdo->prepare("SELECT j.*, a.account_key, a.site_url FROM tk_wpm_job j JOIN tk_wpm_account a
						WHERE job_key = :job_key AND site_url = :site_url");
					$sql_status = $statement->execute(
						array(
							':job_key' => $job_key,
							':site_url' => site_url()
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'submit-job', 'Could not get job for key '.$job_key.': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not get job for key '.$job_key.': '.$error[2]));
						die();
					}
					$job = $statement->fetch(PDO::FETCH_ASSOC);

					// make the request
					$tk_request = curl_init($medic_api_base_url.'submit-job/');
					curl_setopt_array($tk_request, array(
						CURLOPT_POST => TRUE,
						CURLOPT_RETURNTRANSFER => TRUE,
						CURLOPT_HTTPHEADER => array(
								'Content-Type: application/json',
								'Content-Length: '.strlen(json_encode($job))
						),
						CURLOPT_POSTFIELDS => json_encode($job)
					));
					$response = curl_exec($tk_request);
					if($response === FALSE)
					{
						TKWordPressMedicAdmin::activity_log('error', 'submit-job', 'Could not submit job for key '.$job_key.': '.curl_error($tk_request));
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not submit job: '.curl_error($tk_request)));
						die();
					}
					$response_data = json_decode($response, TRUE);

					// if successful, update the status to Submitted
					if (!$response_data['success'])
					{
						TKWordPressMedicAdmin::activity_log('error', 'submit-job', 'Could not submit job for key '.$job_key.': '.$response_data['diagnostic']);
						echo json_encode(array('success'=>false,'diagnostic'=>$response_data['diagnostic']));
						die();
					}
					$statement = $this->pdo->prepare("UPDATE tk_wpm_job SET status = :submitted_status WHERE job_key = :job_key");
					$sql_status = $statement->execute(
						array(
							':job_key' => $job_key,
							':submitted_status' => TkWordPressMedicJobStatus::Submitted
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'submit-job', 'Could not update job status for key '.$job_key.': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not update job status for key '.$job_key.': '.$error[2]));
						die();
					}

					TKWordPressMedicAdmin::activity_log('success', 'submit-job', 'Job for key '.$job_key.' was submitted');
					echo json_encode(array('success'=>true));
					die();

				case 'send-message':

          $job_id = $_POST['job-id'];
					$message_text = wp_kses($_POST['message'],[]);

					// get the job key and account key
					$statement = $this->pdo->prepare("SELECT job_key, (SELECT account_key FROM tk_wpm_account ORDER BY create_date DESC LIMIT 1) as account_key
            FROM tk_wpm_job WHERE id = :job_id AND status != :demo_status");
					$sql_status = $statement->execute(
						array(
							':job_id' => $job_id,
							':demo_status' => TkWordPressMedicJobStatus::Demo
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'send-message', 'Could not get job key for job '.$job_id.': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not get job key for job '.$job_id.': '.$error[2]));
						die();
					}
					$keys = $statement->fetch();
          $job_key = $keys['job_key'];
          $account_key = $keys['account_key'];

					if ($job_key != null)
					{
						$message['message'] = $message_text;
						$message['job_key'] = $job_key;
            $message['account-key'] = $account_key;

						// make the request
						$tk_request = curl_init($medic_api_base_url.'message/');
						curl_setopt_array($tk_request, array(
							CURLOPT_POST => TRUE,
							CURLOPT_RETURNTRANSFER => TRUE,
							CURLOPT_HTTPHEADER => array(
									'Content-Type: application/json'
							),
							CURLOPT_POSTFIELDS => json_encode($message)
						));
						$response = curl_exec($tk_request);
						if($response === FALSE)
						{
							TKWordPressMedicAdmin::activity_log('error', 'send-message', 'Could not send message job for job '.$job_id.': '.curl_error($tk_request));
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not send message for job '.$job_id.': '.curl_error($tk_request)));
							die();
						}
						$response_data = json_decode($response, TRUE);

						// if the request to the server was unsuccessful, abort
						if (!$response_data['success'])
						{
							if ($response_data == null)
							{
								TKWordPressMedicAdmin::activity_log('error', 'send-message', 'Request to medic server failed.');
								echo json_encode(array('success'=>false,'diagnostic'=>'Request to medic server failed.'));
							}
							else
							{
								TKWordPressMedicAdmin::activity_log('error', 'send-message', 'Could not send message for job '.$job_id.': '.$response_data['diagnostic']);
								echo json_encode(array('success'=>false,'diagnostic'=>$response_data['diagnostic']));
							}
							die();
						}
					}

					// save message locally if we have gotten this far (either the request to the medic server was successful
					// or we are only saving the message locally for a demo job)
					$statement = $this->pdo->prepare("INSERT INTO tk_wpm_message (job_id,message,is_medic,status)
						VALUES (:job_id,:message,false,:unread_status)");
					$sql_status = $statement->execute(
						array(
							':job_id' => $job_id,
							':message' => $_POST['message'],
							':unread_status' => TkWordPressMedicMessageStatus::Unread
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'send-message', 'Message for job '.$job_id.' was sent to the medic but could not be saved locally. Message content: '.$_POST['message'].': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Message was successfully sent to the medic but could not be saved locally. An attempt to store the message in the activity log was made however this may fail for the same reason (particulary if connectivity to the database was lost): '.$error[2]));
						die();
					}

					TKWordPressMedicAdmin::activity_log('success', 'send-message', 'Message for job '.$job_id.' was submitted');
					echo json_encode(array('success'=>true));
					die();

        case 'get-messages':

          $job_key = $_POST['job-key'];
          $statement = $this->pdo->prepare("SELECT * FROM tk_wpm_message WHERE job_id = (SELECT id FROM tk_wpm_job WHERE job_key = :job_key) ORDER BY create_date DESC");
          $sql_status = $statement->execute(
            array(
              ':job_key' => $job_key
            )
          );
          if (!$sql_status)
          {
            $error = $statement->errorInfo();
            $error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'get-messages', 'Could not get messages for job key '.$job_key.': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>$error[2]));
						die();
          }
          $messages = $statement->fetchAll(PDO::FETCH_ASSOC);

          echo json_encode(array('success'=>true,'messages'=>$messages));
          die();

        case 'get-patches':

          $job_key = $_POST['job-key'];
          $statement = $this->pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM tk_wpm_patch_page WHERE patch_id = p.id) as page_selection_count
      			FROM tk_wpm_patch p
            WHERE job_id = (SELECT id FROM tk_wpm_job WHERE job_key = :job_key)
            ORDER BY create_date");
          $sql_status = $statement->execute(
            array(
              ':job_key' => $job_key
            )
          );
          if (!$sql_status)
          {
            $error = $statement->errorInfo();
            $error = $statement->errorInfo();
            TKWordPressMedicAdmin::activity_log('error', 'get-patches', 'Could not get patches for job key '.$job_key.': '.$error[2]);
            echo json_encode(array('success'=>false,'diagnostic'=>$error[2]));
            die();
          }
          $patches = $statement->fetchAll(PDO::FETCH_ASSOC);

          foreach ($patches as $patch)
          {
            $output_parameters = null;
            // get the pages
            $patch['pages'] = TkWordPressMedicAdmin::get_patch_pages($patch['id']);
            // get the parameters
            $parameters = TkWordPressMedicAdmin::get_parameters($patch['id']);
            foreach ($parameters as $parameter)
            {
              $parameter['parameter_value'] = get_option($parameter['parameter_name']);
              $output_parameters[] = $parameter;
            }
            $patch['parameters'] = $output_parameters == null ? [] : $output_parameters;
            $output_patches[] = $patch;
          }

          echo json_encode(array('success'=>true,'patches'=>$output_patches));
          die();

        case 'set-message-status':

					$message_id = $_POST['message-id'];
					$status = $_POST['current-status'] == TkWordPressMedicMessageStatus::Unread ? TkWordPressMedicMessageStatus::Read : TkWordPressMedicMessageStatus::Unread;

					$statement = $this->pdo->prepare("UPDATE tk_wpm_message SET status = :status WHERE id = :message_id");
					$sql_status = $statement->execute(
						array(
							':status' => $status,
							':message_id' => $message_id
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'set-message-status', 'Could not change message status: '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>$error[2]));
						die();
					}

					echo json_encode(array('success'=>true));
					die();

				case 'clear-activity':

					$statement = $this->pdo->prepare("TRUNCATE TABLE tk_wpm_activity_log");
					$sql_status = $statement->execute();
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'clear-activity', 'Could not clear activity log: '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>$error[2]));
						die();
					}

					echo json_encode(array('success'=>true));
					die();

				case 'clear-activity-type':

					$activity_type = $_POST['type'];
					$statement = $this->pdo->prepare("DELETE FROM tk_wpm_activity_log WHERE type = :activity_type");
					$sql_status = $statement->execute(
						array(
							':activity_type' => $activity_type
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'clear-activity-type', 'Could not clear '.$activity_type.' entries from activity log: '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>$error[2]));
						die();
					}

					echo json_encode(array('success'=>true));
					die();

				case 'toggle-patch':

					$state = $_POST['state'];
					$patch_id = $_POST['patch-id'];

					$statement = $this->pdo->prepare("UPDATE tk_wpm_patch SET is_active = :is_active WHERE id = :patch_id");
					$sql_status = $statement->execute(
						array(
							':is_active' => $state == 'on' ? 1 : 0,
							':patch_id' => $patch_id
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'toggle-patch', 'Could not set patch '.$patch_id.' to '.$state.': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not set patch '.$patch_id.' to '.$state.': '.$error[2]));
						die();
					}

					// update medic server (the patch key is needed)
					$statement = $this->pdo->prepare("SELECT patch_key, (SELECT account_key FROM tk_wpm_account ORDER BY create_date DESC LIMIT 1) as account_key FROM tk_wpm_patch WHERE id = :patch_id");
					$sql_status = $statement->execute(
						array(
							':patch_id' => $patch_id
						)
					);
					// no error check, it's a diagnostics bonus if the medic server has this info but it's not fatal if it fails
					// update the medic server if there was no error
					if ($sql_status)
					{
						//TKWordPressMedicAdmin::activity_log('debug', 'toggle-patch', 'sending');
						$keys = $statement->fetch();
            $patch_key = $keys['patch_key'];
            $account_key = $keys['account_key'];
						$medic_server_request['state'] = $state;
						$medic_server_request['patch-key'] = $patch_key;
            $medic_server_request['account-key'] = $account_key;
						$tk_request = curl_init($medic_api_base_url.'toggle-patch/');
						curl_setopt_array($tk_request, array(
							CURLOPT_POST => TRUE,
							CURLOPT_RETURNTRANSFER => TRUE,
							CURLOPT_HTTPHEADER => array(
									'Content-Type: application/json'
							),
							CURLOPT_POSTFIELDS => json_encode($medic_server_request)
						));
						$response = curl_exec($tk_request);
						// no error check, it's a diagnostics bonus if the medic server has this info but it's not fatal if it fails
					}

					echo json_encode(array('success'=>true));
					die();

				case 'set-patch-behaviour':

					update_option('tk_wpm_patch_sync', 	$_POST['sync']);
					echo json_encode(array('success',true));
					die();

        case 'remove-patch':

          $patch_id = $_POST['patch-id'];
          $this->pdo->beginTransaction();

          // remove parameters
          $statement = $this->pdo->prepare("DELETE FROM tk_wpm_parameter
            WHERE patch_key = (SELECT patch_key FROM tk_wpm_patch WHERE id = :patch_id)");
					$sql_status = $statement->execute(
						array(
							':patch_id' => $patch_id
						)
					);
					if (!$sql_status)
					{
            $this->pdo->rollBack();
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'remove-patch', 'Could not remove patch '.$patch_id.' parameters: '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not remove patch '.$patch_id.' parameters: '.$error[2]));
						die();
					}

          // remove patch pages
          $statement = $this->pdo->prepare("DELETE FROM tk_wpm_patch_page
            WHERE patch_id = :patch_id");
					$sql_status = $statement->execute(
						array(
							':patch_id' => $patch_id
						)
					);
					if (!$sql_status)
					{
            $this->pdo->rollBack();
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'remove-patch', 'Could not remove patch '.$patch_id.' pages: '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not remove patch '.$patch_id.' pages: '.$error[2]));
						die();
					}

          // get the patch key for use when updating the medic server
          // we don't want the patch to appear active if we can help it
          // not critical so no error handling
          $keys = null;
          $statement = $this->pdo->prepare("SELECT patch_key, (SELECT account_key FROM tk_wpm_account ORDER BY create_date DESC LIMIT 1) as account_key FROM tk_wpm_patch WHERE id = :patch_id");
					$sql_status = $statement->execute(array(':patch_id' => $patch_id));
					if ($sql_status)
					{
            $keys = $statement->fetch();
					}

          // remove patch
          $statement = $this->pdo->prepare("DELETE FROM tk_wpm_patch WHERE id = :patch_id");
					$sql_status = $statement->execute(
						array(
							':patch_id' => $patch_id
						)
					);
					if (!$sql_status)
					{
            $this->pdo->rollBack();
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'remove-patch', 'Could not remove patch '.$patch_id.': '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not remove patch '.$patch_id.': '.$error[2]));
						die();
					}

          $this->pdo->commit();

          // update medic server (the patch key is needed)
          if ($keys !== null)
          {
  					$statement = $this->pdo->prepare("SELECT patch_key, (SELECT account_key FROM tk_wpm_account ORDER BY create_date DESC LIMIT 1) as account_key FROM tk_wpm_patch WHERE id = :patch_id");
  					$sql_status = $statement->execute(
  						array(
  							':patch_id' => $patch_id
  						)
  					);
  					// no error check, it's a diagnostics bonus if the medic server has this info but it's not fatal if it fails
  					// update the medic server if there was no error
  					if ($sql_status)
  					{
  						//TKWordPressMedicAdmin::activity_log('debug', 'toggle-patch', 'sending');
  						$patch_key = $keys['patch_key'];
              $account_key = $keys['account_key'];
  						$medic_server_request['state'] = 'off';
  						$medic_server_request['patch-key'] = $patch_key;
              $medic_server_request['account-key'] = $account_key;
  						$tk_request = curl_init($medic_api_base_url.'toggle-patch/');
  						curl_setopt_array($tk_request, array(
  							CURLOPT_POST => TRUE,
  							CURLOPT_RETURNTRANSFER => TRUE,
  							CURLOPT_HTTPHEADER => array(
  									'Content-Type: application/json'
  							),
  							CURLOPT_POSTFIELDS => json_encode($medic_server_request)
  						));
  						$response = curl_exec($tk_request);
  						// no error check, it's a diagnostics bonus if the medic server has this info but it's not fatal if it fails
  					}
          }

          echo json_encode(array('success'=>true));
          die();

				case 'accept-job':

					$job_key = $_POST['job-key'];

					$statement = $this->pdo->prepare("UPDATE tk_wpm_job SET status = :accepted_status WHERE job_key = :job_key");
					$sql_status = $statement->execute(
						array(
							':accepted_status' => TkWordPressMedicJobStatus::Accepted,
							':job_key' => $job_key
						)
					);
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'accept-job', 'Could not set job '.$job_id.' to Accepted: '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not set job '.$job_id.' to Accepted: '.$error[2]));
						die();
					}

					// update medic server (account key is needed for this)
					$statement = $this->pdo->prepare("SELECT account_key FROM tk_wpm_account WHERE site_url = :site_url");
					$sql_status = $statement->execute(
						array(
							':site_url' => site_url()
						)
					);
					// no error check, it's a diagnostics bonus if the medic server has this info but it's not fatal if it fails
					// update the medic server if there was no error
					if ($sql_status)
					{
						$account_key = $statement->fetchColumn();
						$medic_server_request['account-key'] = $account_key;
						$medic_server_request['job-key'] = $job_key;
						$tk_request = curl_init($medic_api_base_url.'accept-job/');
						curl_setopt_array($tk_request, array(
							CURLOPT_POST => TRUE,
							CURLOPT_RETURNTRANSFER => TRUE,
							CURLOPT_HTTPHEADER => array(
									'Content-Type: application/json'
							),
							CURLOPT_POSTFIELDS => json_encode($medic_server_request)
						));
						$response = curl_exec($tk_request);
						// no error check, it's a diagnostics bonus if the medic server has this info but it's not fatal if it fails
					}

					echo json_encode(array('success'=>true,'status'=>TkWordPressMedicJobStatus::Accepted));
					die();

				case 'set-pages':

					$patch_id = $_POST['patch-id'];
					$apply_to_all = $_POST['apply-to-all'] == 'true';
					$pages = $_POST['pages'];

					// the simplest way to go is to dump all pages for this patch (and all others for the job if instructed)
					$this->pdo->beginTransaction();
					if ($apply_to_all)
					{
						$patches = $this->get_sibling_patches($patch_id);
						$statement = $this->pdo->prepare("DELETE FROM tk_wpm_patch_page WHERE patch_id = :patch_id");
						foreach ($patches as $patch)
						{
							$sql_status = $statement->execute(
								array(
									':patch_id' => $patch['id']
								)
							);
							if (!$sql_status)
							{
								$this->pdo->rollBack();
								$error = $statement->errorInfo();
								TKWordPressMedicAdmin::activity_log('error', 'set-pages', 'Could not clear pages for patch '.$patch_id.' siblings: '.$error[2]);
								echo json_encode(array('success'=>false,'diagnostic'=>'Could not clear pages for patch '.$patch_id.' siblings: '.$error[2]));
								die();
							}
						}

						$statement = $this->pdo->prepare("INSERT INTO tk_wpm_patch_page (patch_id,page_id) VALUES (:patch_id,:page_id)");
						foreach ($patches as $patch)
						{
							foreach ($pages as $page)
							{
								$sql_status = $statement->execute(
									array(
										':patch_id' => $patch['id'],
										':page_id' => $page
									)
								);
								if (!$sql_status)
								{
									$this->pdo->rollBack();
									$error = $statement->errorInfo();
									TKWordPressMedicAdmin::activity_log('error', 'set-pages', 'Could not add page for patch '.$patch['id'].': '.$error[2]);
									echo json_encode(array('success'=>false,'diagnostic'=>'Could not add page for patch '.$patch['id'].': '.$error[2]));
									die();
								}
							}
						}
					}
					else
					{
						$statement = $this->pdo->prepare("DELETE FROM tk_wpm_patch_page WHERE patch_id = :patch_id");
						$sql_status = $statement->execute(
							array(
								':patch_id' => $patch_id
							)
						);
						if (!$sql_status)
						{
							$this->pdo->rollBack();
							$error = $statement->errorInfo();
							TKWordPressMedicAdmin::activity_log('error', 'set-pages', 'Could not clear pages for patch '.$patch_id.': '.$error[2]);
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not clear pages for patch '.$patch_id.': '.$error[2]));
							die();
						}

            if (isset($pages) && !empty($pages))
            {
  						$statement = $this->pdo->prepare("INSERT INTO tk_wpm_patch_page (patch_id,page_id) VALUES (:patch_id,:page_id)");
  						foreach ($pages as $page)
  						{
  							$sql_status = $statement->execute(
  								array(
  									':patch_id' => $patch_id,
  									':page_id' => $page
  								)
  							);
  							if (!$sql_status)
  							{
  								$this->pdo->rollBack();
  								$error = $statement->errorInfo();
  								TKWordPressMedicAdmin::activity_log('error', 'set-pages', 'Could not add page for patch '.$patch['id'].': '.$error[2]);
  								echo json_encode(array('success'=>false,'diagnostic'=>'Could not add page for patch '.$patch['id'].': '.$error[2]));
  								die();
  							}
  						}
            }
					}

					$this->pdo->commit();
					echo json_encode(array('success'=>true));
					die();

        case 'support':

          // send the support message through the api
          $message = wp_kses($_POST['message'],[]);

					// get the account
					$statement = $this->pdo->prepare("SELECT * FROM tk_wpm_account ORDER BY create_date DESC LIMIT 1");
					$sql_status = $statement->execute();
					if (!$sql_status)
					{
						$error = $statement->errorInfo();
						TKWordPressMedicAdmin::activity_log('error', 'support', 'Could not get account: '.$error[2]);
						echo json_encode(array('success'=>false,'diagnostic'=>'Could not get account for support request: '.$error[2]));
						die();
					}
					$account = $statement->fetch(PDO::FETCH_ASSOC);

					if ($account == null)
          {
            TKWordPressMedicAdmin::activity_log('error', 'support', 'No account found for support request');
						echo json_encode(array('success'=>false,'diagnostic'=>'No account found for support request'));
						die();
          }
          else
					{
						$support_request['message'] = $message;
						$support_request['account_key'] = $account['account_key'];
            $support_request['site_url'] = $account['site_url'];
            $support_request['email'] = $_POST['email'];

						// make the request
						$tk_request = curl_init($medic_api_base_url.'support/');
						curl_setopt_array($tk_request, array(
							CURLOPT_POST => TRUE,
							CURLOPT_RETURNTRANSFER => TRUE,
							CURLOPT_HTTPHEADER => array(
									'Content-Type: application/json'
							),
							CURLOPT_POSTFIELDS => json_encode($support_request)
						));
						$response = curl_exec($tk_request);
						if($response === FALSE)
						{
							TKWordPressMedicAdmin::activity_log('error', 'support', 'Could not send support request: '.curl_error($tk_request));
							echo json_encode(array('success'=>false,'diagnostic'=>'Could not send support request: '.curl_error($tk_request)));
							die();
						}
						$response_data = json_decode($response, TRUE);

						// if the request to the server was unsuccessful, abort
						if (!$response_data['success'])
						{
							if ($response_data == null)
							{
								TKWordPressMedicAdmin::activity_log('error', 'support', 'Request to medic server failed.');
								echo json_encode(array('success'=>false,'diagnostic'=>'Request to medic server failed.'));
							}
							else
							{
								TKWordPressMedicAdmin::activity_log('error', 'support', 'Could not support request: '.$response_data['diagnostic']);
								echo json_encode(array('success'=>false,'diagnostic'=>$response_data['diagnostic']));
							}

              // send the request via email
              if (mail(
                'tenkinetic@gmail.com',
                'tenKinetic Medic support request',
                json_encode($support_request)
              ))
              {
                echo json_encode(array('success'=>false,'diagnostic'=>'Support API request failed. Your request was successfully sent via email.'));
              }
              else
              {
                echo json_encode(array('success'=>false,'diagnostic'=>'Support API request failed. An attempt to send the request via email also failed.'));
              }

							die();
						}
					}

					TKWordPressMedicAdmin::activity_log('success', 'support', 'Support request was submitted');
					echo json_encode(array('success'=>true));
					die();

        case 'manual-sync':

					$sync_messages = $this->sync_jobs(true);
					if (count($sync_messages['job-messages']) == 0)
					{
						echo json_encode(array('success'=>true,'updates-received'=>$sync_messages['updates-received']));
						die();
					}
					else
					{
						foreach ($sync_messages as $message)
						{
							TKWordPressMedicAdmin::activity_log('error', 'job-sync', $message);
						}
						echo json_encode(array('success'=>false,'diagnostic'=>'There were issues with the job synchronisation. See the activity log for details.'));
						die();
					}

				case 'set-option':

					$option_key = $_POST['option-key'];
					$option_value = $_POST['option-value'];

          if (!$this->validate_option_key($option_key))
          {
            echo json_encode(array('success'=>false,'diagnostic'=>'Invalid option key'));
            die();
          }

					update_option($option_key, $option_value);
					echo json_encode(array('success'=>true));
					die();

				default:
					echo json_encode(array('success'=>false,'diagnostic'=>'Command not found'));
					die();
				break;
			}
		}
		die();
	}

	function tk_wpm_Public($args)
	{
		if (!$this->pdo)
		{
			echo json_encode(array('success'=>false,'diagnostic'=>$this->status));
			die();
		}

		if (isset($_POST['command']))
		{
			switch ($_POST['command'])
			{
				case 'get-option':

					$option_key = $_POST['option-key'];

          if (!$this->validate_option_key($option_key))
          {
            echo json_encode(array('success'=>false,'diagnostic'=>'Invalid option key'));
            die();
          }

					$option_value = get_option($option_key);
					echo json_encode(array('success'=>true,'option-value'=>$option_value,'option-hash'=>MD5($option_value)));
					die();

				default:
					echo json_encode(array('success'=>false,'diagnostic'=>'Command not found'));
					die();
				break;
			}
		}
		die();
	}

  public static function hash_key($key)
	{
		return MD5($key);
	}
}
?>
