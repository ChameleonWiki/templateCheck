<?php
/*
	Purpose:  Database with specialized query methods for the tool Template Linking and Transclusion Check
	Written:  02. May 2015

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

// Dependencies
require_once('./dependencies/database.php');
require_once('./dependencies/wpNamespaces.php');

class DatabaseSpecialized extends Database
{
	// Get misc status for a wiki page, 0 if not existing
	public function pageStatus($title, $namespace = WikiNS::NS_MAIN)
	{
		static $recursionLevel = 0;
		if (!is_numeric($namespace))
			return array();
		$result = $this->query("SELECT page_id,page_is_redirect FROM page WHERE page_title='" . $this->wiki_escape_string($title) . "' AND page_namespace=" . $namespace . ";");
		if (!isset($result))
			return array();
		$row = $result->fetch_array();
		$result->close();
		if (!isset($row))
			return array();
		$status['pageid'] = $row['page_id'];
		if (isset($row['page_is_redirect']) && $row['page_is_redirect'] === '1' && $recursionLevel === 0 && is_numeric($status['pageid']))
		{
			$result = $this->query("SELECT rd_title FROM redirect WHERE rd_from=" . $status['pageid'] . ";");
			if (isset($result))
			{
				$row = $result->fetch_array();
				$result->close();
				if (isset($row))
				{
					// Might also want namespace for redirect target
					$status['redirect_title'] = $row['rd_title'];
					$recursionLevel = 1;
					$status['redirect_pageid'] = $this->pageId($status['redirect_title'], $namespace); // Recursive...
					$recursionLevel = 0;
				}
			}
		}
		return $status;
	}

	// Get pageid for a wiki page, 0 if not existing
	private function pageId($title, $namespace = WikiNS::NS_MAIN)
	{
		$status = $this->pageStatus($title, $namespace);
		if (!isset($status['pageid']))
			return 0;
		return $status['pageid'];
	}

	// Do the articles the template link to exist?
	public function checkStatus(&$links)
	{
		$redirects = array();
		foreach ($links as &$c)
		{
			$status = $this->pageStatus($c['title'], $c['ns']);
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
	public function templateExists($template)
	{
		$templateName = $template;
		$namespaceDelimiter = strpos($template, ':');
		if ($namespaceDelimiter > 0)
		{
			$templateName = substr($templateName, $namespaceDelimiter + 1);
			if (isset($templateName) && $templateName != '')
				return $this->pageId($templateName, WikiNS::NS_TEMPLATE) != 0;
		}
		return false;
	}
}
