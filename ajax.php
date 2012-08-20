<?php

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Andreas Schempp 2009-2012
 * @author     Andreas Schempp <andreas@schempp.ch>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */


/**
 * Patch system eg. for flash upload
 * This allows to transmit Session-ID and User-Auth Key using GET-Paramenters
 */
@ini_set('session.use_only_cookies', '0');
if (isset($_GET['FE_USER_AUTH']))
{
	$_COOKIE['FE_USER_AUTH'] = $_GET['FE_USER_AUTH'];
}

// ajax.php is a frontend script
define('TL_MODE', 'FE');

// Start the session so we can access known request tokens
@session_start();

// Allow do bypass the token check if a known token is passed in
if (isset($_GET['bypassToken']) && ((is_array($_SESSION['REQUEST_TOKEN'][TL_MODE]) && in_array($_POST['REQUEST_TOKEN'], $_SESSION['REQUEST_TOKEN'][TL_MODE])) || $_SESSION['REQUEST_TOKEN'][TL_MODE] == $_POST['REQUEST_TOKEN']))
{
	define('BYPASS_TOKEN_CHECK', true);
}

// Initialize for Contao <= 2.9
elseif (!isset($_SESSION['REQUEST_TOKEN']))
{
	$arrPOST = $_POST;
	unset($_POST);
}

// Close session so Contao's initalization routine can use ini_set()
session_write_close();

// Initialize the system
require('system/initialize.php');

// Preserve $_POST data in Contao <= 2.9
if (version_compare(VERSION, '2.10', '<'))
{
	$_POST = $arrPOST;
}



/**
 * Ajax front end controller.
 */
class PageAjax extends PageRegular
{

	/**
	 * Initialize the object
	 */
	public function __construct()
	{
		// Load user object before calling the parent constructor
		$this->import('FrontendUser', 'User');
		parent::__construct();

		// Check whether a user is logged in
		define('BE_USER_LOGGED_IN', $this->getLoginStatus('BE_USER_AUTH'));
		define('FE_USER_LOGGED_IN', $this->getLoginStatus('FE_USER_AUTH'));
	}


	/**
	 * Run the controller
	 */
	public function run()
	{
		$intPage = (int) $this->Input->get('pageId');

		if (!$intPage)
		{
			$intPage = (int) $this->Input->get('page');
		}

		if ($intPage > 0)
		{
			// Get the current page object
			global $objPage;
			$objPage = $this->getPageDetails($intPage);

			if (version_compare(VERSION, '2.9', '>'))
			{
				// Define the static URL constants
				define('TL_FILES_URL', ($objPage->staticFiles != '' && !$GLOBALS['TL_CONFIG']['debugMode']) ? $objPage->staticFiles . TL_PATH . '/' : '');
				define('TL_SCRIPT_URL', ($objPage->staticSystem != '' && !$GLOBALS['TL_CONFIG']['debugMode']) ? $objPage->staticSystem . TL_PATH . '/' : '');
				define('TL_PLUGINS_URL', ($objPage->staticPlugins != '' && !$GLOBALS['TL_CONFIG']['debugMode']) ? $objPage->staticPlugins . TL_PATH . '/' : '');

				// Get the page layout
				$objLayout = $this->getPageLayout($objPage->layout);
				$objPage->template = strlen($objLayout->template) ? $objLayout->template : 'fe_page';
				$objPage->templateGroup = $objLayout->templates;

				// Store the output format
				list($strFormat, $strVariant) = explode('_', $objLayout->doctype);
				$objPage->outputFormat = $strFormat;
				$objPage->outputVariant = $strVariant;
			}

            if (version_compare(VERSION, '2.11', '>='))
            {
                // Use the global date format if none is set
                if ($objPage->dateFormat == '')
                {
                        $objPage->dateFormat = $GLOBALS['TL_CONFIG']['dateFormat'];
                }                

                if ($objPage->timeFormat == '')
                {                
                        $objPage->timeFormat = $GLOBALS['TL_CONFIG']['timeFormat'];
                }                

                if ($objPage->datimFormat == '')
                {                
                        $objPage->datimFormat = $GLOBALS['TL_CONFIG']['datimFormat'];
                }
            }

			$GLOBALS['TL_LANGUAGE'] = $objPage->language;
		}

		$this->User->authenticate();

		// Set language from _GET
		if (strlen($this->Input->get('language')))
		{
			$GLOBALS['TL_LANGUAGE'] = $this->Input->get('language');
		}

		unset($GLOBALS['TL_HOOKS']['outputFrontendTemplate']);
		unset($GLOBALS['TL_HOOKS']['parseFrontendTemplate']);

		$this->loadLanguageFile('default');

		if ($this->Input->get('action') == 'fmd')
		{
			$this->output($this->getFrontendModule($this->Input->get('id')));
		}

		if ($this->Input->get('action') == 'cte')
		{
			$this->output($this->getElement($this->Input->get('id')));
		}

		if ($this->Input->get('action') == 'ffl')
		{
			$this->output($this->getFormField($this->Input->get('id')));
		}

		if (is_array($GLOBALS['TL_HOOKS']['dispatchAjax']))
		{
			foreach ($GLOBALS['TL_HOOKS']['dispatchAjax'] as $callback)
			{
				$this->import($callback[0]);
				$varValue = $this->$callback[0]->$callback[1]();

				if ($varValue !== false)
				{
					$this->output($varValue);
				}
			}
		}

		header('HTTP/1.1 412 Precondition Failed');
		die('Invalid AJAX call.');
	}


	/**
	 * Generate a front end module and return it as HTML string
	 * @param integer
	 * @param string
	 * @return string
	 */
	protected function getFrontendModule($intId, $strColumn='main')
	{
		if (!strlen($intId) || $intId < 1)
		{
			header('HTTP/1.1 412 Precondition Failed');
			return 'Missing frontend module ID';
		}

		$objModule = $this->Database->prepare("SELECT * FROM tl_module WHERE id=?")
									->limit(1)
									->execute($intId);

		if ($objModule->numRows < 1)
		{
			header('HTTP/1.1 404 Not Found');
			return 'Frontend module not found';
		}

		// Show to guests only
		if ($objModule->guests && FE_USER_LOGGED_IN && !BE_USER_LOGGED_IN && !$objModule->protected)
		{
			header('HTTP/1.1 403 Forbidden');
			return 'Forbidden';
		}

		// Protected element
		if (!BE_USER_LOGGED_IN && $objModule->protected)
		{
			if (!FE_USER_LOGGED_IN)
			{
				header('HTTP/1.1 403 Forbidden');
				return 'Forbidden';
			}

			$this->import('FrontendUser', 'User');
			$groups = deserialize($objModule->groups);

			if (!is_array($groups) || count($groups) < 1 || count(array_intersect($groups, $this->User->groups)) < 1)
			{
				header('HTTP/1.1 403 Forbidden');
				return 'Forbidden';
			}
		}

		$strClass = $this->findFrontendModule($objModule->type);

		// Return if the class does not exist
		if (!$this->classFileExists($strClass))
		{
			$this->log('Module class "'.$GLOBALS['FE_MOD'][$objModule->type].'" (module "'.$objModule->type.'") does not exist', 'Ajax getFrontendModule()', TL_ERROR);

			header('HTTP/1.1 404 Not Found');
			return 'Frontend module class does not exist';
		}

		$objModule->typePrefix = 'mod_';
		$objModule = new $strClass($objModule, $strColumn);

		return $this->Input->get('g') == '1' ? $objModule->generate() : $objModule->generateAjax();
	}


	/**
	 * Generate a content element return it as HTML string
	 * @param integer
	 * @return string
	 */
	protected function getElement($intId)
	{
		if (!strlen($intId) || $intId < 1)
		{
			header('HTTP/1.1 412 Precondition Failed');
			return 'Missing content element ID';
		}

		$objElement = $this->Database->prepare("SELECT * FROM tl_content WHERE id=?")
									 ->limit(1)
									 ->execute($intId);

		if ($objElement->numRows < 1)
		{
			header('HTTP/1.1 404 Not Found');
			return 'Content element not found';
		}

		// Show to guests only
		if ($objElement->guests && FE_USER_LOGGED_IN && !BE_USER_LOGGED_IN && !$objElement->protected)
		{
			header('HTTP/1.1 403 Forbidden');
			return 'Forbidden';
		}

		// Protected element
		if ($objElement->protected && !BE_USER_LOGGED_IN)
		{
			if (!FE_USER_LOGGED_IN)
			{
				header('HTTP/1.1 403 Forbidden');
				return 'Forbidden';
			}

			$this->import('FrontendUser', 'User');
			$groups = deserialize($objElement->groups);

			if (!is_array($groups) || count($groups) < 1 || count(array_intersect($groups, $this->User->groups)) < 1)
			{
				header('HTTP/1.1 403 Forbidden');
				return 'Forbidden';
			}
		}

		$strClass = $this->findContentElement($objElement->type);

		// Return if the class does not exist
		if (!$this->classFileExists($strClass))
		{
			$this->log('Content element class "'.$strClass.'" (content element "'.$objElement->type.'") does not exist', 'Ajax getContentElement()', TL_ERROR);

			header('HTTP/1.1 404 Not Found');
			return 'Content element class does not exist';
		}

		$objElement->typePrefix = 'ce_';
		$objElement = new $strClass($objElement);

		if ($this->Input->get('g') == '1')
		{
			$strBuffer = $objElement->generate();

			// HOOK: add custom logic
			if (isset($GLOBALS['TL_HOOKS']['getContentElement']) && is_array($GLOBALS['TL_HOOKS']['getContentElement']))
			{
				foreach ($GLOBALS['TL_HOOKS']['getContentElement'] as $callback)
				{
					$this->import($callback[0]);
					$strBuffer = $this->$callback[0]->$callback[1]($objElement, $strBuffer);
				}
			}

			return $strBuffer;
		}
		else
		{
			return $objElement->generateAjax();
		}
	}


	/**
	 * Generate a form field
	 * @param  int
	 * @return string
	 */
	protected function getFormField($strId)
	{
		if (!strlen($strId) || !isset($_SESSION['AJAX-FFL'][$strId]))
		{
			header('HTTP/1.1 412 Precondition Failed');
			return 'Missing form field ID';
		}

		$arrConfig = $_SESSION['AJAX-FFL'][$strId];

		$strClass = strlen($GLOBALS['TL_FFL'][$arrConfig['type']]) ? $GLOBALS['TL_FFL'][$arrConfig['type']] : $GLOBALS['BE_FFL'][$arrConfig['type']];

		if (!$this->classFileExists($strClass))
		{
			$this->log('Form field class "'.$strClass.'" (form field "'.$arrConfig['type'].'") does not exist', 'Ajax getFormField()', TL_ERROR);

			header('HTTP/1.1 404 Not Found');
			return 'Form field class does not exist';
		}

		$objField = new $strClass($arrConfig);

		return $objField->generateAjax();
	}


	/**
	 * Output data, encode to json and replace insert tags
	 * @param  mixed
	 * @return string
	 */
	protected function output($varValue)
	{
		$varValue = $this->replaceTags($varValue);

		if (version_compare(VERSION, '2.9', '>'))
		{
			$varValue = json_encode(array
			(
				'token'		=> REQUEST_TOKEN,
				'content'	=> $varValue,
			));
		}
		elseif (is_array($varValue) || is_object($varValue))
		{
			$varValue = json_encode($varValue);
		}

		echo $varValue;
		exit;
	}


	/**
	 * Recursively replace inserttags in the return value
	 * @param	array|string
	 * @return	array|string
	 */
	private function replaceTags($varValue)
	{
		if (is_array($varValue))
		{
			foreach( $varValue as $k => $v )
			{
				$varValue[$k] = $this->replaceTags($v);
			}

			return $varValue;
		}
		elseif (is_object($varValue))
		{
			return $varValue;
		}

		return $this->replaceInsertTags($varValue);
	}
}


/**
 * Instantiate controller
 */
$objPageAjax = new PageAjax();
$objPageAjax->run();

