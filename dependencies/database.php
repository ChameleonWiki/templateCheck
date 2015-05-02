<?php
/*
	Purpose:  Wrap database connection
	Written:  20. Jan. 2015

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

class Database extends mysqli
{
	private $hostName = null;
	private $databaseName = null;
		
	public function __construct(
		$database,
		$charset = 'utf8' // utf8, utf8mb4, latin1, binary etc
	)
	{		
		// Connect using user credentials (local-toolname => /data/project/toolname/replica.my.cnf)
		$mycnf = parse_ini_file('/data/project/' . substr(get_current_user(), 6) . '/replica.my.cnf');
		$this->databaseName = str_replace('-', '_', $database); // needed for be-x-old.wikipedia.org (db host name be_x_old.labsdb)
		$this->hostName = (preg_match('/[-_]p$/', $this->databaseName)) ? substr($this->databaseName, 0, -2) : $this->databaseName;
		
		// Connect to server
		parent::__construct($this->hostName . '.labsdb', $mycnf['user'], $mycnf['password']); // mysqli CTor
		unset($mycnf);
		if($this->connect_error) // One possible error is wrong db host name. Check /etc/hosts for match
			throw new Exception('Database server login failed. This is probably a temporary problem with the server and will be fixed soon. The server returned error code ' . $this->connect_errno . '.');

		// Select database
		if(($this->select_db($this->databaseName)) === false)
			throw new Exception('Database selection failed. This is probably a temporary problem with the server and will be fixed soon.');
			
		if ($charset && $charset != '')
		{
			if (!$this->set_charset($charset))
				throw new Exception('Error loading character set "' . $charset . '": ' . $this->error);
		}
	}
	
	public function __destruct()
	{
		unset($hostName);
		unset($databaseName);
		if ($this->thread_id)
			$this->kill($this->thread_id); // Kill connection
		$this->close(); // Close connection
	}
	
	public function hostName()
	{
		return $this->hostName;
	}
	
	public function databaseName()
	{
		return $this->databaseName;
	}
	
	public function wiki_escape_string( // Wiki variant (for page names etc) of real_escape_string
		$escapeStr
	)
	{
		$esc = str_replace(' ', '_', $escapeStr);
		return $this->real_escape_string($esc);
	}
}
