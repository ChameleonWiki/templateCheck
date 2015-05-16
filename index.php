<?php
/*
	Purpose:  Checks and reports which articles that transcludes a template that are not linked from the template,
	          and which articles that are linked from the template but don't transclude the template.
	          Doc: //tools-static.wmflabs.org/templatetransclusioncheck/
	          Typical usage: //en.wikipedia.org/wiki/Template:Squad_maintenance
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

$oldTime = $_SERVER['REQUEST_TIME'] ? $_SERVER['REQUEST_TIME'] : time();

// Dependencies
require_once('./dependencies/wpApiPage.php');
require_once('./dependencies/wpNamespaces.php');
require_once('./dependencies/maintenanceNotice.php');
require_once('./databaseQueries.php');

// Intuition
define('i18nDomain', 'templatetransclusioncheck');
require_once('/data/project/intuition/src/Intuition/ToolStart.php');
$I18N = new Intuition(array('domain' => i18nDomain));
$I18N->loadMessageFile(i18nDomain, 'en', 'languages/en.json');

/**
** Shortcut for line output
*/
function s_out($str, $level = 0, $end = "\n")
{
	echo str_repeat("\t", $level) . $str . $end;
}

/**
** Shortcut for conditional string
*/
function t_str($bo, $str)
{
	return $bo ? $str : '';
}

/**
** Class which generates the output
*/
class TemplateCheck
{
	private $language = null; // Language code
	private $template = null; // Template name, including namespace
	private $complete = null; // Generate complete report?
	private $validInput = false;
	private $protocol = null; // https/http, needed for the wpAPI
	
	const scriptLink  =  './index.php';
	const staticStash = '//tools-static.wmflabs.org/templatetransclusioncheck/';
	const cssLink     = '//tools-static.wmflabs.org/templatetransclusioncheck/main.css'; // self::staticStash . 'main.css';
	const docLink = self::staticStash;
	const htmlVer = ENT_XHTML; // ENT_XHTML or ENT_HTML5
	const redirectSymbolR = ' <span class="redirect">&rarr;</span> ';
	const redirectSymbolL = ' <span class="redirect">&larr;</span> ';
	
	public function __construct()
	{
		global $I18N;
		$this->language = (isset($_GET['lang']) && $_GET['lang'] != '') ? htmlspecialchars(trim($_GET['lang']), ENT_QUOTES | self::htmlVer) : 'no';
		$this->template = (isset($_GET['name']) && $_GET['name'] != '') ? trim(str_replace('_', ' ', $_GET['name'])) : '';
		$this->complete = (isset($_GET['complete']) && $_GET['complete'] === '1') ? true : false;
		if (!preg_match('/^[a-z-]{2,8}$/', $this->language))
			die($I18N->msg('error-language')); // Safety precaution ('Unknown language.')
		if (isset($_GET['lang']) && $this->template != '')
			$this->validInput = true;
		$this->protocol = isset($_SERVER['HTTPS']) ? 'https' : (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'http');
	}
	
	private function wpServer()
	{		
		return "{$this->protocol}://{$this->language}.wikipedia.org/";
	}

	private static function wpLink($wpServer, $title, $exists = true, $noredirect = false)
	{
		$titleHtml = htmlspecialchars($title, ENT_QUOTES | self::htmlVer);
		$titleUrl = WikiAPI::linkUrlEncode($title);
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
	private static function arrayDiff($arrayA, $arrayB)
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

	private function outputHeader()
	{
		if (self::htmlVer === ENT_HTML5)
		{
			s_out('<!DOCTYPE html>');
			s_out('<html>');
		}
		else // ENT_XHTML
		{
			s_out('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">');
			s_out('<html xmlns="http://www.w3.org/1999/xhtml">');
		}
		s_out('<head>');
		s_out('<title>' . _html('title') . '</title>', 1);
		s_out('<link rel="stylesheet" href="' . self::cssLink . '" type="text/css" />', 1);
		s_out('<meta name="robots" content="noindex,nofollow" />', 1);
		if (self::htmlVer === ENT_HTML5)
			s_out('<meta charset="UTF-8" />', 1);
		else
		{
			s_out('<meta http-equiv="content-type" content="text/html; charset=UTF-8" />', 1);
			s_out('<meta http-equiv="content-style-type" content="text/css" />', 1);
			s_out('<meta http-equiv="expires" content="0" />', 1);
			s_out('<meta http-equiv="pragma" content="no-cache" />', 1);
		}
		s_out('</head>');
		s_out('<body>');
		MaintenanceNotice::displayMessage();
		InformationNotice::displayMessage(true, __DIR__ . '/informationNotice.json');
		s_out('<p><a href="//tools.wmflabs.org/"><img src="//upload.wikimedia.org/wikipedia/commons/b/bf/Powered-by-tool-labs.png" alt="Powered by Wikimedia Tool Labs icon" width="105" height="40" id="logo" /></a></p>', 1);
		s_out('<h1>' . _html('title') . '</h1>', 1);
		s_out('<p>' . _html('description') . '</p>', 1);
	}

	private function outputForm()
	{		
		s_out('<form action="' . self::scriptLink . '" method="get">', 1);
		s_out('<table>', 2);
		s_out('<tr><td><label for="lang">' . _html('form-label-language') . '</label></td><td><input type="text" name="lang" id="lang" value="' . $this->language . '" style="width:80px;" maxlength="8"' . t_str(self::htmlVer === ENT_HTML5, ' required="required"') . ' />.wikipedia.org</td></tr>', 3);
		s_out('<tr><td><label for="name">' . _html('form-label-template') . '</label></td><td><input type="text" name="name" id="name" value="' . htmlspecialchars($this->template, ENT_QUOTES | self::htmlVer) . '" style="width:400px;"' . t_str(self::htmlVer === ENT_HTML5, ' required="required"') .' /> ' . _html('form-label-template2') . '</td></tr>', 3);
		s_out('<tr><td><label for="complete">' . _html('form-label-complete') . '</label></td><td><input type="checkbox" name="complete" id="complete" value="1"' . t_str($this->complete, ' checked="checked"') . ' /><input type="submit" value="' . _g('form-submit') . '" /></td></tr>', 3);
		s_out('</table>', 2);
		s_out('</form>', 1);
	}

	private function outputResultNonExisting($wpApi)
	{
		// Couldnt get info from db, check with api
		if ($wpApi->pageId($this->template) != 0) // Template is available using the wp api (but not the db). This might be a temporary db issue.
			s_out('<p>' . _html('feedback-template-noaccess', array('variables' => array(self::wpLink($wpApi->server(), $this->template, false), $db->host_info), 'raw-variables' => true)) . '</p>', 1); // 'Template $1 seems to exists, but it is not available from the database. This might be a temporary database issue. Database used: $2.'
		else // Not available either from db or api
			s_out('<p>' . _html('feedback-template-missing', array('variables' => array(self::wpLink($wpApi->server(), $this->template, false)), 'raw-variables' => true)) . '</p>', 1); // 'Template $1 does not exist.'
	}

	private function outputResultBrief($server, $transclusions, $links, $redirects)
	{
		s_out('<tr><th colspan="2" class="verbose">' . _html('feedback-header-mismatch') . '</th></tr>', 2); // 'Mismatch between transclusions and links'
		s_out('<tr><th style="width: 50%;">' . _html('feedback-header-no-link') . '</th><th>' . _html('feedback-header-no-transclusion') . '</th></tr>', 2); // 'Transclusion but no link', 'Link but no transclusion'
		
		// Any articles that transcludes template but are not linked from template?
		$notLinked = self::arrayDiff($transclusions, $links);
		s_out('<tr>', 2);
		s_out('<td><p>' . _html('feedback-total-count', array('variables' => array(count($notLinked)))), 3);
		if (!empty($notLinked))
		{
			foreach ($notLinked as $c)
				s_out('<br />' . self::wpLink($server, $c['title']), 4);
		}
		s_out('</p></td>', 3);

		// Any articles that are linked from the template but do not transclude the template?
		$notTranscluding = self::arrayDiff($links, $transclusions);
		s_out('<td><p>' . _html('feedback-total-count', array('variables' => array(count($notTranscluding)))), 3);
		if (!empty($notTranscluding))
		{
			foreach ($notTranscluding as $c)
			{
				$isRedirect = (isset($c['redirect_title']) && $c['redirect_title'] != '');
				s_out('<br />' . self::wpLink($server, $c['title'], $c['pageid'] != 0, $isRedirect), 4);
				if ($isRedirect)
					s_out(self::redirectSymbolR . self::wpLink($server, $c['redirect_title'], $c['redirect_pageid'] != 0), 5);
			}
		}
		s_out('</p></td>', 3);
		s_out('</tr>', 2);
		
		if (!empty($redirects))
		{
			// Display all links to redirects from template
			s_out('<tr><th>&nbsp;</th><th>' . _html('feedback-header-redirects') . '</th></tr>', 2); // 'Links to redirects'
			s_out('<tr>', 2);
			s_out('<td>&nbsp;</td>', 3);
			s_out('<td><p>' . _html('feedback-total-count', array('variables' => array(count($redirects)))), 3);
			foreach ($redirects as $c)
			{
				s_out('<br />' .
									 self::wpLink($server, $c['title'], $c['pageid'] != 0, true) . 
									 self::redirectSymbolR .
									 self::wpLink($server, $c['redirect_title'], $c['redirect_pageid'] != 0), 4);
			}
			s_out('</p></td>', 3);
			s_out('</tr>', 2);
		}
	}

	private function outputResultExtended($server, $transclusions, $links, $redirects)
	{
		// Template transclusions
		s_out('<tr><th colspan="2" class="verbose">' . _html('feedback-header-complete') . '</th></tr>', 2); // 'Complete transclusion and link overview'
		s_out('<tr><th>' . _html('feedback-header-transclusions') . '</th><th>' . _html('feedback-header-links') . '</th></tr>', 2); // 'Transclutions of template', 'Links from template'
		s_out('<tr>', 2);
		s_out('<td><p>' . _html('feedback-total-count', array('variables' => array(count($transclusions)))), 3);
		if (!empty($transclusions))
		{
			foreach ($transclusions as $c)
			{
				s_out('<br />' . self::wpLink($server, $c['title']), 4);
				foreach ($redirects as $r)
				{
					if ($r['redirect_pageid'] === $c['pageid'])
					{
						s_out(self::redirectSymbolL . self::wpLink($server, $r['title'], true, true), 5);
						break;
					}
				}
			}
		}
		s_out('</p></td>', 3);

		// Links from template to articles
		s_out('<td><p>' . _html('feedback-total-count', array('variables' => array(count($links)))), 3);
		if (!empty($links))
		{
			foreach ($links as $c)
			{
				$isRedirect = (isset($c['redirect_title']) && $c['redirect_title'] != '');
				s_out('<br />' . self::wpLink($server, $c['title'], $c['pageid'] != 0, $isRedirect), 4);
				if ($isRedirect)
					s_out(self::redirectSymbolR . self::wpLink($server, $c['redirect_title'], $c['redirect_pageid'] != 0), 5);
			}
		}
		s_out('</p></td>', 3);
		s_out('</tr>', 2);
	}

	private function outputResult($wpApi, $db)
	{
		if (!$db->templateExists($this->template))
			$this->outputResultNonExisting($wpApi);
		else // Seems like it exist...
		{
			$transclusions = $wpApi->transclusionsOf($this->template);
			$links = $wpApi->linksFrom($this->template);
			$redirects = $db->checkStatus($links); // Check which links that do not exist		

			s_out('<p>' . _html('feedback-result', array('variables' => array(self::wpLink($wpApi->server(), $this->template)), 'raw-variables' => true)) . '</p>', 1); // 'Results for $1'
			s_out('<table style="width: 90%;">', 1);
			$this->outputResultBrief($wpApi->server(), $transclusions, $links, $redirects);
			if ($this->complete)
				$this->outputResultExtended($wpApi->server(), $transclusions, $links, $redirects);
			s_out('</table>', 1);
			if (!$this->complete)
				s_out('<p>&nbsp;</p><p><a href="' . self::scriptLink . "?lang={$this->language}&amp;name=" . WikiAPI::linkUrlEncode($this->template) . '&amp;complete=1">' . _html('link-complete') . '</a></p>', 1);
		}
	}

	private function outputStats()
	{
		global $I18N, $oldTime;
		$diffTime = time() - $oldTime;
		s_out('<p class="stats">' . _html('footer-stats', array('variables' => array($I18N->dateFormatted('%a, %d %b %Y %T %Z'), $diffTime))) . '</p>', 1); // '<p class="stats">Generated: ' . date('D, d M Y H:i:s T') . '. Duration: ' . $diffTime . ' s.</p>'
	}

	/**
	** Intuition::getFooterLine does not generate valid html, override until it does.
	** Eqv to $I18N->getFooterLine(i18nDomain)
	*/
	private static function getFooterLine()
	{
		global $I18N;
		
		// Promo message
		$promoMsgOpts = array(
			'domain' => 'tsintuition',
			'escape' => 'html',
			'raw-variables' => true,
			'variables' => array(
				'<a href="//translatewiki.net/">translatewiki.net</a>',
				'<a href="' . $I18N->dashboardHome . '">Intuition</a>'
			),
		);
		$powered = $I18N->msg( 'bl-promo', $promoMsgOpts );

		// Help translation
		$twLinkText = $I18N->msg( 'help-translate-tool', 'tsintuition' );
		
		// translatewiki.net/w/i.php?language=nl&title=Special:Translate&group=tsint-0-all
		$twParams = array(
			'title' => 'Special:Translate',
			'language' => $I18N->getLang(),
			'group' => 'tsint-' . i18nDomain,
		);
		$twParams = http_build_query( $twParams );
		$helpTranslateLink = '<small>(' . IntuitionUtil::tag( $twLinkText, 'a', array(
			'href' => "//translatewiki.net/w/i.php?$twParams",
			'title' => $I18N->msg( 'help-translate-tooltip', 'tsintuition' )
		) ) . ')</small>';

		// Build output
		return "<div class=\"int-promobox\"><p>$powered {$I18N->dashboardBacklink()} $helpTranslateLink</p></div>";
	}
	
	private function outputFooter()
	{
		global $I18N;
		if (self::htmlVer === ENT_HTML5)
		{
			s_out('<!-- div id="w3c"><a href="//validator.w3.org/check?uri=referer"><img src="' . self::staticStash . 'HTML5_Logo.svg" alt="Valid HTML5" width="88" height="31" /></a></div -->', 1);
			//s_out('<a href="//jigsaw.w3.org/css-validator/check/referer"><img src="' . self::staticStash . 'valid-css-blue.png" alt="Valid CSS" width="88" height="31" /></a></div>', 1);
		}
		else
		{
			s_out('<!-- div id="w3c"><a href="//validator.w3.org/check?uri=referer"><img src="' . self::staticStash . 'valid-xhtml11-blue.png" alt="Valid XHTML 1.1" width="88" height="31" /></a>', 1);
			s_out('<a href="//jigsaw.w3.org/css-validator/check/referer"><img src="' . self::staticStash . 'valid-css-blue.png" alt="Valid CSS" width="88" height="31" /></a></div -->', 1);
		}
		s_out('<p class="info"><a href="' . self::docLink . '">Tool</a> is provided by <a href="//wikitech.wikimedia.org/wiki/User:Chameleon">Chameleon</a> 2015. Powered by <a href="//tools.wmflabs.org/">Wikimedia Labs</a>.</p>', 1);
		s_out(self::getFooterLine(), 1); //s_out($I18N->getFooterLine(i18nDomain), 1);
		s_out('</body>');
		s_out('</html>');
	}

	public function display()
	{
		$this->outputHeader();
		$this->outputForm();
		if ($this->validInput)
		{
			$wpApi = new WikiPageAPI($this->wpServer()) or die("Couldn't get the API client.");
			$db = null;
			try
			{
				$db = new DatabaseSpecialized($this->language . 'wiki-p');
			}
			catch (Exception $e)
			{
				die('Caught an exception: ' . $e->getMessage());
			}
			
			$this->outputResult($wpApi, $db);
			
			unset($db); // Close db
			unset($wpApi);
			
			$this->outputStats();
		}
		$this->outputFooter();
	}
}

/**
** Generate the page
*/
$templateCheck = new TemplateCheck();
$templateCheck->display();
unset($templateCheck);
