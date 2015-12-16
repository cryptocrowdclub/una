<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) BoonEx Pty Limited - http://www.boonex.com/
 * CC-BY License - http://creativecommons.org/licenses/by/3.0/
 *
 * @defgroup    BaseConnect Base classes for OAuth connect modules
 * @ingroup     TridentModules
 *
 * @{
 */

class BxBaseModConnectModule extends BxDolModule
{
    function __construct(&$aModule)
    {
        parent::__construct($aModule);
    }

    public function serviceGetProfilesModules ()
    {
        $aModules = array();
        $a = BxDolService::call('system', 'get_profiles_modules', array(), 'TemplServiceProfiles');
        foreach ($a as &$aModule)
            $aModules[$aModule['name']] = $aModule['title'];
        return $aModules;
    }

    /**
     * Logged profile
     *
     * @param $iProfileId integer
     * @param $sPassword string
     * @param $sCallbackUrl
     * @param $bRedirect boolean
     * @return void
     */
    function setLogged($iProfileId, $sCallbackUrl = '', $bRedirect = true)
    {
        $oProfile = BxDolProfile::getInstance($iProfileId);
 
        bx_login($oProfile->getAccountId());

        if ($bRedirect) {
            $sCallbackUrl = $sCallbackUrl
                ? $sCallbackUrl
                : $this -> _oConfig -> sDefaultRedirectUrl;

            header('Location: ' . $sCallbackUrl);
        }
    }

    /**
     * Create new profile;
     *
     * @param  : $aProfileInfo (array) - remote profile's information;
     *
     * @param  : $sAlternativeName (string) - profiles alternative nickname;
     */
    function _createProfile($aProfileInfo, $sAlternativeName = '')
    {
        $mixed = $this->_createProfileRaw($aProfileInfo, $sAlternativeName);

        // display error
        if (is_string($mixed)) {
            $this->_oTemplate->getPage(_t($this->_oConfig->sDefaultTitleLangKey), MsgBox($mixed));
            exit;
        } 

        // display join page
        if (is_array($mixed) && isset($mixed['join_page_redirect'])) {
            $this->_getJoinPage($mixed['profile_fields'], $mixed['remote_profile_info']['id']);
            exit;
        } 

        // continue profile creation
        if (is_array($mixed) && isset($mixed['profile_id'])) {
            $iProfileId = (int)$mixed['profile_id'];

            //redirect to other page
            header('location:' . $this->_getRedirectUrl($iProfileId, $mixed['existing_profile']));
            exit;
        }

        $this->_oTemplate->getPage( _t($this->_oConfig->sDefaultTitleLangKey), MsgBox(_t('_Error Occured')) );
        exit;
    }

    /**
     * @param $aProfileInfo - remote profile info
     * @param $sAlternativeName - suffix to add to NickName to make it unique
     * @return profile array info, ready for the local database
     */
    protected function _convertRemoteFields($aProfileInfo, $sAlternativeName = '')
    {
    }

    /**
     * Create new profile;
     *
     * @param  : $aProfileInfo (array) - remote profile's information;
     *
     * @param  : $sAlternativeName (string) - profiles alternative nickname;
     * @return : error string or error or request invite form or profile info array on success
     */
    function _createProfileRaw($aProfileInfo, $sAlternativeName = '', $isAutoFriends = true, $isSetLoggedIn = true)
    {
        $sCountry = '';
        $sCity = '';

        // join by invite only
        if (BxDolRequest::serviceExists('bx_invites', 'account_add_form_check') && $sCode = BxDolService::call('bx_invites', 'account_add_form_check'))
            return $sCode;

        // convert fields to unique format
        $aFieldsProfile = $aFieldsAccount = $this->_convertRemoteFields($aProfileInfo, $sAlternativeName);

        // prepare fields for particular module
        $aFieldsAccount = BxDolService::call('system', 'prepare_fields', array($aFieldsAccount));
        $aFieldsProfile = BxDolService::call(getParam('bx_facebook_connect_module'), 'prepare_fields', array($aFieldsProfile));

        // check fields existence in Account
        $oFormHelperAccount = BxDolService::call('system', 'forms_helper');
        $oFormAccount = $oFormHelperAccount->getObjectFormAdd();
        foreach ($aFieldsAccount as $sKey => $mValue) {
            if (!$oFormAccount->isFieldExist($sKey))
                unset($aFieldsAccount[$sKey]);
        }

        // check fields existence in Profile
        if ('system' != getParam('bx_facebook_connect_module') && $oFormHelperProfile = BxDolService::call(getParam('bx_facebook_connect_module'), 'forms_helper')) {
            $oFormProfile = $oFormHelperProfile->getObjectFormAdd();
            foreach ($aFieldsProfile as $sKey => $mValue) {
                if (!$oFormProfile->isFieldExist($sKey))
                    unset($aFieldsProfile[$sKey]);
            }
        }

        // antispam check
        $sErrorMsg = '';
        $bSetPendingApproval = false;
        bx_alert('account', 'check_join', 0, false, array('error_msg' => &$sErrorMsg, 'email' => $aFieldsAccount['email'], 'approve' => &$bSetPendingApproval));
        if ($sErrorMsg)
            return $sErrorMsg;

        // check if user with the same email already exists
        $oExistingAccount = BxDolAccount::getInstance($aFieldsAccount['email']);

        // check redirect page
        if ('join' == $this->_oConfig->sRedirectPage && !$oExistingAccount)
            return array('remote_profile_info' => $aProfileInfo, 'profile_fields' => $aProfileFields, 'join_page_redirect' => true);

        // create new profile
        if ($oExistingAccount) {

            if (!($oExistingProfile = BxDolProfile::getInstanceByAccount($oExistingAccount->id())))
                return _t('_sys_txt_error_account_creation');

            $iProfileId = $oExistingProfile->id();

            $this->setLogged($iProfileId);
        }
        else {

            // create account
            $aFieldsAccount['password'] = genRndPwd();
            $aFieldsAccount['email_confirmed'] = (bool)getParam('bx_facebook_connect_confirm_email'); 
            if (!($iAccountId = $oFormAccount->insert($aFieldsAccount)))
                return _t('_sys_txt_error_account_creation');

            $isSetPendingApproval = getParam('bx_facebook_connect_approve') ? false : !(bool)getParam('sys_account_autoapproval');
            $iAccountProfileId = $oFormHelperAccount->onAccountCreated ($iAccountId, $isSetPendingApproval, BX_PROFILE_ACTION_EXTERNAL);

            // create profile
            if (isset($oFormProfile) && $oFormProfile) {
                
                $aFieldsProfile['picture'] = $this->_processImage($aFieldsProfile, $iAccountProfileId, $oFormHelperProfile);

                if (!($iContentId = $oFormProfile->insert($aFieldsProfile)))
                    return _t('_sys_txt_error_account_creation');

                $oFormHelperProfile->setAutoApproval($oFormHelperProfile->isAutoApproval() ? true : (bool)getParam('bx_facebook_connect_approve'));
                if ($sErrorMsg = $oFormHelperProfile->onDataAddAfter ($iAccountId, $iContentId))
                    return $sErrorMsg;
                
                $oProfile = BxDolProfile::getInstanceByAccount($iAccountId);
                $iProfileId = $oProfile->id();
            } 
            else {
                $iProfileId = $iAccountProfileId;
            }

            // send email with password
            sendMailTemplate($this->_oConfig->sEmailTemplatePasswordGenerated, $iAccountId, $iProfileId, array('password' => $aFieldsAccount['password']), BX_EMAIL_SYSTEM);
        }

        // remember remote profile id for created member
        $this ->_oDb->saveRemoteId($iProfileId, $aProfileInfo['id']);

        // auto-friend members if they are already friends on remote site
        if ($isAutoFriends && method_exists($this, '_makeFriends'))
            $this->_makeFriends($iProfileId);

        return array('remote_profile_info' => $aProfileInfo, 'profile_id' => $iProfileId, 'existing_profile' => $oExistingAccount ? true : false);
    }

    protected function _processImage($aFieldsProfile, $iAccountProfileId, $oFormHelperProfile)
    {
        if (!isset($aFieldsProfile['picture']) || !$aFieldsProfile['picture'])
            return 0;

        if (!($oStorage = $oFormHelperProfile->getObjectStorage()))
            return 0;
        
        if (!($iFileId = $oStorage->storeFileFromUrl($aFieldsProfile['picture'], false, $iAccountProfileId)))
            return 0;

        return $iFileId;
    }

     /**
      * Get join page
      *
      * @param $aProfileFields array
      * @param $iRemoteProfileId remote profile id
      * @return void
      */
    function _getJoinPage($aProfileFields, $iRemoteProfileId)
    {
        bx_import('BxDolSession');
        $oSession = BxDolSession::getInstance();
        $oSession->setValue($this->_oConfig->sSessionUid, $iRemoteProfileId);

        bx_import("BxDolJoinProcessor");

        $GLOBALS['oSysTemplate']->addJsTranslation('_Errors in join form');
        $GLOBALS['oSysTemplate']->addJs(array('join.js', 'jquery.form.min.js'));

        $oJoin = new BxDolJoinProcessor();

        // process received fields
        foreach($aProfileFields as $sFieldName => $sValue)
            $oJoin -> aValues[0][$sFieldName] = $sValue;

        $this->_oTemplate->getPage(_t('_JOIN_H'), $oJoin->process());
        exit;
    }

    /**
     * get redirect URL
     * 
     * @param $iProfileId integer - profile ID
     * @return string redirect URL
     */
    function _getRedirectUrl($iProfileId, $isExistingProfile = false)
    {
        if ($isExistingProfile)
            return 'index' == $this->_oConfig->sRedirectPage ? BX_DOL_URL_ROOT : BX_DOL_URL_ROOT . 'member.php';

        $sRedirectUrl = $this->_oConfig->sDefaultRedirectUrl;

        switch($this->_oConfig->sRedirectPage) {
            case 'join':
            case 'pedit':
                $sRedirectUrl = BX_DOL_URL_ROOT . 'pedit.php?ID=' . (int)$iProfileId;
                break;

            case 'avatar':
                if(BxDolInstallerUtils::isModuleInstalled('avatar') && BxDolService::call('avatar', 'join', array($iProfileId, '_Join complete')))
                    exit;
                break;

            case 'index':
                $sRedirectUrl = BX_DOL_URL_ROOT;
                break;

            case 'member':
            default:
                $sRedirectUrl = BX_DOL_URL_ROOT . 'member.php';
                break;
            }

        return $sRedirectUrl;
    }
}

/** @} */