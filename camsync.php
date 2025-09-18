#!/usr/bin/php-cli
<?php

//set_time_limit(900-5);
//ini_set('default_socket_timeout', 10);

//var_dump($argv);
$options = getopt('d:h:l:s:', ['yihack::', 'reolink::', 'throttle::']);
//var_dump($options);
if(empty($options['d']) || empty($options['h'])) // || !is_dir($options['d'])
{
	die('bad args');
}

$basedir = rtrim($options['d'], "\\/");
$now = time();
$since = strtotime(sprintf("-%d hours", (int)$options['h']), time());
$throttle = !empty($options['throttle']) ? max(1, (int)$options['throttle']) * 1024 : 0; // kB/s

function camlog($s, $timestamp = true, $newline = true)
{
	if($timestamp) echo '['.date('c').'] ';
	echo $s;
	if($newline) echo PHP_EOL;
}

function exec_timeout($cmd, $timeout)
{
	if(is_array($cmd) && version_compare(PHP_VERSION, '7.4.0', '<'))
		$cmd = implode(' ', $cmd);

	$process = proc_open($cmd, [], $pipes);

	if(!is_resource($process)) return -1;

	$exitcode = false;

	$stop = microtime(true) + $timeout;

	do
	{
		$status = proc_get_status($process);

		if(!$status['running'])
		{
			$exitcode = (int)$status['exitcode'];
			break;
		}

		sleep(1);
	}
	while($timeout <= 0 || microtime(true) < $stop);

	if($exitcode === false) proc_terminate($process, 9);

	proc_close($process);

	return $exitcode;
}

function purgevideos($basedir, $dir = '.')
{
	global $since;
//echo $basedir.'/'.$dir.PHP_EOL;
	$files = scandir($basedir.'/'.$dir);
	$filecount = count($files);

	foreach($files as $fn)
	{
		if($fn == '.' || $fn == '..') {$filecount--; continue;}

		//$path = str_replace('/./', '/', $basedir.'/'.$dir.'/'.$fn);
		$path = $basedir.'/'.($dir != '.' ? $dir.'/' : '').$fn;

		$time = null;

		if(is_dir($path))
		{
			//$subdir = $dir.'/'.$fn;
			$subdir = ($dir != '.' ? $dir.'/' : '').$fn;
//echo 'D '.$subdir.PHP_EOL;
			if(preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})/', $subdir, $m))
			{
				$time = mktime($m[4], 0, 0, $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/([0-9]{4})Y([0-9]{2})M([0-9]{2})D([0-9]{2})H/', $subdir, $m))
			{
				$time = gmmktime($m[4], 0, 0, $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/([0-9]{4})[\\/\\\\]([0-9]{2})[\\/\\\\]([0-9]{2})/', $subdir, $m))
			{
				$time = mktime(0, 0, 0, $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $subdir, $m))
			{
				$time = mktime(0, 0, 0, $m[2], $m[3], $m[1]);
			}

			if(empty($time) || $time < $since)
			{
				if(purgevideos($basedir, $subdir) == 0)
				{
					if(!empty($time) && !is_link($basedir.'/'.$subdir))
					{
						camlog('-D '.$subdir);
						rmdir($basedir.'/'.$subdir);
					}
				}
			}
		}
		else
		{
			if(!preg_match('/\\.(mp4|avi|jpg|jpeg)$/i', $fn)) continue;
//echo 'F '.$fn.PHP_EOL;
			if(preg_match('/_([0-9]{10})\\./', $fn, $m))
			{
				$time = $m[1];
			}
			else if(preg_match('/([0-9]{4})Y([0-9]{2})M([0-9]{2})D([0-9]{2})H.+?([0-9]{2})M([0-9]{2})S/', $path, $m))
			{
				$time = gmmktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})_([0-9]{2})-([0-9]{2})-([0-9]{2})/i', $fn, $m))
			{
				$time = gmmktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
			}
			//Zs4 Raolink Doorbell_00_20240605152917.mp4
			else if(preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})\\.(mp4|jpg|jpeg)$/i', $fn, $m))
			{
				$time = mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})[\\/\\\\]([0-9]{2})H([0-9]{2})M([0-9]{2})S\\.(mp4|jpg|jpeg)$/i', $path, $m))
			{
				$time = mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
			}
//echo date('c', $time).PHP_EOL;
			if(!empty($time) && $time < $since - 3600)
			{
				camlog('-F '.($dir != '.' ? $dir.'/' : '').$fn);
				unlink($path);
				$filecount--;
			}
		}
	}

	return $filecount;
}

function yihack_get_records($path)
{
	global $context;
	global $host;
	global $port;
	$url = "http://$host:$port/$path";
	camlog($url, true, false);
	$t = microtime(true);
	$s = file_get_contents($url, false, $context);
	camlog(sprintf("%.2fs", microtime(true) - $t), false, true);
	if(empty($s)) die('?');
	$obj = json_decode($s, true);
	if(empty($obj['records'])) die('no records?');
//print_r($obj['records']);
	return $obj['records'];
}

function reolink_request($baseurl, $token, $params)
{
	$opts = [
		'http' => [
			'method'  => 'POST',
			'header'  => 'Content-Type: application/json\r\nAccept: application/json\r\n',
			'content' => '['.json_encode($params).']'
			]
		];

	$context  = stream_context_create($opts);

	$url = $baseurl.(!empty($token) ? '&token='.$token : '');

	camlog($url);
//print_r($params);

	$res = file_get_contents($url, false, $context);

//print_r($res);

	if(empty($res)) return false;

	$res = json_decode($res, true);

//print_r($res);

	if(empty($res) || !isset($res[0]['value'])) return false;

	if(isset($res[0]['code']) && $res[0]['code'] != '0') die('reolink api return code '.$res[0]['code']);

	return $res[0]['value'];
}

function reolink_to_time($dt)
{
	return [
		'year' => (int)$dt->format('Y'),
		'mon' => (int)$dt->format('m'),
		'day' => (int)$dt->format('d'),
		'hour' => (int)$dt->format('G'),
		'min' => (int)$dt->format('i'),
		'sec' => (int)$dt->format('s'),
	];
}

function reolink_from_time($t)
{
	if(!is_numeric($t['year'])
	|| !is_numeric($t['mon'])
	|| !is_numeric($t['day'])
	|| !is_numeric($t['hour'])
	|| !is_numeric($t['min'])
	|| !is_numeric($t['sec']))
	{
		print_r($t);
		die('???');
	}

	return mktime($t['hour'], $t['min'], $t['sec'], $t['mon'], $t['day'], $t['year']);
}

//

camlog(date('c', $since).' START');

if(!empty($options['l']))
{
	$fpflock = fopen($options['l'], 'w');
	if(!flock($fpflock, LOCK_EX | LOCK_NB))
		die('already running'.PHP_EOL);
}

if(@disk_free_space(is_dir($basedir) ? $basedir : dirname($basedir)) < 1024*1024*1024)
{
	die('disk full'.PHP_EOL);
}

if(!is_dir($basedir))
{
	camlog('+D '.$basedir);
	@mkdir($basedir); // no recursive here, only do subdirs
}

if(!is_dir($basedir))
{
	die('Cannot create directory, check if '.dirname($basedir).' exists');
}

purgevideos($basedir);

// yihack

if(!empty($options['yihack']))
{
	$src = $options['yihack'];

	if(!preg_match('/^http:\\/\\/([^:]+):([^@]+)@([^\\/:]+)(:([0-9]+))?(\\/.*)$/i', $src, $m))
	{
		die('bad src');
	}

	$username = $m[1];
	$password = $m[2];
	$host = $m[3];
	$port = !empty($m[5]) ? (int)$m[5] : 80;
	$path = $m[6];
	$http_headers = 'Authorization: Basic '.base64_encode("$username:$password");
	$context = stream_context_create(['http' => ['header' => $http_headers]]);

	$files = [];

	foreach(yihack_get_records('cgi-bin/eventsdir.sh') as $dir)
	{
		if(!preg_match('/([0-9]{4})Y([0-9]{2})M([0-9]{2})D([0-9]{2})H/i', $dir['dirname'], $m)) continue;
		$t = gmmktime((int)$m[4], 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
		if($t < $since - 3600) continue;

		foreach(yihack_get_records('cgi-bin/eventsfile.sh?dirname='.$dir['dirname']) as $file)
		{
			if(!preg_match('/([0-9]{2})M([0-9]{2})S([0-9]{2})\\.(avi|mp4)/i', $file['filename'], $m2)) continue;
			$t = gmmktime((int)$m[4], (int)$m2[1], (int)$m2[2], (int)$m[2], (int)$m[3], (int)$m[1]);
			if($t < $since || $t >= $now - 60) continue;
			$files[] = ['path' => $dir['dirname'].'/'.$file['filename'], 'mtime' => $t];
		}
	}

	foreach(yihack_get_records('cgi-bin/timelapse.sh?action=list') as $file)
	{
		if(!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})_([0-9]{2})-([0-9]{2})-([0-9]{2})\\.(avi|mp4)/i', $file['filename'], $m2)) continue;
		$t = gmmktime((int)$m2[4], (int)$m2[5], (int)$m2[6], (int)$m2[2], (int)$m2[3], (int)$m2[1]);
		if($t < $since || $t >= $now - 60) continue;
		$files[] = ['path' => 'timelapse/'.$file['filename'], 'mtime' => $t];
	}

	usort($files, function ($a, $b) { return $a['mtime'] > $b['mtime']; });
//print_r($files);
	//$files = array_reverse($files);

	foreach($files as $f)
	{
//print_r($f);
		$dst = $basedir.'/'.$f['path'];

		$reason = '';

		if(file_exists($dst))
		{
			$mtime = filemtime($dst);
			if($mtime == $f['mtime']) continue;
			$reason = ' ('.date('c', $mtime).' != '.date('c', $f['mtime']).')';
		}

		if(!is_dir(dirname($dst)))
		{
			camlog('+D '.dirname($f['path']));
			@mkdir(dirname($dst), 0777, true);
		}

		camlog('+F '.$f['path'].$reason);

		$cmd = [
			'ffmpeg',
			'-hide_banner',
			'-loglevel', 'error',
			'-y',
			'-readrate', '3.0',
			'-i', "http://$username:$password@$host:$port/record/".$f['path'],
			'-map', '0:v:0',
			'-map', '0:a:0?',
			'-c', 'copy',
			$dst
			];

		$exitcode = exec_timeout($cmd, 180);

		if($exitcode === false || $exitcode != 0)
		{
			camlog('*E '.($exitcode === false ? 'T' : $exitcode).' '.implode(' ', $cmd));
			@unlink($dst);
			if(!copy("http://$host:$port/record/".$f['path'], $dst, $context)) break;
		}

		touch($dst, $f['mtime']);
		//break;
	}
}

if(!empty($options['reolink']))
{
	$src = $options['reolink'];
	$stream = !empty($options['s']) ? $options['s'] : 'main';

	if(!preg_match('/^http:\\/\\/([^:]+):([^@]+)@([^\\/:]+)(:([0-9]+))?(\\/.*)$/i', $src, $m))
	{
		die('bad src');
	}

	$username = $m[1];
	$password = $m[2];
	$host = $m[3];
	$port = !empty($m[5]) ? (int)$m[5] : 80;
	$path = $m[6];

	// see if json is available (/downloadfile/js/Mp4Record/...)

	$baseurl = "http://$host:$port";
	$files = [];
	
	$Mp4Record = !empty($path) && strlen($path) > 1 ? $path : '/downloadfile/js/Mp4Record/';
	$json_dir = json_decode(file_get_contents($baseurl.$Mp4Record), true);		

	if(!empty($json_dir[0]['type']) && $json_dir[0]['type'] == 'directory')
	{
		$path = $Mp4Record;

		foreach($json_dir as $d)
		{
			if(strtotime($d['name'].' +1 day') < $since)
				continue;

			echo $d['name'].PHP_EOL;

			$json_files = json_decode(file_get_contents($baseurl.$path.'/'.$d['name']), true);

			foreach($json_files as $f)
			{
				//echo $d['name'].'/'.$f['name'].PHP_EOL;

				$mtime = strtotime($f['mtime']);

				if($mtime >= $since && $mtime < $now)
				{
					$files[] = [
						'url' => $baseurl.$path.'/'.$d['name'].'/'.$f['name'],
						'dir' => $d['name'],
						'size' => $f['size'],
						'mtime' => $mtime,
					];
				}
			}
		}

		foreach($files as $f)
		{
			$subdir = date("Y-m-d", $f['mtime']);
			$dir = $basedir.'/'.$subdir;

			if(!is_dir($dir))
			{
				camlog('+D '.$subdir);
				@mkdir($dir, 0777, true);
			}

			$fn = sprintf('%02dH%02dM%02dS.mp4',
				date("H", $f['mtime']),
				date("i", $f['mtime']),
				date("s", $f['mtime']));

			$dst = $dir.'/'.$fn;
			$dstsize = @filesize($dst);

			if(is_file($dst) && filesize($dst) == $f['size'] && filemtime($dst) == $f['mtime'])
				continue;

			camlog('+F '.$subdir.'/'.$fn.' ('.$f['size'].')');

			@unlink($dst);

			$fp = fopen($dst, 'wb');

			$ch = curl_init($f['url']);
			//curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			if($throttle > 0) curl_setopt($ch, CURLOPT_MAX_RECV_SPEED_LARGE, $throttle);
			//curl_setopt($ch, CURLOPT_PROXY, 'localhost:8888'); curl_setopt($ch, CURLOPT_HEADER, 1);

			$res = curl_exec($ch);
		
			fclose($fp);

			if($res === false)
			{
				camlog('failed to download');
				exit;
			}

			touch($dst, $f['mtime']);
		}

		exit;
	}

	// fall back to HTTP API

	$baseurl = "http://$host:$port/cgi-bin/api.cgi?cmd=";

	// Login

	$res = reolink_request($baseurl.'Login', null, ['cmd' => 'Login', 'param' => ['User' => ['Version' => 0, 'userName' => $username, 'password' => $password]]]);
//var_dump($res);
	if(empty($res['Token']['name'])) die('no login token');

	$token = $res['Token']['name'];

	// Logout

	register_shutdown_function(function($u, $t) {if(!empty($t)) reolink_request($u.'Logout', $t, ['cmd' => 'Logout', 'param' => []]);}, $baseurl, $token);

//$res = reolink_request($baseurl.'GetRtspUrl', $token, ['cmd' => 'GetRtspUrl', 'action' => 1, 'param' => ['channel' => 0]]);
//print_r($res);exit;
//$res = reolink_request($baseurl.'Reboot', $token, ['cmd' => 'Reboot']);
//print_r($res);exit;
//$res = reolink_request($baseurl.'GetUser', $token, ['cmd' => 'GetUser', 'action' => 1]);
//print_r($res);//exit;
//$res = reolink_request($baseurl.'GetOnline', $token, ['cmd' => 'GetOnline', 'action' => 1]);
//print_r($res);//exit;
//$res = reolink_request($baseurl.'AddUser', $token, ['cmd' => 'AddUser', 'param' => ['User' => ['userName' => 'guest', 'password' => '12345678', 'level' => 'guest']]]);
//print_r($res);exit;
//$res = reolink_request($baseurl.'GetEnc', $token, ['cmd' => 'GetEnc', 'action' => 1, 'param' => ['channel' => 0]]);
//print_r($res);exit;

	// Search

	$start = new DateTime();
	$start->setTimestamp($since);
	$start->setTime(0, 0, 0);

	$end = new DateTime();
	$end->setTimestamp($now);

	$period = new DatePeriod($start, DateInterval::createFromDateString('1 day'), $end);

	foreach($period as $dt)
	{
		$startTime = reolink_to_time($dt);

		$dt->setTime(23, 59, 59);

		$endTime = reolink_to_time($dt);

		$param = [
			'Search' => [
				'channel' => 0,
				'onlyStatus' => 0,
				'streamType' => $stream,
				'StartTime' => $startTime,
				'EndTime' => $endTime,
				]
	    	];
//print_r($param);
		$res = reolink_request($baseurl.'Search', $token, [
			'cmd' => 'Search', 
			'action' => 1, 
			'param' => $param]);
//print_r($res); exit;
		if(!isset($res['SearchResult']['File'])) continue;

		$files = [];

		foreach($res['SearchResult']['File'] as $file)
		{
			$file['start'] = reolink_from_time($file['StartTime']);
			$file['end'] = reolink_from_time($file['EndTime']);

			if($file['start'] >= $since && $file['start'] < $now)
			{
				$files[] = $file;
			}
		}

		usort($files, function ($a, $b) { return $a['start'] > $b['start'] ? 1 : -1; });

		while(!empty($files))
		{
			$file = array_shift($files);
			//$subdir = sprintf("%04d/%02d/%02d", $file['StartTime']['year'], $file['StartTime']['mon'], $file['StartTime']['day']);
			$subdir = sprintf("%04d-%02d-%02d", $file['StartTime']['year'], $file['StartTime']['mon'], $file['StartTime']['day']);
			$dir = $basedir.'/'.$subdir;

			if(!is_dir($dir))
			{
				camlog('+D '.$subdir);
				@mkdir($dir, 0777, true);
			}

			$start = preg_match('/.*Rec(\w{3})(?:_|_DST)(\d{8})_(\d{6})_.*/', $file['name'], $m) ? '&start='.$m[2].$m[3] : '';

			$download = $baseurl.'Download&token='.$token.'&source='.$file['name'].'&output='.basename($file['name']).$start;
			$playback = $baseurl.'Playback&token='.$token.'&source='.$file['name'].$start;

			$fn = sprintf('%02dH%02dM%02dS.mp4',
				$file['StartTime']['hour'],
				$file['StartTime']['min'],
				$file['StartTime']['sec']);

			$dst = $dir.'/'.$fn;
			$dstsize = @filesize($dst);

			//if(is_file($dst) && filesize($dst) == $file['size'] && filemtime($dst) == $file['start'])
			if(is_file($dst) && $dstsize > 0 && ($file['size'] - $dstsize) < 1024*1024 && filemtime($dst) == $file['start'])
			{
				continue;
				//break;
			}

			camlog('+F '.$subdir.'/'.$fn.' ('.$file['size'].')');
//echo $download.PHP_EOL;

			@unlink($dst);

			$fp = fopen($dst, 'wb');

			$ch = curl_init($download);
			//curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			if($throttle > 0) curl_setopt($ch, CURLOPT_MAX_RECV_SPEED_LARGE, $throttle);
			//curl_setopt($ch, CURLOPT_PROXY, 'localhost:8888'); curl_setopt($ch, CURLOPT_HEADER, 1);

			$res = curl_exec($ch);
		
			fclose($fp);

			if($res === false)
			{
				camlog('*E '.curl_error($ch).' '.$src);
				@unlink($dst);

				$cmd = [
					'ffmpeg',
					'-hide_banner',
					'-loglevel', 'error',
					'-y',
					'-probesize', '1000000',
					'-timeout', '3000000',
					//'-readrate', '3.0',
					'-i', "$playback",
					'-map', '0:v:0',
					'-map', '0:a:0?',
					'-c', 'copy',
					$dst
					];
		
				$exitcode = exec_timeout($cmd, 180);
		
				if($exitcode === false || $exitcode != 0)
				{
					camlog('*E'.($exitcode === false ? 'T' : $exitcode).' '.implode(' ', $cmd));
					@unlink($dst);
				}
			}

			curl_close($ch);

			if(file_exists($dst))
			{
				touch($dst, $file['start']);
			}
			else
			{
				/*
				if(empty($file['retry'])) $file['retry'] = 0;
				$file['retry']++;
				if($file['retry'] < 3) $files[] = $file; //array_unshift($files, $file);
				sleep(1);
				*/
			}
		}
	}
}

//

camlog(date('c', $since).' - '.date('c').' END');

if(!empty($fpflock))
{
	fclose($fpflock);
	@unlink($options['l']);
}

?>
