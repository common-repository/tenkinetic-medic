<?php

class TkWordPressMedicLoader
{
	public function __construct()
	{
		add_action('wp_footer', 'tk_wpm_javascript');
		//add_action('admin_footer', 'tk_wpm_javascript');

		add_action('wp_footer', 'tk_wpm_css');
		//add_action('admin_footer', 'tk_wpm_css');

		function tk_wpm_javascript()
    {
    	global $post;
			// get active JS patches for the current page (or that are global)
			$patches = TkWordPressMedicAdmin::get_patches(TkWordPressMedicPatchType::Js, $post->ID);
			?>
			<script id="tk-wpm-js" type="text/javascript">
			jQuery(window).load(function()
			{
				<?php
				foreach ($patches as $patch)
				{
					echo $patch['content'];
				}
				?>
			});
			</script><?php
		}

		function tk_wpm_css()
    {
    	global $post;
			// get active CSS patches
			$patches = TkWordPressMedicAdmin::get_patches(TkWordPressMedicPatchType::Css, $post->ID);
			echo '<style id="tk-wpm-css">';
			foreach ($patches as $patch)
			{
				// replace any parameter names found with the value of that parameter
				$output = $patch['content'];
				foreach ($patch['parameters'] as $parameter)
				{
					$output = str_replace($parameter['parameter_name'],get_option($parameter['parameter_name']),$output);
				}
				echo $output;
			}
			echo '</style>';
		}
	}

	function tk_wpm_RenderShortcode($args)
	{
		$args = shortcode_atts(array('key' => null), $args);
		$html_id = $args['id'];
		// render the requested HTML
		$patch = TkWordPressMedicAdmin::get_patch_by_key($args['key']);
		return $patch['content'];
	}
}
?>
