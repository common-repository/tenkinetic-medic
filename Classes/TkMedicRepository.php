<?php

class TkWordPressMedicRepository
{
	public static function get_patches()
	{
    // get the account
    $account = TkWordPressMedicAdmin::get_account();
    if ($account == null)
    {
      TKWordPressMedicAdmin::activity_log('error', 'repository-load', 'Could not get account');
      return [];
    }

    // get the repository metadata`
    $tk_request = curl_init($medic_api_base_url.'repository-patches/');
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
      return json_encode(array('success'=>false,'diagnostic'=>'Could not get repository patches: '.curl_error($tk_request)));
    }
    $response_data = json_decode($response, TRUE);
    return $response_data;
	}
}
?>
