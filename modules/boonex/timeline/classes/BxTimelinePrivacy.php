<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) BoonEx Pty Limited - http://www.boonex.com/
 * CC-BY License - http://creativecommons.org/licenses/by/3.0/
 *
 * @defgroup    Timeline Timeline
 * @ingroup     TridentModules
 *
 * @{
 */

class BxTimelinePrivacy extends BxTemplPrivacy
{
	public function __construct ($aOptions, $oTemplate = false)
    {
        parent::__construct ($aOptions, $oTemplate);
    }

    protected function convertActionToField($sAction)
    {
    	return strtolower('object_privacy_' . $sAction);
    }
}

/** @} */
