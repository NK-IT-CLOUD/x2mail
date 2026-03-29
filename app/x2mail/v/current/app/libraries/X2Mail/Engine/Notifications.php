<?php

namespace X2Mail\Engine;

enum Notifications: int
{
/*
	RequestError = 1;
	RequestAborted = 2;
	RequestTimeout = 3;
*/

	case InvalidToken = 101;
	case AuthError = 102;

	// User
	case ConnectionError = 104;
	case DomainNotAllowed = 109;
	case AccountNotAllowed = 110;
	case CryptKeyError = 111;

	case ContactsSyncError = 140;

	case CantGetMessageList = 201;
	case CantGetMessage = 202;
	case CantDeleteMessage = 203;
	case CantMoveMessage = 204;
	case CantCopyMessage = 205;

	case CantSaveMessage = 301;
	case CantSendMessage = 302;
	case InvalidRecipients = 303;

	case CantSaveFilters = 351;
	case CantGetFilters = 352;
	case CantActivateFiltersScript = 353;
	case CantDeleteFiltersScript = 354;
//	case FiltersAreNotCorrect = 355;

	case CantCreateFolder = 400;
	case CantRenameFolder = 401;
	case CantDeleteFolder = 402;
	case CantSubscribeFolder = 403;
	case CantUnsubscribeFolder = 404;
	case CantDeleteNonEmptyFolder = 405;

//	case CantSaveSettings = 501;

	case DomainAlreadyExists = 601;

	case DemoSendMessageError = 750;
	case DemoAccountError = 751;

	case AccountAlreadyExists = 801;
	case AccountDoesNotExist = 802;
	case AccountSwitchFailed = 803;
	case AccountFilterError = 804;

	case MailServerError = 901;
	case ClientViewError = 902;
	case InvalidInputArgument = 903;
	case UnknownError = 999;

	// Admin
//	case CantInstallPackage = 701;
//	case CantDeletePackage = 702;
	case InvalidPluginPackage = 703;
	case UnsupportedPluginPackage = 704;
	case CantSavePluginSettings = 705;

	static public function GetNotificationsMessage(int $iCode, ?\Throwable $oPrevious = null) : string
	{
		if (self::ClientViewError->value === $iCode && $oPrevious) {
			return $oPrevious->getMessage();
		}

		$oCase = self::tryFrom($iCode);
		return ($oCase ? $oCase->name : 'UnknownNotification') . '['.$iCode.']';
	}
}
