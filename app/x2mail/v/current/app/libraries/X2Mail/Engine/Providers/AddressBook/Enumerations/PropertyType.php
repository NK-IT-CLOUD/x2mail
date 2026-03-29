<?php

namespace X2Mail\Engine\Providers\AddressBook\Enumerations;

enum PropertyType: int
{
	case UNKNOWN = 0;

	case UID = 9;
	case FULLNAME = 10;

	case FIRST_NAME = 15;
	case LAST_NAME = 16;
	case MIDDLE_NAME = 17;
	case NICK_NAME = 18;

	case NAME_PREFIX = 20;
	case NAME_SUFFIX = 21;

	case EMAIL = 30;
	case PHONE = 31;
//	case MOBILE = 32;
//	case FAX = 33;
	case WEB_PAGE = 32;

	case BIRTHDAY = 40;

	case FACEBOOK = 90;
	case SKYPE = 91;
	case GITHUB = 92;

	case NOTE = 110;

	case CUSTOM = 250;

	case JCARD = 251;
}
