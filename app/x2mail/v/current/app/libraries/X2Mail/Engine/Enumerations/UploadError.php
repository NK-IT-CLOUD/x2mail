<?php

namespace X2Mail\Engine\Enumerations;

enum UploadError: int
{
	case FILE_TYPE = 98;
	case UNKNOWN = 99;

	case CONFIG_SIZE = 1001;
	case ON_SAVING = 1002;
	case EMPTY_FILE = 1003;

	private const MESSAGES = [
		/*1*/\UPLOAD_ERR_INI_SIZE   => 'Filesize exceeds the upload_max_filesize directive in php.ini',
		/*2*/\UPLOAD_ERR_FORM_SIZE  => 'Filesize exceeds the MAX_FILE_SIZE directive that was specified in the html form',
		/*3*/\UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
		/*4*/\UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
		/*6*/\UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
		/*7*/\UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
		/*8*/\UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension',
		98   => 'Invalid file type',
		99   => 'Unknown error',
		1001 => 'Filesize exceeds the config setting',
		1002 => 'Error saving file',
		1003 => 'File is empty'
	];

	public static function getMessage(int $code): string
	{
		return self::MESSAGES[$code] ?? '';
	}

	public static function getUserMessage(int $iError, int &$iClientError): string
	{
		$iClientError = $iError;
		switch ($iError) {
			case \UPLOAD_ERR_OK:
			case \UPLOAD_ERR_PARTIAL:
			case \UPLOAD_ERR_NO_FILE:
			case self::FILE_TYPE->value:
			case self::EMPTY_FILE->value:
				break;

			case \UPLOAD_ERR_INI_SIZE:
			case \UPLOAD_ERR_FORM_SIZE:
			case self::CONFIG_SIZE->value:
				return 'File is too big';

			case \UPLOAD_ERR_NO_TMP_DIR:
			case \UPLOAD_ERR_CANT_WRITE:
			case \UPLOAD_ERR_EXTENSION:
			case self::ON_SAVING->value:
				$iClientError = self::ON_SAVING->value;
				break;

			default:
				$iClientError = self::UNKNOWN->value;
				break;
		}

		return self::getMessage($iClientError);
	}

}
