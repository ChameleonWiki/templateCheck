<?php
/*
	Purpose:	Checks and reports which articles that transcludes a template that are not linked from the template,
				and which articles that are linked from the template but don't transclude the template.
				Typical use: http://en.wikipedia.org/wiki/Template:Squad_maintenance
	Written:	03. Jan. 2015

	Copyright (c) 2015, Chameleon
	All rights reserved.

	Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

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

// Load Zend classes
require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Rest_Client');

// Create a Zend REST client
function wpApiClient($language)
{
	$wpApi = NULL;
	try
	{
		// Initialize REST client
		$wpApi = new Zend_Rest_Client('http://' . $language . '.wikipedia.org/w/api.php');
	}
	catch (Exception $e)
	{
		die('Oops, sorry: ' . $e->getMessage());
	}
	return $wpApi;
}

// Get which articles that transcludes a template
function transclusionsOf($wpApi, $template)
{
	// https://no.wikipedia.org/w/api.php?action=query&prop=transcludedin&tinamespace=0&tilimit=500&titles=Mal:Rosenborg_Ballklub_spillerstall
	$transclusions = array();
	try
	{
		// Set query parameters
		$wpApi->action('query');
		$wpApi->prop('transcludedin');
		$wpApi->tinamespace(0); // Only from main name space (articles)
		$wpApi->tilimit(500);
		$wpApi->titles($template);
		$wpApi->format('xml');
		//$wpApi->continue('');
	
		// Perform request
		$transclusions = $wpApi->get();
	}
	catch (Exception $e)
	{
		die('Oops, sorry: ' . $e->getMessage());
	}
	return $transclusions;
}

// Get which articles a template links to
function linksFrom($wpApi, $template)
{
	// https://no.wikipedia.org/w/api.php?action=query&prop=links&plnamespace=0&pllimit=500&titles=Mal:Rosenborg_Ballklub_spillerstall
	$links = array();
	try
	{
		// Set query parameters
		$wpApi->action('query');
		$wpApi->prop('links');
		$wpApi->plnamespace(0); // Only from main name space (articles)
		$wpApi->pllimit(500);
		$wpApi->titles($template);
		$wpApi->format('xml');
		//$wpApi->continue('');
	
		// Perform request
		$links = $wpApi->get();
	}
	catch (Exception $e)
	{
		die('Oops, sorry: ' . $e->getMessage());
	}
	return $links;
}

$oldTime = time();
$language = (isset($_GET['lang']) && $_GET['lang'] != '') ? htmlspecialchars($_GET['lang']) : 'no';
$template = (isset($_GET['name']) && $_GET['name'] != '') ? str_replace('_', ' ', htmlspecialchars($_GET['name'], ENT_QUOTES)) : '';
if(!preg_match('/^[a-z-]{2,7}$/', $language)) die("Oops, sorry: I don't speak that language..."); // Safety precaution

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd"> 
<html>
	<head>
		<title>Template linking and transclusion check</title>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
		<meta http-equiv="Content-Style-Type" content="text/css">
		<link rel="stylesheet" href="./main.css" type="text/css">
		<meta name="robots" content="noindex,nofollow">
		<meta http-equiv="expires" content="0">
		<meta http-equiv="pragma" content="no-cache">
	</head>
	<body>
		<h1>Template linking and transclusion check</h1>
		<form action="index.php" method="GET">
			<table>
				<tr><td><label for="lang">Language:</label></td><td><input type="text" name="lang" id="lang" value="<?php echo $language; ?>" style="width:80px;" maxlength="7" required="required">.wikipedia.org</td></tr>
				<tr><td><label for="name">Template name:</label></td><td><input type="text" name="name" id="name" style="width:200px;" value="<?php echo $template; ?>" required="required"/> (including namespace)</td></tr>
				<tr><td></td><td><input type="submit" value="Check!" /></td></tr>
			</table>
		</form>
<?php
if (isset($_GET['lang']))
{
	$template = mb_strtoupper(mb_substr($template, 0, 1)).mb_substr($template, 1); // For Xeno
	$template = str_replace(' ', '_', $template);

	// test...
	$template = 'Mal:Rosenborg_Ballklub_spillerstall';
	$language = 'no';

	// Initialize REST client
	$wpApi = wpApiClient($language) or die("Oops, sorry: Couldn't initialize the Zend REST client");

	$transclusions = transclusionsOf($wpApi, $template);
	$links = linksFrom($wpApi, $template);

	// Any articles that transcludes template but are not linked from template?
	$notLinked = array();
	foreach ($transclusions->query->pages[0]->transcludedin as $c)
	{
		if (!array_search($c['title'], $links->query->pages[0]->links))
			array_push($notLinked, $c['title']);
	}
	echo('<h2>Articles that transcludes the template but are not linked from template</h2>' . "\n");
	echo('Total: ' . count($notLinked) . "\n");
	if (!empty($notLinked))
	{
		echo("<ol>\n");
		foreach ($notLinked as $c)
			echo('<li>' . $c . "</li>\n");
		echo("</ol>\n");
	}

	// Any articles that are linked from the template but do not transclude the template?
	$notTranscluding = array();
	foreach ($links->query->pages[0]->links as $c)
	{
		if (!array_search($c['title'], $transclusions->query->pages[0]->transcludedin))
			array_push($notTranscluding, $c['title']);
	}
	echo('<h2>Articles that are linked from the template but do not transclude the template</h2>' . "\n");
	echo('Total: ' . count($notTranscluding) . "\n");
	if (!empty($notTranscluding))
	{
		echo("<ol>\n");
		foreach ($notTranscluding as $c)
			echo('<li>' . $c . "</li>\n");
		echo("</ol>\n");
	}

	// Template transclusions
	echo('<h2>Articles that transcludes template</h2>' . "\n");	
	echo('Transclusion count: ' . count($transclusions->query->pages[0]->transcludedin) . "\n");
	if (!empty($transclusions->query->pages[0]->transcludedin))
	{
		echo("<ol>\n");
		foreach ($transclusions->query->pages[0]->transcludedin as $c)
			echo('<li>' . $c['title'] . "</li>\n");
		echo("</ol>\n");
	}

	// Links from template to articles
	echo('<h2>Articles that are linked from template</h2>' . "\n");	
	echo('Link count: ' . count($links->query->pages[0]->links) . "\n");
	if (!empty($links->query->pages[0]->links))
	{
		echo("<ol>\n");
		foreach ($links->query->pages[0]->links as $c)
			echo('<li>' . $c['title'] . "</li>\n");
		echo("</ol>\n");
	}

	$diffTime = time() - $oldTime;
	echo '<p class="stats">Generated: ' . date('D, d M Y H:i:s T') . '. Duration: ' . $diffTime . ' s.</p>';
}
?>
		<p class="info"><a href="http://tools.wmflabs.org/">Tool</a> provided by <a href="http://wikitech.wikimedia.org/wiki/User:Chameleon">Chameleon</a> 2015. Powered by <a href="http://tools.wmflabs.org/">Wikimedia Labs</a>.</p>
	</body>
</html>
