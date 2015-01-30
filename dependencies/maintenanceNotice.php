<?php
/*
	Purpose:	Display a maintenance notice
	Written:	28. Jan. 2015

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

/*
	Example maintenanceNotice.json:
{
	"from" : "2015-01-28 09:00:00",
	"to"   : "2015-01-29 18:00:00",
	"text" : "Due to unscheduled maintenance of the Wikimedia Labs infrastructure this tool might suffer temporary outages/instability until the maintenance has finished. Sorry for the inconvenience.<br />For details please check <a href=\"http://lists.wikimedia.org/pipermail/labs-l/2015-January/003279.html\">[Labs-l] Rolling reboots today</a> and <a href=\"https://lists.wikimedia.org/pipermail/labs-l/2015-January/003288.html\">[Labs-l] Rolling reboots today (finished)</a>."
}
*/
class MaintenanceNotice
{
	private $message = array();
	
	private function jsonDecode($jsonStr)
	{
		$json = json_decode($jsonStr, true);
		if ($json)
		{
			$this->message['from'] = $json['from'] && $json['from'] != '0' ? strtotime($json['from']) : null;
			$this->message['to'] = $json['to'] && $json['to'] != '0' ? strtotime($json['to']) : null;
			$this->message['text'] = $json['text'];
			return true;
		}
		return false;
	}
	
	public function __construct($file)
	{	
		$jsonStr = file_get_contents($file);
		if ($jsonStr)
			$this->jsonDecode($jsonStr);
	}
	
	public function active()
	{
		if (!$this->message['text'])
			return false; // No text: Nothing to display
		$now = time();
		if ($this->message['from'] && $this->message['from'] > $now)
			return false; // Not yet
		if ($this->message['to'] && $this->message['to'] < $now)
			return false; // Not anymore
		return true;
	}
	
	public function display()
	{
		// Display message
		echo '<div style="border: 2px solid orange; padding: .3em; margin-bottom: 2em;"><p><span style="font-weight: bold;">Maintenance notice:</span>';
		if ($this->message['from']) // Display issued time
		{
			echo ' <span style="font-size: 8pt;"> (' . date('D, d M Y H:i:s T', $this->message['from']);
			if ($this->message['to']) // Display expected end time?
				echo ' &ndash; ' . date('D, d M Y H:i:s T', $this->message['to']);
			echo ')</span>';
		}
		echo '<br />' . $this->message['text'] . '</p></div>' . "\n";
	}
	
	public static function displayMessage($file = null)
	{
		$notice = new MaintenanceNotice($file ? $file : './maintenanceNotice.json');
		if ($notice->active())
			$notice->display();
		unset($notice);
	}
}

function maintenanceNotice($file = null)
{
	MaintenanceNotice::displayMessage($file);
}
