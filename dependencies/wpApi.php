<?php
/*
	Purpose:  Wrap Wikipedia http API requests
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

// External dependencies
require_once('Peachy/Includes/Hooks.php');
require_once('Peachy/HTTP.php');

class WikiAPI
{
	private $instance = null; // HTTP instance
	private $server = null;
	private $url = null;
	private $queryUrl = null;
	const queryLimit = 500;
		
	public function __construct(
		$wpServerUrl
	)
	{		
		try
		{
			$this->instance = HTTP::getDefaultInstance();
		}
		catch (Exception $e)
		{
			die('Could not get HTTP instance: ' . $e->getMessage());
		}
		$this->server = $wpServerUrl;
		$this->url = $this->server . 'w/api.php?format=json';
		$this->queryUrl = $this->url . '&action=query';
	}
	
	public function __destruct()
	{
		unset($this->queryUrl);
		unset($this->url);
		unset($this->server);
		unset($this->instance);
	}
	
	public function server()
	{
		return $this->server;
	}
	
	/*
		Misc utility functions
	*/
	public static function linkUrlEncode($title)
	{
		return urlencode(str_replace(' ', '_', $title));
	}
	
	public static function continueStr($jsonResult = null)
	{
		if (!$jsonResult)
			return '&continue='; // Initital
		if (array_key_exists('batchcomplete', $jsonResult) || !array_key_exists('continue', $jsonResult))
			return false; // Finished
		$continue = '';
		foreach ($jsonResult['continue'] as $key => $value)
			$continue .= '&' . $key . '=' . $value;
		return $continue; // Intermediate
	}
	
	/*
		General requests methods
	*/
	public function query(
		$queryStr,
		$continueStr = null
	)
	{
		$continue = $continueStr or self::continueStr();
		$json = null;
		$apiUrl = $this->queryUrl . $continue . $queryStr;
		try
		{
			$json = json_decode($this->instance->get($apiUrl), true);
		}
		catch (Exception $e)
		{
			die('Caught an exception: ' . $e->getMessage());
		}
		return $json;
	}
}
