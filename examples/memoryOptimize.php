<?php
/******************************************************************************
 * Author: Petr Suchy (xsuchy09) <suchy@wamos.cz> <http://www.wamos.cz>
 * Subject: WAMOS <http://www.wamos.cz>
 * Project: rollingcurl
 * Copyright: (c) Petr Suchy (xsuchy09) <suchy@wamos.cz> <http://www.wamos.cz>
 *****************************************************************************/


require __DIR__ . '/../src/RollingCurl/RollingCurl.php';
require __DIR__ . '/../src/RollingCurl/Request.php';

$rollingCurl = new \RollingCurl\RollingCurl();
$rollingCurl
		->get('http://yahoo.com')
		->get('http://google.com')
		->get('http://hotmail.com')
		->get('http://msn.com')
		->get('http://reddit.com')
		->setCallback(function(\RollingCurl\Request $request, \RollingCurl\RollingCurl $rollingCurl) {
			if (preg_match("#<title>(.*)</title>#i", $request->getResponseText(), $out)) {
				$title = $out[1];
			} else {
				$title = '[No Title Tag Found]';
			}
			echo 'Fetch complete for (' . $request->getUrl() . ') ' . $title . PHP_EOL;
			
			// Clear list of completed requests and prune pending request queue to avoid memory growth
			$rollingCurl->clearCompleted();
			$rollingCurl->prunePendingRequestQueue();
		})
		->execute();
;
