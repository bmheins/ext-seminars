<?php
/***************************************************************
* Copyright notice
*
* (c) 2005-2006 Oliver Klee (typo3-coding@oliverklee.de)
* All rights reserved
*
* This script is part of the TYPO3 project. The TYPO3 project is
* free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* The GNU General Public License can be found at
* http://www.gnu.org/copyleft/gpl.html.
*
* This script is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Class 'tx_seminars_dbplugin' for the 'seminars' extension.
 *
 * It defines the database table names, provides the configuration
 * and calles the base class init functions.
 *
 * This is an abstract class; don't instantiate it.
 *
 * @author	Oliver Klee <typo3-coding@oliverklee.de>
 */

require_once(PATH_t3lib.'class.t3lib_tstemplate.php');
require_once(PATH_t3lib.'class.t3lib_page.php');
require_once(t3lib_extMgm::extPath('seminars').'class.tx_seminars_configcheck.php');
require_once(t3lib_extMgm::extPath('seminars').'class.tx_seminars_salutationswitcher.php');

class tx_seminars_dbplugin extends tx_seminars_salutationswitcher {
	/** The extension key. */
	var $extKey = 'seminars';

	/** whether init() already has been called (in order to avoid double calls) */
	var $isInitialized = false;

	// Database table names. Will be initialized (indirectly) by $this->init.
	var $tableSeminars;
	var $tableVipsMM;
	var $tableSpeakers;
	var $tableSpeakersMM;
	var $tableSites;
	var $tableSitesMM;
	var $tableOrganizers;
	var $tableAttendances;
	var $tablePaymentMethods;
	var $tableEventTypes;

	/** The frontend user who currently is logged in. */
	var $feuser = null;

	/** The configuration check object that will check this object. */
	var $configurationCheck;

	/**
	 * Dummy constructor: Does nothing.
	 *
	 * The base classe's constructor is called in $this->init().
	 */
	function tx_seminars_dbplugin() {
	}

	/**
	 * Initializes the FE plugin stuff, read the configuration
	 * and set the table names while we're at it.
	 *
	 * It is harmless if this function gets called multiple times as it recognizes
	 * this and ignores all calls but the first one.
	 *
	 * This is merely a convenience function.
	 *
	 * If the parameter is ommited, the configuration for plugin.tx_seminar is used instead.
	 *
 	 * @param	array		TypoScript configuration for the plugin
	 *
	 * @access	protected
	 */
	function init($conf = null) {
		static $cachedConfigs = array();

		if (!$this->isInitialized) {
			if ($GLOBALS['TSFE'] && !isset($GLOBALS['TSFE']->config['config'])) {
				$GLOBALS['TSFE']->config['config'] = array();
			}

			// call the base classe's constructor manually as this isn't done automatically
			parent::tslib_pibase();

			if ($conf !== null) {
				$this->conf = $conf;
			} else {
				// We need to create our own template setup if we are in the BE
				// and we aren't currently creating a DirectMail page.
				if ((TYPO3_MODE == 'BE') && !is_object($GLOBALS['TSFE'])) {
					$pageId = intval(t3lib_div::_GP('id'));

					if (isset($cachedConfigs[$pageId])) {
						$this->conf =& $cachedConfigs[$pageId];
					} else {
						$template = t3lib_div::makeInstance('t3lib_TStemplate');
						// do not log time-performance information
						$template->tt_track = 0;
						$template->init();

						// Get the root line
						$sys_page = t3lib_div::makeInstance('t3lib_pageSelect');
						// the selected page in the BE is found
						// exactly as in t3lib_SCbase::init()
						$rootline = $sys_page->getRootLine($pageId);

						// This generates the constants/config + hierarchy info for the template.
						$template->runThroughTemplates($rootline, 0);
						$template->generateConfig();

						$this->conf =& $template->setup['plugin.']['tx_'.$this->extKey.'.'];
						$cachedConfigs[$pageId] =& $this->conf;
					}
				} else {
					// On the front end, we can use the provided template setup.
					$this->conf =& $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_'.$this->extKey.'.'];
				}
			}

			$this->pi_setPiVarDefaults();
			$this->pi_loadLL();

			$this->setTableNames();

			// unserialize the configuration array
			$globalConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['seminars']);

			if ($globalConfiguration['enableConfigCheck']) {
				$configurationCheckClassname = t3lib_div::makeInstanceClassName('tx_seminars_configcheck');
				$this->configurationCheck =& new $configurationCheckClassname($this);
			} else {
				$this->configurationCheck = null;
			}

			$this->isInitialized = true;
		}

		return;
	}

	/**
	 * Sets the table names.
	 *
	 * @access	private
	 */
	function setTableNames() {
		$dbPrefix = 'tx_'.$this->extKey.'_';

		$this->tableSeminars       = $dbPrefix.'seminars';
		$this->tableSpeakers       = $dbPrefix.'speakers';
		$this->tableSites          = $dbPrefix.'sites';
		$this->tableOrganizers     = $dbPrefix.'organizers';
		$this->tableAttendances    = $dbPrefix.'attendances';
		$this->tablePaymentMethods = $dbPrefix.'payment_methods';
		$this->tableEventTypes     = $dbPrefix.'event_types';

		$this->tableVipsMM         = $dbPrefix.'seminars_feusers_mm';
		$this->tableSpeakersMM     = $dbPrefix.'seminars_speakers_mm';
		$this->tableSitesMM        = $dbPrefix.'seminars_place_mm';

		return;
	}

	/**
	 * Gets a value from flexforms or TS setup.
	 * The priority lies on flexforms; if nothing is found there, the value
	 * from TS setup is returned. If there is no field with that name in TS setup,
	 * an empty string is returned.
	 *
	 * @param	string		field name to extract
	 * @param	string		sheet pointer, eg. "sDEF"
	 * @param	boolean		whether this is a filename, which has to be combined with a path
	 * @param	boolean		whether to ignore the flexform values and just get the settings from TypoScript, may be empty
	 *
	 * @return	string		the value of the corresponding flexforms or TS setup entry (may be empty)
	 *
	 * @access	private
	 */
	function getConfValue($fieldName, $sheet = 'sDEF', $isFileName = false, $ignoreFlexform = false) {
		$flexformsValue = '';
		if (!$ignoreFlexform) {
			$flexformsValue = $this->pi_getFFvalue(
				$this->cObj->data['pi_flexform'],
				$fieldName,
				$sheet
			);
		}
		if ($isFileName && !empty($flexformsValue)) {
			$flexformsValue = $this->addPathToFileName($flexformsValue);
		}
		$confValue = isset($this->conf[$fieldName]) ? $this->conf[$fieldName] : '';

		return ($flexformsValue) ? $flexformsValue : $confValue;
	}

	/**
	 * Adds a path in front of the file name.
	 * This is used for files that are selected in the Flexform of the front end plugin.
	 *
	 * If no path is provided, the default (uploads/[extension_name]/) is used as path.
	 *
	 * An example (default, with no path provided):
	 * If the file is named 'template.tmpl', the output will be 'uploads/[extension_name]/template.tmpl'.
	 * The '[extension_name]' will be replaced by the name of the calling extension.
	 *
	 * @param	string		the file name
	 * @param	string		the path to the file (without filename), must contain a slash at the end, may contain a slash at the beginning (if not relative)
	 *
	 * @return	string		the complete path including file name
	 *
	 * @access	private
	 */
	function addPathToFileName($fileName, $path = '') {
		if (empty($path)) {
			$path = 'uploads/tx_'.$this->extKey.'/';
		}

		return $path.$fileName;
	}

	/**
	 * Gets a trimmed string value from flexforms or TS setup.
	 * The priority lies on flexforms; if nothing is found there, the value
	 * from TS setup is returned. If there is no field with that name in TS setup,
	 * an empty string is returned.
	 *
	 * @param	string		field name to extract
	 * @param	string		sheet pointer, eg. "sDEF"
	 * @param	boolean		whether this is a filename, which has to be combined with a path
	 * @param	boolean		whether to ignore the flexform values and just get the settings from TypoScript, may be empty
	 *
	 * @return	string		the trimmed value of the corresponding flexforms or TS setup entry (may be empty)
	 *
	 * @access	public
	 */
	function getConfValueString($fieldName, $sheet = 'sDEF', $isFileName = false, $ignoreFlexform = false) {
		return trim($this->getConfValue($fieldName, $sheet, $isFileName, $ignoreFlexform));
	}

	/**
	 * Checks whether a string value from flexforms or TS setup is set.
	 * The priority lies on flexforms; if nothing is found there, the value
	 * from TS setup is checked. If there is no field with that name in TS setup,
	 * false is returned.
	 *
	 * @param	string		field name to extract
	 * @param	string		sheet pointer, eg. "sDEF"
	 * @param	boolean		whether to ignore the flexform values and just get the settings from TypoScript, may be empty
	 *
	 * @return	boolean		whether there is a non-empty value in the corresponding flexforms or TS setup entry
	 *
	 * @access	public
	 */
	function hasConfValueString($fieldName, $sheet = 'sDEF', $ignoreFlexform = false) {
		return ($this->getConfValueString($fieldName, $sheet, false, $ignoreFlexform) != '');
	}

	/**
	 * Gets an integer value from flexforms or TS setup.
	 * The priority lies on flexforms; if nothing is found there, the value
	 * from TS setup is returned. If there is no field with that name in TS setup,
	 * zero is returned.
	 *
	 * @param	string		field name to extract
	 * @param	string		sheet pointer, eg. "sDEF"
	 *
	 * @return	integer		the inval'ed value of the corresponding flexforms or TS setup entry
	 *
	 * @access	public
	 */
	function getConfValueInteger($fieldName, $sheet = 'sDEF') {
		return intval($this->getConfValue($fieldName, $sheet));
	}

	/**
	 * Checks whether an integer value from flexforms or TS setup is set and non-zero.
	 * The priority lies on flexforms; if nothing is found there, the value
	 * from TS setup is checked. If there is no field with that name in TS setup,
	 * false is returned.
	 *
	 * @param	string		field name to extract
	 * @param	string		sheet pointer, eg. "sDEF"
	 *
	 * @return	boolean		whether there is a non-zero value in the corresponding flexforms or TS setup entry
	 *
	 * @access	public
	 */
	function hasConfValueInteger($fieldName, $sheet = 'sDEF') {
		return (boolean) $this->getConfValueInteger($fieldName, $sheet);
	}

	/**
	 * Gets a boolean value from flexforms or TS setup.
	 * The priority lies on flexforms; if nothing is found there, the value
	 * from TS setup is returned. If there is no field with that name in TS setup,
	 * false is returned.
	 *
	 * @param	string		field name to extract
	 * @param	string		sheet pointer, eg. "sDEF"
	 *
	 * @return	boolean		the boolean value of the corresponding flexforms or TS setup entry
	 *
	 * @access	public
	 */
	function getConfValueBoolean($fieldName, $sheet = 'sDEF') {
		return (boolean) $this->getConfValue($fieldName, $sheet);
	}

	/**
	 * Extracts a value within listView.
	 *
	 * @param	string		TS setup field name to extract (within listView.), must not be empty
	 *
	 * @return	string		the trimmed contents of that field within listView. (may be empty)
	 *
	 * @access	public
	 */
	function getListViewConfValueString($fieldName) {
		$result = '';
		if (isset($this->conf['listView.'])
			&& isset($this->conf['listView.'][$fieldName])) {
			$result = trim($this->conf['listView.'][$fieldName]);
		}

		return $result;
	}

	/**
	 * Checks whether a front end user is logged in.
	 *
	 * @return	boolean		true if a user is logged in, false otherwise
	 *
	 * @access	public
	 */
	function isLoggedIn() {
		return ((boolean) $GLOBALS['TSFE']) && ((boolean) $GLOBALS['TSFE']->loginUser);
	}

	/**
	 * If a user logged in, retrieves that user's data as stored in the
	 * table "feusers" and stores it in $this->feuser.
	 *
	 * If no user is logged in, $this->feuser will be null.
	 *
	 * @access	private
	 */
	function retrieveFEUser() {
		$this->feuser = $this->isLoggedIn() ? $GLOBALS['TSFE']->fe_user->user : null;
	}

	/**
	 * Returns the UID of the currently logged-in FE user
	 * or 0 if no FE user is logged in.
	 *
	 * @return	integer		the UID of the logged-in FE user or 0 if no FE user is logged in
	 *
	 * @access	public
	 */
	function getFeUserUid() {
		// If we don't have the FE user's UID (yet), try to retrieve it.
		if (!$this->feuser) {
			$this->retrieveFEUser();
		}

		return ($this->isLoggedIn() ? intval($this->feuser['uid']) : 0);
	}

	/**
	 * Sets the "flavor" of the object to check.
	 *
	 * @param	string		a short string identifying the "flavor" of the object to check (may be empty)
	 *
	 * @access	public
	 */
	function setFlavor($flavor) {
		if ($this->configurationCheck) {
			$this->configurationCheck->setFlavor($flavor);
		}

		return;
	}

	/**
	 * Sets the error text of $this->configurationCheck.
	 *
	 * If this->configurationCheck is null, this function is a no-op.
	 *
	 * @param	string		error text to set (may be empty)
	 *
	 * @access	protected
	 */
	function setErrorMessage($message) {
		if ($this->configurationCheck) {
			$this->configurationCheck->setErrorMessage($message);
		}

		return;
	}

	/**
	 * Checks this object's configuration and returns a formatted error message
	 * (if any). If there are several objects of this class, still only one
	 * error message is created (in order to prevent duplicate messages).
	 *
	 * @param	boolean		whether to use the raw message instead of the wrapped message
	 *
	 * @return	string		a formatted error message (if there are errors) or an empty string
	 *
	 * @access	public
	 */
	function checkConfiguration($useRawMessage = false) {
		static $hasDisplayedMessage = false;
		$result = '';

		if ($this->configurationCheck) {
			$message = ($useRawMessage) ?
				$this->configurationCheck->checkIt() :
				$this->configurationCheck->checkItAndWrapIt();

			// If we have a message, only return it if it is the first message
			// for objects of this class.
			if (!empty($message) && !$hasDisplayedMessage) {
				$result = $message;
				$hasDisplayedMessage = true;
			}
		}

		return $result;
	}

	/**
	 * Wrapper function for t3lib_pageSelect::enableFields() since it is no longer
	 * accessible statically.
	 * 
	 * Returns a part of a WHERE clause which will filter out records with start/end
	 * times or deleted/hidden/fe_groups fields set to values that should de-select
	 * them according to the current time, preview settings or user login.
	 * Is using the $TCA arrays "ctrl" part where the key "enablefields" determines 
	 * for each table which of these features applies to that table.
	 *
	 * @param	string		table name found in the $TCA array
	 * @param	integer		If $show_hidden is set (0/1), any hidden-fields in
	 * 						records are ignored. NOTICE: If you call this function,
	 * 						consider what to do with the show_hidden parameter.
	 * 						Maybe it should be set? See tslib_cObj->enableFields
	 * 						where it's implemented correctly.
	 * @param	array		Array you can pass where keys can be "disabled",
	 * 						"starttime", "endtime", "fe_group" (keys from 
	 * 						"enablefields" in TCA) and if set they will make sure
	 * 						that part of the clause is not added. Thus disables
	 * 						the specific part of the clause. For previewing etc.
	 * @param	boolean		If set, enableFields will be applied regardless of
	 * 						any versioning preview settings which might otherwise
	 * 						disable enableFields.
	 * @return	string		the clause starting like " AND ...=... AND ...=..."
	 * 
	 * @access	protected
	 */
	function enableFields($table, $show_hidden = -1, $ignore_array = array(), $noVersionPreview = false) {
		$pageSelect = t3lib_div::makeInstance('t3lib_pageSelect');
		return $pageSelect->enableFields(
			$table,
			$show_hidden,
			$ignore_array,
			$noVersionPreview
		);
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/seminars/class.tx_seminars_dbplugin.php']) {
	include_once ($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/seminars/class.tx_seminars_dbplugin.php']);
}

?>
