<?php
/*
	Purpose:  Wrap Wikipedia http API page related requests.
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
require_once('wpNamespaces.php');

class WikiPageAPI extends WikiAPI
{
	// Get page id for a page
	public function pageId($title)
	{
		$json = $this->query(self::continueStr() . '&titles=' . self::linkUrlEncode($title));
		if ($json != false && count($json) > 0)
		{
			$tmp = current($json['query']['pages']);
			if (array_key_exists('pageid', $tmp))
				return $tmp['pageid'];
		}
		return 0;
	}
	
	// Get which articles that transcludes a page
	public function transclusionsOf(
		$title,
		$namespace = WikiNS::NS_MAIN
	)
	{
		$result = array();
		$continue = self::continueStr();
		while ($continue)
		{
			$json = $this->query($continue . '&prop=transcludedin&tinamespace=' . $namespace . '&titles=' . self::linkUrlEncode($title) . '&tilimit=' . self::queryLimit);
			if ($json == false || count($json) == 0)
				return $result;
			$tmp = current($json['query']['pages']);
			if (array_key_exists('transcludedin', $tmp))
				$result = array_merge($result, $tmp['transcludedin']);
			$continue = self::continueStr($json);
		}
		return $result;
	}

	// Get which articles a page links to
	public function linksFrom(
		$title,
		$namespace = WikiNS::NS_MAIN
	)
	{
		$result = array();
		$continue = self::continueStr();
		while ($continue)
		{
			$json = $this->query($continue . '&prop=links&plnamespace=' . $namespace . '&titles=' . self::linkUrlEncode($title) . '&pllimit=' . self::queryLimit);
			if($json == false || count($json) == 0)
				return $result;
			$tmp = current($json['query']['pages']);
			if (array_key_exists('links', $tmp))
				$result = array_merge($result, $tmp['links']);
			$continue = self::continueStr($json);
		}
		return $result;
	}
}
