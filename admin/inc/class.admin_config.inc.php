<?php
/**
 * EGgroupware admin - New et2 site configuration
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb@stylite.de>
 * @package admin
 * @copyright (c) 2016-18 by Ralf Becker <rb@stylite.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

use EGroupware\Api;

/**
 * New site configuration for all apps using eTemplate2 $app/templates/default/config.xet
 */
class admin_config
{
	var $public_functions = array('index' => True);

	/**
	 * Upload function to store anonymous images into instance files_dir/anon_images
	 *
	 * @param array $file file info array
	 * @param array|string $value current value of login_background_file
	 *
	 */
	function ajax_upload_anon_images ($file, $value)
	{
		if (!isset($GLOBALS['egw_info']['user']['apps']['admin'])) die('no rights to be here!');
		$path = $GLOBALS['egw_info']['server']['files_dir'].'/anon-images';
		$success = false;
		$response = Api\Json\Response::get();
		if (is_array($file) && is_writable(dirname($path)))
		{
			if (!is_dir($path)) mkdir ($path);
			$tmp_file = array_keys($file);
			$destination = $path.'/'.$file[$tmp_file[0]]['name'];
			$success = rename($GLOBALS['egw_info']['server']['temp_dir'].'/'.$tmp_file[0],$destination);
		}
		if ($success)
		{
			$value = array_merge($value, array(
				$GLOBALS['egw_info']['server']['webserver_url'].'/api/anon_images.php?src='.urlencode($file[$tmp_file[0]]['name']).'&'.filemtime($destination),
			));
			$response->data($value);
		}
		else
		{
			$response->error(lang('Failed to upload %1',$destination));
		}
	}

	/**
	 * Removes images from anon-images directory
	 *
	 * @param type $files
	 */
	function remove_anon_images ($files)
	{
		if (!isset($GLOBALS['egw_info']['user']['apps']['admin'])) die('no rights to be here!');
		if ($files)
		{
			$base = $GLOBALS['egw_info']['server']['files_dir'].'/anon-images';
			foreach ($files as $file)
			{
				$parts2 = explode('anon_images.php?src=', $file);
				$parts = explode('&', $parts2[1]);
				$path = $base.'/'.urldecode($parts[0]);
				if (is_writable(dirname($base)) && file_exists($path))
				{
					unlink($path);
				}
			}
		}
	}

	/**
	 * List of configs
	 *
	 * @param type $_content
	 * @throws Api\Exception\WrongParameter
	 */
	function index($_content=null)
	{
		if (is_array($_content))
		{
			$_appname = $_content['appname'];
		}
		elseif (!empty($_GET['appname']) && isset($GLOBALS['egw_info']['apps'][$_GET['appname']]))
		{
			$_appname = $_GET['appname'];
		}
		else
		{
			throw new Api\Exception\WrongParameter("Wrong or missing appname parameter!");
		}
		if ($GLOBALS['egw']->acl->check('site_config_acce',1,'admin'))
		{
			Api\Framework::redirect_link('/index.php');
		}

		// load the translations of the app we show too, so they dont need to be in admin!
		if ($_appname != 'admin')
		{
			Api\Translation::add_app($_appname);
		}

		switch($_appname)
		{
			case 'admin':
			case 'addressbook':
			case 'calendar':
			case 'preferences':
				$appname = $_appname;
				$config_appname = 'phpgwapi';
				break;
			case 'phpgwapi':
			case '':
				/* This keeps the admin from getting into what is a setup-only config */
				Api\Framework::redirect_link('/admin/index.php?ajax=true');
				break;
			default:
				$appname = $_appname;
				$config_appname = $appname;
				break;
		}

		$c = new Api\Config($config_appname);
		$old = $c->read_repository();
		if ($_content['cancel'] || ($_content['save'] || $_content['apply']) && $GLOBALS['egw']->acl->check('site_config_acce',2,'admin'))
		{
			Api\Framework::redirect_link('/admin/index.php?ajax=true');
		}

		if ($_content['save'] || $_content['apply'])
		{
			// support old validation hooks
			$_POST = array('newsettings' => &$_content['newsettings']);

			// Remove actual files (cleanup) of deselected urls from login_background_file
			$this->remove_anon_images(array_diff($c->config_data['login_background_file'], $_content['newsettings']['login_background_file']));

			/* Load hook file with functions to validate each config (one/none/all) */
			Api\Hooks::single(array(
				'location' => 'config_validate',
			)+(array)$_content['newsettings'], $appname);

			foreach($_content['newsettings'] as $key => $config)
			{
				if ($config)
				{
					$c->config_data[$key] = $config;
					if (in_array($key, (array)$GLOBALS['egw_info']['server']['found_validation_hook'], true) && function_exists($key))
					{
						call_user_func($key, $config, $c);
						if($GLOBALS['config_error'])
						{
							$errors .= lang($GLOBALS['config_error']) . "\n";
							$GLOBALS['config_error'] = False;
						}
					}
				}
				// don't erase passwords, since we also don't print them
				elseif(strpos($key,'passwd') === false && strpos($key,'password') === false && strpos($key,'root_pw') === false)
				{
					unset($c->config_data[$key]);
				}
			}
			if(in_array('final_validation', (array)$GLOBALS['egw_info']['server']['found_validation_hook']) &&
				function_exists('final_validation'))
			{
				final_validation($_content['newsettings']);
				if($GLOBALS['config_error'])
				{
					$errors .= lang($GLOBALS['config_error']) . "\n";
					$GLOBALS['config_error'] = False;
				}
				unset($GLOBALS['egw_info']['server']['found_validation_hook']);
			}

			// compute real changes and their old values (null for removals)
			$modifications = array_udiff_assoc($c->config_data, $old, function($a, $b)
			{
				return (int)($a != $b);	// necessary to kope with arrays
			});
			$removals = array_udiff_assoc($old, $c->config_data, function($a, $b)
			{
				return (int)($a != $b);
			});
			$set = array_merge(array_fill_keys(array_keys($removals), null), $modifications);
			$old = array_filter($old, function($key) use ($set)
			{
				return array_key_exists($key, $set);
			}, ARRAY_FILTER_USE_KEY);
			if ($set)
			{
				$cmd = new admin_cmd_config($config_appname, $set, $old,
					$_content['admin_cmd']+array('appname' => $_appname));
				$msg = $cmd->run();
			}
			else
			{
				$msg = lang('Nothing to save.');
			}
			if(!$errors && !$_content['apply'])
			{
				Api\Framework::message($msg, 'success');
				Api\Framework::redirect_link('/index.php', array(
					'menuaction' => 'admin.admin_ui.index',
					'ajax' => 'true'
				), 'admin');
			}
		}

		if($errors)
		{
			Api\Framework::message(lang('Error') . ': ' . $errors, 'error');
			unset($errors);
			unset($GLOBALS['config_error']);
		}
		elseif ($_content['apply'])
		{
			Api\Framework::message($msg, 'success');
		}

		$sel_options = $readonlys = array();
		$config = $c->read_repository();

		// call "config" hook, allowing apps to overwrite config, eg. set default values,
		// or return options in "sel_options" keys
		$config['location'] = 'config';
		$ret = Api\Hooks::single($config, $appname);
		if (is_array($ret))
		{
			if (isset($ret['sel_options'])) $sel_options = $ret['sel_options'];
			$config = array_merge($config, $ret);
		}

		$tmpl = new Api\Etemplate($appname.'.config');
		$path = (parse_url($tmpl->rel_path, PHP_URL_SCHEME) !== 'vfs' ? EGW_SERVER_ROOT : '').$tmpl->rel_path;
		$content = array(
			'tabs' => $_content['tabs'],
			'tabs2' => $_content['tabs2'],
			'template' => $appname.'.config',
			'newsettings' => array(),
		);

		// for security reasons we do not send all config to client-side, but only ones mentioned in templates
		$matches = null;
		preg_match_all('/id="newsettings\[([^]]+)\]/', file_get_contents($path), $matches, PREG_PATTERN_ORDER);
		foreach($matches[1] as $name)
		{
			$content['newsettings'][$name] = isset($config[$name]) ? $config[$name] : '';
		}

		// make everything readonly and remove save/apply button, if user has not rights to store config
		if ($GLOBALS['egw']->acl->check('site_config_acce',2,'admin'))
		{
			$readonlys[__ALL__] = true;
			$readonlys['cancel'] = false;
		}

		$tmpl->read('admin.site-config');
		$method = (get_called_class() == __CLASS__) ? 'admin.admin_config.index' : "$appname.".get_called_class().'.'.__FUNCTION__;
		$tmpl->exec($method, $content, $sel_options, $readonlys, array('appname' => $appname));
	}
}
