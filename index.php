<?php
/*
	Purpose:  Checks and reports which articles that transcludes a template that are not linked from the template,
	          and which articles that are linked from the template but don't transclude the template.
	          Typical use: http://en.wikipedia.org/wiki/Template:Squad_maintenance
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

// Dependencies
require_once('./dependencies/database.php');
require_once('./dependencies/maintenanceNotice.php');

// External dependencies
require_once('./dependencies/Peachy/Includes/Hooks.php');
require_once('./dependencies/Peachy/HTTP.php');

function wpServer($language)
{
	return protocol . "://$language.wikipedia.org/";
}

function wpLinkUrlEncode($title)
{
	return urlencode(str_replace(' ', '_', $title));
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
		return '<a href="' . $url . '">' . $titleHtml . '</a> (<a href="' . $urlEdit . '">edit</a>)';
	return '<a class ="redlink" href="' . $url . '">' . $titleHtml . '</a> (<a href="' . $urlEdit . '">create</a>)';
}

function wpApiClient($wpServer)
{
	$wpApi = array();
	try
	{
		$wpApi['http'] = HTTP::getDefaultInstance();
	}
	catch (Exception $e)
	{
		die('Oops, sorry: ' . $e->getMessage());
	}
	$wpApi['url'] = $wpServer . 'w/api.php?format=json&continue=';
	return $wpApi;
}

// Get which articles that transcludes a template
function transclusionsOf($wpApi, $template)
{
	$apiUrl = $wpApi['url'] . '&action=query&prop=transcludedin&tinamespace=0&tilimit=500&titles=' . wpLinkUrlEncode($template);
	$json = array();
	try
	{
		$json = json_decode($wpApi['http']->get($apiUrl), true);
	}
	catch (Exception $e)
	{
		die('Oops, sorry: ' . $e->getMessage());
	}
	if ($json == false || count($json) == 0) return array();
	
	$tmp = current($json['query']['pages']);
	if (!array_key_exists('transcludedin', $tmp)) return array();
	return $tmp['transcludedin'];
}

// Get which articles a template links to
function linksFrom($wpApi, $template)
{
	$apiUrl = $wpApi['url'] . '&action=query&prop=links&plnamespace=0&pllimit=500&titles=' . wpLinkUrlEncode($template);
	$json = array();
	try
	{
		$json = json_decode($wpApi['http']->get($apiUrl), true);
	}
	catch (Exception $e)
	{
		die('Oops, sorry: ' . $e->getMessage());
	}
	if($json == false || count($json) == 0) return array();

	$tmp = current($json['query']['pages']);
	if (!array_key_exists('links', $tmp)) return array();
	return $tmp['links'];
}

// Get misc status for a wiki page, 0 if not existing
function pageStatus($db, $namespace, $title)
{
	$result = $db->query("SELECT page_id,page_is_redirect FROM page WHERE page_title='" . $db->real_escape_string(str_replace(' ', '_', $title)) . "' AND page_namespace='" . $db->real_escape_string(str_replace(' ', '_', $namespace)) . "';");
	if (!isset($result))
		return array();
	$row = $result->fetch_array();
	$result->close();
	if (!isset($row))
		return array();
	$status['pageid'] = $row['page_id'];
	if (isset($row['page_is_redirect']) && $row['page_is_redirect'] === '1')
	{
		$result = $db->query("SELECT rd_title FROM redirect WHERE rd_from='" . $db->real_escape_string($status['pageid']) . "';");
		if (isset($result))
		{
			$row = $result->fetch_array();
			$result->close();
			if (isset($row))
			{
				// Might also want namespace for redirect target
				$status['redirect_title'] = $row['rd_title'];
				$status['redirect_pageid'] = pageId($db, $namespace, $status['redirect_title']); // Recursive...
			}
		}
	}
	return $status;
}

// Get pageid for a wiki page, 0 if not existing
function pageId($db, $namespace, $title)
{
	$status = pageStatus($db, $namespace, $title);
	if (!isset($status['pageid']))
		return 0;
	return $status['pageid'];
}

// Do the articles the template link to exist?
function checkStatus($db, &$links)
{
	$redirects = array();
	foreach ($links as &$c)
	{
		$status = pageStatus($db, $c['ns'], $c['title']);
		if (!isset($status['pageid']))
			$c['pageid'] = 0;
		else
		{
			$c['pageid'] = $status['pageid'];
			if (isset($status['redirect_title']))
			{
				$c['redirect_title'] = str_replace('_', ' ', $status['redirect_title']);
				$c['redirect_pageid'] = isset($status['redirect_pageid']) ? $status['redirect_pageid'] : 0;
				array_push($redirects, $c);
			}
		}
	}
	return $redirects;
}

// Check if template exists
function templateExists($db, $template)
{
	$templateName = $template;
	$namespaceDelimiter = strpos($template, ':');
	if ($namespaceDelimiter > 0)
	{
		$templateName = substr($templateName, $namespaceDelimiter + 1);
		if (isset($templateName) && $templateName != '')
			return pageId($db, 10, $templateName) != 0;
	}
	return false;
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

if(!preg_match('/^[a-z-]{2,8}$/', $language)) die("Oops, sorry: I don't speak that language..."); // Safety precaution

define('redirectSymbolR', ' <span class="redirect">&rarr;</span> ');
define('redirectSymbolL', ' <span class="redirect">&larr;</span> ');

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<title>Template linking and transclusion check</title>
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
		<h1>Template linking and transclusion check</h1>
		<p>Checks and reports which articles that transcludes a template that are not linked from the template, and which articles that are linked from a template but don't transclude it.</p>
		<form action="<?php echo scriptLink; ?>" method="get">
			<table>
				<tr><td><label for="lang">Language:</label></td><td><input type="text" name="lang" id="lang" value="<?php echo $language; ?>" style="width:80px;" maxlength="8" required="required" />.wikipedia.org</td></tr>
				<tr><td><label for="name">Template name:</label></td><td><input type="text" name="name" id="name" value="<?php echo htmlspecialchars($template, ENT_QUOTES); ?>" style="width:200px;" required="required" /> (including namespace)</td></tr>
				<tr><td><label for="complete">Generate complete report:</label></td><td><input type="checkbox" name="complete" id="complete" value="1"<?php if ($complete) echo ' checked="checked"'; ?> /><input type="submit" value="Check!" /></td></tr>
			</table>
		</form>
<?php

if (isset($_GET['lang']) && $template != '')
{
	$db = null;
	try
	{
		$db = new Database($language . 'wiki-p');
	}
	catch (Exception $e)
	{
		die('Oops, sorry: ' . $e->getMessage());
	}
	
	$server = wpServer($language);
	if (!templateExists($db, $template))
		echo '<p>Template ' . wpLink($server, $template, false) . " does not exist.</p>\n";
	else // Seems like it exist...
	{
		$wpApi = wpApiClient($server) or die("Oops, sorry: Couldn't get the API client");
		$transclusions = transclusionsOf($wpApi, $template);
		$links = linksFrom($wpApi, $template);
		unset($wpApi);
		$redirects = checkStatus($db, $links); // Check which links that do not exist		
		
		echo '<p>Results for ' . wpLink($server, $template) . "</p>\n";		
		echo '<table style="width: 90%;">' . "\n";
		echo '<tr><th colspan="2" class="verbose">Mismatch between transclusions and links</th></tr>' . "\n";
		echo '<tr><th style="width: 50%;">Transclusion but no link</th><th>Link but no transclusion</th></tr>' . "\n";
		
		// Any articles that transcludes template but are not linked from template?
		$notLinked = arrayDiff($transclusions, $links);
		echo '<tr><td><p>Total: ' . count($notLinked);
		if (!empty($notLinked))
		{
			foreach ($notLinked as $c)
				echo "\n<br />" . wpLink($server, $c['title']);
		}
		echo "</p></td>\n";

		// Any articles that are linked from the template but do not transclude the template?
		$notTranscluding = arrayDiff($links, $transclusions);
		echo '<td><p>Total: ' . count($notTranscluding);
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
			echo '<tr><th>&nbsp;</th><th>Links to redirects</th></tr>' . "\n";
			echo '<tr><td>&nbsp;</td><td><p>Total: ' . count($redirects);
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
			echo '<tr><th colspan="2" class="verbose">Complete transclusion and link overview</th></tr>' . "\n";
			echo "<tr><th>Transclutions of template</th><th>Links from template</th></tr>\n";
			echo '<tr><td><p>Transclusion count: ' . count($transclusions);
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
			echo '<td><p>Link count: ' . count($links);
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
			echo '<p>&nbsp;</p><p><a href="' . scriptLink . "?lang=$language&amp;name=" . wpLinkUrlEncode($template) . '&amp;complete=1">Complete report...</a></p>' . "\n";
	}
	unset($db); // Close db
	$diffTime = time() - $oldTime;
	echo '<p class="stats">Generated: ' . date('D, d M Y H:i:s T') . '. Duration: ' . $diffTime . ' s.</p>';
}
?>
<!-- div id="w3c"><a href="http://validator.w3.org/check?uri=referer"><img src="http://www.w3.org/Icons/valid-xml11-blue.png" alt="Valid XHTML 1.1 Strict" width="88" height="31" /></a>
<a href="http://jigsaw.w3.org/css-validator/check/referer"><img src="http://www.w3.org/Icons/valid-css-blue.png" alt="Valid CSS" width="88" height="31" /></a></div -->
<p class="info"><a href="<?php echo docLink; ?>">Tool</a> provided by <a href="<?php echo protocol; ?>://wikitech.wikimedia.org/wiki/User:Chameleon">Chameleon</a> 2015. Powered by <a href="<?php echo protocol; ?>://tools.wmflabs.org/">Wikimedia Labs</a>.</p>
</body>
</html>
