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
 * @copyright  Andreas Schempp 2009-2010
 * @author     Andreas Schempp <andreas@schempp.ch>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 * @version    $Id$
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

$arrPOST = $_POST;
unset($_POST);


/**
 * Initialize the system
 */
define('TL_MODE', 'FE');
require('system/initialize.php');


// Preserve $_POST data
$_POST = $arrPOST;


/**
 * Ajax front end controller.
 */
class Ajax extends Frontend
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
		// Get the current page object
		global $objPage;
		$objPage = $this->getPageDetails((int)$this->Input->get('page'));
		
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
			$this->output($this->getContentElement($this->Input->get('id')));
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
			$arrGroups = deserialize($objModule->groups);
	
			if (is_array($arrGroups) && count(array_intersect($this->User->groups, $arrGroups)) < 1)
			{
				header('HTTP/1.1 403 Forbidden');
				return 'Forbidden';
			}
		}

		$strClass = $this->findFrontendModule($objModule->type);

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
	protected function getContentElement($intId)
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
		if ($objElement->type != 'comments' && !BE_USER_LOGGED_IN && $objElement->protected)
		{
			if (!FE_USER_LOGGED_IN)
			{
				header('HTTP/1.1 403 Forbidden');
				return 'Forbidden';
			}

			$this->import('FrontendUser', 'User');
			$arrGroups = deserialize($objElement->groups);
	
			if (is_array($arrGroups) && count(array_intersect($this->User->groups, $arrGroups)) < 1)
			{
				header('HTTP/1.1 403 Forbidden');
				return 'Forbidden';
			}
		}

		$strClass = $this->findContentElement($objElement->type);

		if (!$this->classFileExists($strClass))
		{
			$this->log('Content element class "'.$strClass.'" (content element "'.$objElement->type.'") does not exist', 'Ajax getContentElement()', TL_ERROR);
			
			header('HTTP/1.1 404 Not Found');
			return 'Content element class does not exist';
		}

		$objElement->typePrefix = 'ce_';
		$objElement = new $strClass($objElement);

		return $this->Input->get('g') == '1' ? $objElement->generate() : $objElement->generateAjax();
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
		if (is_array($varValue) || is_object($varValue))
		{
			$varValue = json_encode($varValue);
		}
		
		echo $this->replaceInsertTags($varValue);
		exit;
	}
}


/**
 * Instantiate controller
 */
$objAjax = new Ajax();
$objAjax->run();

