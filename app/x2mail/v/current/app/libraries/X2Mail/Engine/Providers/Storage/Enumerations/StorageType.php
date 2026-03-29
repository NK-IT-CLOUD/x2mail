<?php

namespace X2Mail\Engine\Providers\Storage\Enumerations;

enum StorageType: int
{
	case CONFIG = 1;
	case NOBODY = 2;
	case SIGN_ME = 3;
	case SESSION = 4;
	case PGP = 5;
	case ROOT = 6;
}
