<?php
/*
	Purpose:  Wikipedia namespaces
	Written:  1. May 2015

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
require_once('wpApi.php');

/*
	Namespaces as defined in includes/Defines.php
*/
class WikiNS
{
	// Virtual namespaces; don't appear in the page database
	const NS_MEDIA = -2;
	const NS_SPECIAL = -1;

	// Real namespaces
	const NS_MAIN = 0;
	const NS_TALK = 1;
	const NS_USER = 2;
	const NS_USER_TALK = 3;
	const NS_PROJECT = 4;
	const NS_PROJECT_TALK = 5;
	const NS_FILE = 6;
	const NS_FILE_TALK = 7;
	const NS_MEDIAWIKI = 8;
	const NS_MEDIAWIKI_TALK = 9;
	const NS_TEMPLATE = 10;
	const NS_TEMPLATE_TALK = 11;
	const NS_HELP = 12;
	const NS_HELP_TALK = 13;
	const NS_CATEGORY = 14;
	const NS_CATEGORY_TALK = 15;
	
	private $namespaceMap = null; // Local names
	private $namespaceCanonicalMap = null; // English names
	private $namespaceAliasMap = null; // Local shortcuts. Several aliases for each nameSpaceId may exists
	
	public function __construct(
		$wpServerUrl,
		$getCanonical = false,
		$getAliases = false
	)
	{
		$wpApi = new WikiAPI($wpServerUrl);
		
		$this->namespaceMap = array();
		if ($getCanonical)
			$this->namespaceCanonicalMap = array();
		$continue = WikiAPI::continueStr();
		while ($continue)
		{
			$json = $wpApi->query($continue . '&meta=siteinfo&siprop=namespaces'); //&silimit=' . WikiAPI::queryLimit);
			if(!$json || count($json) == 0 || !array_key_exists('query', $json))
				break;
			$namespaces = current($json['query']);
			foreach ($namespaces as $namespaceInfo)
			{
				if (array_key_exists('id', $namespaceInfo) && is_numeric($namespaceInfo['id']))
				{
					$namespaceId = intval($namespaceInfo['id']);
					if (array_key_exists('*', $namespaceInfo))
						$this->namespaceMap[$namespaceId] = $namespaceInfo['*'];
					if ($getCanonical && array_key_exists('canonical', $namespaceInfo))
						$this->namespaceCanonicalMap[$namespaceId] = $namespaceInfo['canonical'];
				}
			}
			$continue = WikiAPI::continueStr($json);
		}
		
		if ($getAliases)
		{
			$this->namespaceAliasMap = array();
			$continue = WikiAPI::continueStr();
			while ($continue)
			{
				$json = $wpApi->query($continue . '&meta=siteinfo&siprop=namespacealiases'); //&silimit=' . WikiAPI::queryLimit);
				if(!$json || count($json) == 0 || !array_key_exists('query', $json))
					break;
				$namespaces = current($json['query']);
				foreach ($namespaces as $namespaceInfo)
				{
					if (array_key_exists('id', $namespaceInfo) && is_numeric($namespaceInfo['id']) && array_key_exists('*', $namespaceInfo) && $namespaceInfo['*'] != '')
						$this->namespaceAliasMap[$namespaceInfo['*']] = intval($namespaceInfo['id']);
				}
				$continue = WikiAPI::continueStr($json);
			}
		}
				
		unset($wpApi);
	}
	
	public function __destruct()
	{
		unset($this->namespaceMap);
		unset($this->namespaceCanonicalMap);
		unset($this->namespaceAliasMap);
	}
	
	public function getName(
		$namespaceId = self::NS_MAIN
	)
	{
		if (!is_numeric($namespaceId))
			return false;
		if (array_key_exists($namespaceId, $this->namespaceMap))
			return $this->namespaceMap[$namespaceId];
		return false;
	}
	
	public function getId(
		$namespaceName,
		$tryCanonical = true,
		$tryAliases = true
	)
	{
		// TODO: Fix case issues (this function uses case sensitive compare of namespaceName)
		if (!is_string($namespaceName))
			return false;
		if ($namespaceName == '')
			return self::NS_MAIN;
		
		// Local name?
		$namespaceId = array_search($namespaceName, $this->namespaceMap);
		if ($namespaceId !== false)
			return $namespaceId;
		
		// Try canonical name?
		if ($tryCanonical && isset($this->namespaceCanonicalMap))
		{
			$namespaceId = array_search($namespaceName, $this->namespaceCanonicalMap);
			if ($namespaceId !== false)
				return $namespaceId;
		}
		
		// Try aliases?
		if ($tryAliases && isset($this->namespaceAliasMap))
		{
			if (array_key_exists($namespaceName, $this->namespaceAliasMap))
				return $this->namespaceAliasMap[$namespaceName];
		}
		return false;
	}
	
	public function getNamespaceMap()
	{
		return $this->namespaceMap;
	}
	
	public function htmlSelectCtrl(
		$attributes, // e.g. 'name="myDrop"'
		$selected = self::NS_MAIN,
		$skipTalk = true,
		$skipVirtual = true
	)	
	{
		$htmlDrop = '<select ' . $attributes . '>';
		foreach ($this->namespaceMap as $namespaceId => $namespaceName)
		{
			if ($skipVirtual && $namespaceId < 0)
				continue; // Skip virtual namespaces
			if ($skipTalk && $namespaceId % 2 != 0)
				continue; // Skip talk pages
			$htmlDrop .= '<option value="' . $namespaceId . '"';
			if ($selected == $namespaceId)
				$htmlDrop .= ' selected';
			$htmlDrop .= '>';
			if ($namespaceName != '')
				$htmlDrop .= $namespaceName;
			else if ($namespaceId == self::NS_MAIN)
				$htmlDrop .= '(main)';
			else
				$htmlDrop .= '(' . $namespaceId . ')';
			$htmlDrop .= '</option>';
		}
		$htmlDrop .= '</select>';
		return $htmlDrop;
	}
}

function testWikipediaNamespaces($server)
{
	$wpNS = new WikiNS($server, true, true);
	//echo $wpNS->htmlSelectCtrl('name="myDrop"', WikiNS::NS_TEMPLATE) . "\n";
	//var_dump($wpNS);
	unset($wpNS);
}
