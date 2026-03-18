<?php

return [
	'routes' => [
		[
			'name' => 'page#index',
			'url' => '/',
			'verb' => 'GET'
		],
		[
			'name' => 'page#indexPost',
			'url' => '/',
			'verb' => 'POST'
		],
		[
			'name' => 'page#appGet',
			'url' => '/app/',
			'verb' => 'GET'
		],
		[
			'name' => 'page#appPost',
			'url' => '/app/',
			'verb' => 'POST'
		],
		[
			'name' => 'fetch#setAdmin',
			'url' => '/fetch/setAdmin',
			'verb' => 'POST'
		],
		[
			'name' => 'fetch#setAdmin',
			'url' => '/fetch/admin.php',
			'verb' => 'POST'
		],
		[
			'name' => 'fetch#setPersonal',
			'url' => '/fetch/personal.php',
			'verb' => 'POST'
		],
		[
			'name' => 'fetch#upgrade',
			'url' => '/fetch/upgrade',
			'verb' => 'POST'
		]
	]
];
