<?php
/*
	Purpose:  Checks and reports which articles that transcludes a template that are not linked from the template,
	          and which articles that are linked from the template but don't transclude the template.
	          Doc: http://tools-static.wmflabs.org/templatetransclusioncheck/
	          Typical usage: http://en.wikipedia.org/wiki/Template:Squad_maintenance
	Written:  03. Jan. 2015

	Copyright (c) 2015, Chameleon
	All rights reserved.

	Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met

	1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
	2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and
	   the following disclaimer in the documentation and/or other materials provided with the distribution.
	3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote
	   products derived from this software without specific prior written permission.

	THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
	IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS;
	OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
	OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('protocol', isset($_SERVER['HTTPS']) ? 'https' : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http'));
define('scriptLink', './index.php');
define('cssLink', protocol . '://tools-static.wmflabs.org/templatetransclusioncheck/main.css');
define('docLink', protocol . '://tools-static.wmflabs.org/templatetransclusioncheck/');
define('i18nDomain', 'templatetransclusioncheck');

// Dependencies
require_once('./dependencies/wpApiPage.php');
require_once('./dependencies/wpNamespaces.php');
require_once('./dependencies/maintenanceNotice.php');
require_once('./databaseQueries.php');

// External dependencies
require_once('/data/project/intuition/src/Intuition/ToolStart.php');

$I18N = new Intuition(array('domain' => i18nDomain));
$I18N->loadMessageFile(i18nDomain, 'en', 'en.json');

function wpServer($language)
{
	return protocol . "://$language.wikipedia.org/";
}

function wpLinkUrlEncode($title)
{
	return WikiAPI::linkUrlEncode($title);
}

function wpLink($wpServer, $title, $exists = true, $noredirect = false)
{
	$titleHtml = htmlspecialchars($title, ENT_QUOTES);
	$titleUrl = wpLinkUrlEncode($title);
	$urlEdit = $wpServer . 'w/index.php?title=' . $titleUrl . '&amp;action=edit';
	$url = '';
	if ($noredirect) // Do not follow redirects (display redirecting page)
		$url = $wpServer . 'w/index.php?title=' . $titleUrl . '&amp;redirect=no';
	else // Display page normally
		$url = $wpServer . 'wiki/' . $titleUrl;
	
	if ($exists) // Existing page?
		return '<a href="' . $url . '">' . $titleHtml . '</a> ' . _g('parentheses', array('variables' => array('<a href="' . $urlEdit . '">' . _html('link-edit') . '</a>')));
	return '<a class ="redlink" href="' . $url . '">' . $titleHtml . '</a> ' . _g('parentheses', array('variables' => array('<a href="' . $urlEdit . '">' . _html('link-create') . '</a>')));
}

// Returns a list of titles that exists in arrayA but not in arrayB
function arrayDiff($arrayA, $arrayB)
{
	// TODO: This function (or the 2 calls to it) could/should be optimized...
	$not = array();
	if (empty($arrayB))
	{
		if (!empty($arrayA))
			$not = $arrayA; // None of the entries in arrayA exist in arrayB...
	}
	elseif (!empty($arrayA))
	{
		foreach ($arrayA as $a)
		{
			$found = 0;
			foreach ($arrayB as $b)
			{
				if ($a['title'] === $b['title'])
				{
					$found = 1; // Direct
					break;
				}
				if (isset($b['redirect_pageid']) && $b['redirect_pageid'] != 0 && $a['title'] === $b['redirect_title'])
				{
					$found = 2; // Indirect
					break;
				}
				if (isset($a['redirect_pageid']) && $a['redirect_pageid'] != 0 && $b['title'] === $a['redirect_title'])
				{
					$found = 2; // Indirect
					break;
				}
			}
			if ($found == 0)
				array_push($not, $a);
		}
	}
	return $not;
}

$oldTime = $_SERVER['REQUEST_TIME'] ? $_SERVER['REQUEST_TIME'] : time();
$language = (isset($_GET['lang']) && $_GET['lang'] != '') ? htmlspecialchars($_GET['lang']) : 'no';
$template = (isset($_GET['name']) && $_GET['name'] != '') ? str_replace('_', ' ', $_GET['name']) : '';
$complete = (isset($_GET['complete']) && $_GET['complete'] === '1') ? true : false;

if(!preg_match('/^[a-z-]{2,8}$/', $language)) die($I18N->msg('error-language')); // Safety precaution ('Unknown language.')

define('redirectSymbolR', ' <span class="redirect">&rarr;</span> ');
define('redirectSymbolL', ' <span class="redirect">&larr;</span> ');

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<title><?php echo _html('title'); ?></title>
		<link rel="stylesheet" href="<?php echo cssLink; ?>" type="text/css" />
		<meta name="robots" content="noindex,nofollow" />
		<meta http-equiv="content-type" content="text/html; charset=UTF-8" />
		<meta http-equiv="content-style-type" content="text/css" />
		<meta http-equiv="expires" content="0" />
		<meta http-equiv="pragma" content="no-cache" />
	</head>
	<body>
		<?php MaintenanceNotice::displayMessage(); ?>
		<p><a href="<?php echo protocol; ?>://tools.wmflabs.org/"><img src="<?php echo protocol; ?>://upload.wikimedia.org/wikipedia/commons/b/bf/Powered-by-tool-labs.png" alt="Powered by Wikimedia Tool Labs icon" width="105" height="40" id="logo" /></a></p>
		<h1><?php echo _html('title'); ?></h1>
		<p><?php echo _html('description'); ?></p>
		<form action="<?php echo scriptLink; ?>" method="get">
			<table>
				<tr><td><label for="lang"><?php echo _html('form-label-language'); ?></label></td><td><input type="text" name="lang" id="lang" value="<?php echo $language; ?>" style="width:80px;" maxlength="8" required="required" />.wikipedia.org</td></tr>
				<tr><td><label for="name"><?php echo _html('form-label-template'); ?></label></td><td><input type="text" name="name" id="name" value="<?php echo htmlspecialchars($template, ENT_QUOTES); ?>" style="width:400px;" required="required" /> <?php echo _html('form-label-template2'); ?></td></tr>
				<tr><td><label for="complete"><?php echo _html('form-label-complete'); ?></label></td><td><input type="checkbox" name="complete" id="complete" value="1"<?php if ($complete) echo ' checked="checked"'; ?> /><input type="submit" value="<?php echo _g('form-submit'); ?>" /></td></tr>
			</table>
		</form>
<?php

if (isset($_GET['lang']) && $template != '')
{
	$server = wpServer($language);
	$wpApi = new WikiPageAPI($server) or die("Couldn't get the API client.");
	$db = null;
	try
	{
		$db = new DatabaseSpecialized($language . 'wiki-p');
	}
	catch (Exception $e)
	{
		die('Caught an exception: ' . $e->getMessage());
	}
	
	if (!$db->templateExists($template))
	{
		// Couldnt get info from db, check with api
		if ($wpApi->pageId($template) != 0) // Template is available using the wp api (but not the db). This might be a temporary db issue.
			echo '<p>' . _html('feedback-template-noaccess', array('variables' => array(wpLink($server, $template, false), $db->host_info), 'raw-variables' => true)) . "</p>\n"; // 'Template $1 seems to exists, but it is not available from the database. This might be a temporary database issue. Database used: $2.'
		else // Not available either from db or api
			echo '<p>' . _html('feedback-template-missing', array('variables' => array(wpLink($server, $template, false)), 'raw-variables' => true)) . "</p>\n"; // 'Template $1 does not exist.'
	}
	else // Seems like it exist...
	{
		$transclusions = $wpApi->transclusionsOf($template);
		$links = $wpApi->linksFrom($template);
		$redirects = $db->checkStatus($links); // Check which links that do not exist		
		
		echo '<p>' . _html('feedback-result', array('variables' => array(wpLink($server, $template)), 'raw-variables' => true)) . "</p>\n"; // 'Results for $1'
		echo '<table style="width: 90%;">' . "\n";
		echo '<tr><th colspan="2" class="verbose">' . _html('feedback-header-mismatch') . "</th></tr>\n"; // 'Mismatch between transclusions and links'
		echo '<tr><th style="width: 50%;">' . _html('feedback-header-no-link') . '</th><th>' . _html('feedback-header-no-transclusion') . "</th></tr>\n"; // 'Transclusion but no link', 'Link but no transclusion'
		
		// Any articles that transcludes template but are not linked from template?
		$notLinked = arrayDiff($transclusions, $links);
		echo '<tr><td><p>' . _html('feedback-total-count', array('variables' => array(count($notLinked))));
		if (!empty($notLinked))
		{
			foreach ($notLinked as $c)
				echo "\n<br />" . wpLink($server, $c['title']);
		}
		echo "</p></td>\n";

		// Any articles that are linked from the template but do not transclude the template?
		$notTranscluding = arrayDiff($links, $transclusions);
		echo '<td><p>' . _html('feedback-total-count', array('variables' => array(count($notTranscluding))));
		if (!empty($notTranscluding))
		{
			foreach ($notTranscluding as $c)
			{
				$isRedirect = isset($c['redirect_title']) && $c['redirect_title'] != '';
				echo "\n<br />" . wpLink($server, $c['title'], $c['pageid'] != 0, $isRedirect);
				if ($isRedirect)
					echo redirectSymbolR . wpLink($server, $c['redirect_title'], $c['redirect_pageid'] != 0);
			}
		}
		echo "</p></td></tr>\n";
		
		if (!empty($redirects))
		{
			// Display all links to redirects from template
			echo '<tr><th>&nbsp;</th><th>' . _html('feedback-header-redirects') . "</th></tr>\n"; // 'Links to redirects'
			echo '<tr><td>&nbsp;</td><td><p>' . _html('feedback-total-count', array('variables' => array(count($redirects))));
			foreach ($redirects as $c)
			{
				echo "\n<br />" . wpLink($server, $c['title'], $c['pageid'] != 0, true);
				echo redirectSymbolR . wpLink($server, $c['redirect_title'], $c['redirect_pageid'] != 0);
			}
			echo "</p></td></tr>\n";
		}

		if ($complete) // Display 
		{
			// Template transclusions
			echo '<tr><th colspan="2" class="verbose">' . _html('feedback-header-complete') . "</th></tr>\n"; // 'Complete transclusion and link overview'
			echo '<tr><th>' . _html('feedback-header-transclusions') . '</th><th>' . _html('feedback-header-links') . "</th></tr>\n"; // 'Transclutions of template', 'Links from template'
			echo '<tr><td><p>' . _html('feedback-total-count', array('variables' => array(count($transclusions))));
			if (!empty($transclusions))
			{
				foreach ($transclusions as $c)
				{
					echo "\n<br />" . wpLink($server, $c['title']);
					foreach ($redirects as $r)
					{
						if ($r['redirect_pageid'] === $c['pageid'])
						{
							echo redirectSymbolL . wpLink($server, $r['title'], true, true);
							break;
						}
					}
				}
			}
			echo "</p></td>\n";

			// Links from template to articles
			echo '<td><p>' . _html('feedback-total-count', array('variables' => array(count($links))));
			if (!empty($links))
			{
				foreach ($links as $c)
				{
					$isRedirect = isset($c['redirect_title']) && $c['redirect_title'] != '';
					echo "\n<br />" . wpLink($server, $c['title'], $c['pageid'] != 0, $isRedirect);
					if ($isRedirect)
						echo redirectSymbolR . wpLink($server, $c['redirect_title'], $c['redirect_pageid'] != 0);
				}
			}
			echo "</p></td></tr>\n";
		}
		echo "</table>\n";
	
		if (!$complete)
			echo '<p>&nbsp;</p><p><a href="' . scriptLink . "?lang=$language&amp;name=" . wpLinkUrlEncode($template) . '&amp;complete=1">' . _html('link-complete') ."</a></p>\n";
	}
	unset($db); // Close db
	unset($wpApi);
	$diffTime = time() - $oldTime;
	//echo '<p class="stats">Generated: ' . date('D, d M Y H:i:s T') . '. Duration: ' . $diffTime . ' s.</p>';
	echo '<p class="stats">' . _html('footer-stats', array('variables' => array($I18N->dateFormatted('%a, %d %b %Y %T %Z'), $diffTime))) . '</p>';
}
?>
<!-- div id="w3c"><a href="http://validator.w3.org/check?uri=referer"><img src="http://www.w3.org/Icons/valid-xml11-blue.png" alt="Valid XHTML 1.1 Strict" width="88" height="31" /></a>
<a href="http://jigsaw.w3.org/css-validator/check/referer"><img src="http://www.w3.org/Icons/valid-css-blue.png" alt="Valid CSS" width="88" height="31" /></a></div -->
<p class="info"><a href="<?php echo docLink; ?>">Tool</a> is provided by <a href="<?php echo protocol; ?>://wikitech.wikimedia.org/wiki/User:Chameleon">Chameleon</a> 2015. Powered by <a href="<?php echo protocol; ?>://tools.wmflabs.org/">Wikimedia Labs</a>.</p>
<?php /*echo $I18N->getFooterLine(i18nDomain);*/ ?>
</body>
</html>
