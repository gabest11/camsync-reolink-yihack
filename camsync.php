#!/usr/bin/php-cli
<?php

//set_time_limit(900-5);
//ini_set('default_socket_timeout', 10);

//var_dump($argv);
$options = getopt('d:h:l:', ['yihack::', 'reolink::', 'throttle::']);
//var_dump($options);
if(empty($options['d']) || !is_dir($options['d']) || empty($options['h']))
{
	die('bad args');
}

$dst = rtrim($options['d'], "\\/");
$now = time();
$since = strtotime(sprintf("-%d hours", (int)$options['h']), time());
$throttle = !empty($options['throttle']) ? max(10, (int)$options['throttle'] / 10) * 1024 : 0; // bytes per 0.1 sec

function exec_timeout($cmd, $timeout)
{
	if(is_array($cmd) && version_compare(PHP_VERSION, '7.4.0', '<'))
		$cmd = implode($cmd, ' ');
	
	$process = proc_open($cmd, array(), $pipes);

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
		if($fn[0] == '.') {$filecount--; continue;}

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

			if(!empty($time) && $time >= $since) continue;

			purgevideos($basedir, $subdir);
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
			if(empty($time) || $time >= $since - 3600) continue;

			echo '-F '.($dir != '.' ? $dir.'/' : '').$fn.PHP_EOL;
			unlink($path);
			$filecount--;
		}
	}
//echo 'C '.$filecount.PHP_EOL;
	if($filecount == 0 && $dir != '.')
	{
		echo '-D '.$dir.PHP_EOL;
		rmdir($basedir.'/'.$dir);
	}
}

function yihack_get_records($path)
{
	global $context;
	global $host;
	global $port;
	$url = "http://$host:$port/$path";
echo $url;
$t = microtime(true);
	$s = file_get_contents($url, false, $context);
printf(" %.2fs\n", microtime(true) - $t);
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
	
	echo $url.PHP_EOL;
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

//

echo date('c', $since).' START'.PHP_EOL;

if(!empty($options['l']))
{
	$fpflock = fopen($options['l'], 'w');
	if(!flock($fpflock, LOCK_EX | LOCK_NB))
		die('already running'.PHP_EOL);
}

purgevideos($dst);

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
		$d = $dst.'/'.$f['path'];

		$reason = '';

		if(file_exists($d))
		{
			$mtime = filemtime($d);
			if($mtime == $f['mtime']) continue;
			$reason = ' ('.date('c', $mtime).' != '.date('c', $f['mtime']).')';
		}

		if(!is_dir(dirname($d)))
		{
			echo '+D '.dirname($f['path']).PHP_EOL;
			@mkdir(dirname($d), 0777, true);
		}

		echo '+F '.$f['path'].$reason.PHP_EOL;

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
			$d
			];

		$exitcode = exec_timeout($cmd, 180);

		if($exitcode === false)
		{
			echo 'timeout'.PHP_EOL;
			@unlink($d);
			break;
		}

		if($exitcode != 0)
		{
			echo (int)$exitcode.' = '.implode(' ', $cmd).PHP_EOL;
			@unlink($d);
			if(!copy("http://$host:$port/record/".$f['path'], $d, $context)) break;
		}
	
		touch($d, $f['mtime']);
		//break;
	}
}

if(!empty($options['reolink']))
{
	$src = $options['reolink'];

	if(!preg_match('/^http:\\/\\/([^:]+):([^@]+)@([^\\/:]+)(:([0-9]+))?(\\/.*)$/i', $src, $m))
	{
		die('bad src');
	}

	$username = $m[1];
	$password = $m[2];
	$host = $m[3];
	$port = !empty($m[5]) ? (int)$m[5] : 80;
	$path = $m[6];
	
	$baseurl = "http://$host:$port/cgi-bin/api.cgi?cmd=";
	
	// Login

	$res = reolink_request($baseurl.'Login', null, ['cmd' => 'Login', 'param' => ['User' => ['Version' => 0, 'userName' => $username, 'password' => $password]]]);
//var_dump($res);
	if(empty($res['Token']['name'])) die('no login token');
	
	$token = $res['Token']['name'];

	// Logout

	register_shutdown_function(function($u, $t) {if(!empty($t)) reolink_request($u.'Logout', $t, ['cmd' => 'Logout', 'param' => []]);}, $baseurl, $token);

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
	       	$startTime = [
       			'year' => (int)$dt->format('Y'),
	       		'mon' => (int)$dt->format('m'),
	       		'day' => (int)$dt->format('d'),
	       		'hour' => (int)$dt->format('G'),
	       		'min' => (int)$dt->format('i'),
	       		'sec' => (int)$dt->format('s'),
	       		];

		$dt->setTime(23, 59, 59);
        	
	       	$endTime = [
	       		'year' => (int)$dt->format('Y'),
	       		'mon' => (int)$dt->format('m'),
	       		'day' => (int)$dt->format('d'),
	       		'hour' => (int)$dt->format('G'),
	       		'min' => (int)$dt->format('i'),
       			'sec' => (int)$dt->format('s'),
       			];

	    	$param = ['Search' => [
	        		'channel' => 0,
		        	'onlyStatus' => 0,
	        		'streamType' => 'main',
	        		'StartTime' => $startTime,
		        	'EndTime' => $endTime,
		        	]
	            	];
//print_r($param);
		$res = reolink_request($baseurl.'Search', $token, ['cmd' => 'Search', 'action' => 0, 'param' => $param]);
//print_r($res);
//exit;
		if(!isset($res['SearchResult']['File'])) continue;
		
		$files = [];
		
		foreach($res['SearchResult']['File'] as $file)
		{
			if(!is_numeric($file['StartTime']['year'])
			|| !is_numeric($file['StartTime']['mon'])
			|| !is_numeric($file['StartTime']['day'])
			|| !is_numeric($file['StartTime']['hour'])
			|| !is_numeric($file['StartTime']['min'])
			|| !is_numeric($file['StartTime']['sec']))
			{
				print_r($file['StartTime']);
				die('??? 2');
			}
			
			if(!is_numeric($file['EndTime']['year'])
			|| !is_numeric($file['EndTime']['mon'])
			|| !is_numeric($file['EndTime']['day'])
			|| !is_numeric($file['EndTime']['hour'])
			|| !is_numeric($file['EndTime']['min'])
			|| !is_numeric($file['EndTime']['sec']))
			{
				print_r($file['EndTime']);
				die('??? 3');
			}

			$file['start'] = mktime(
				$file['StartTime']['hour'],
				$file['StartTime']['min'],
				$file['StartTime']['sec'],
				$file['StartTime']['mon'],
				$file['StartTime']['day'],
				$file['StartTime']['year']);

			$file['end'] = mktime(
				$file['EndTime']['hour'],
				$file['EndTime']['min'],
				$file['EndTime']['sec'],
				$file['EndTime']['mon'],
				$file['EndTime']['day'],
				$file['EndTime']['year']);
			
			$files[] = $file;
		}
		
		usort($files, function ($a, $b) { return $a['start'] > $b['start']; });

		foreach($files as $file)
		{
			if($file['start'] < $since) continue;
			if($file['start'] >= $now) break;
			
			//$subdir = sprintf("%04d/%02d/%02d", $file['StartTime']['year'], $file['StartTime']['mon'], $file['StartTime']['day']);
			$subdir = sprintf("%04d-%02d-%02d", $file['StartTime']['year'], $file['StartTime']['mon'], $file['StartTime']['day']);
			$dir = $dst.'/'.$subdir;
			
			if(!is_dir($dir))
			{
				echo '+D '.$subdir.PHP_EOL;
				@mkdir($dir, 0777, true);
			}
			
//			$n = basename($file['name']);
			
			$fn = sprintf('%02dH%02dM%02dS.mp4', //'%04d%02d%02d%02d%02d%02d.mp4', 
				//$file['StartTime']['year'],
				//$file['StartTime']['mon'],
				//$file['StartTime']['day'],
				$file['StartTime']['hour'],
				$file['StartTime']['min'],
				$file['StartTime']['sec']);
			
			$path = $dir.'/'.$fn;
			
			if(is_file($path) && filesize($path) == $file['size'] && filemtime($path) == $file['start'])
			{
				continue;
				//break;
			}
			
			echo '+F '.$subdir.'/'.$fn.' ('.$file['size'].')'.PHP_EOL;
			
			@unlink($path);
			
			$url = $baseurl.'Download&token='.$token.'&source='.urlencode($file['name']).'&output='.urlencode(basename($file['name']));
//echo $url.PHP_EOL;
/*
			$cmd = [
				'ffmpeg',
				'-hide_banner',
				'-loglevel', 'error',
				'-y',
				'-readrate', '3.0',
				'-i', $url,
				'-map', '0:v:0',
				'-map', '0:a:0?',
				'-c', 'copy',
				$path
				];

			$exitcode = exec_timeout($cmd, 180);

			if($exitcode === false)
			{
				echo 'timeout'.PHP_EOL;
				@unlink($path);
				die('??? 4');
			}

			if($exitcode != 0)
			{
				echo (int)$exitcode.' = '.implode(' ', $cmd).PHP_EOL;
				@unlink($path);
*/
				//if(!copy($url, $path)) die('??? 5');

				$fpdst = fopen($path, "wb");

				if(!empty($fpdst))
				{
					$fpsrc = fopen($url, "rb");
					
					if(!empty($fpsrc))
					{
						$tstart = microtime(true);
						$total = 0;
						
						while(!feof($fpsrc))
						{
							$buff = '';
							
							if(empty($throttle))
							{
								$buff = fread($fpsrc, 8192);
								if(empty($buff)) break;
								fwrite($fpdst, $buff);
							}
							else
							{
								$t1 = microtime(true);
								while(!feof($fpsrc) && strlen($buff) < $throttle)
									$buff .= fread($fpsrc, 1024);
								if(empty($buff)) break;
								fwrite($fpdst, $buff);
								$t21 = microtime(true) - $t1;
								if($t21 < 0.1)
								{
									$sleep = 0.1 - $t21;
									//echo strlen($buff).'B sleep '.$sleep.'s'.PHP_EOL;
									//usleep(1000 * $sleep);
									time_nanosleep($sleep % 1000, fmod($sleep, 1) * 1000000000);
								}
							}
							
							$total += strlen($buff);
						}
						
						fclose($fpsrc);

						//echo (int)($total / (microtime(true) - $tstart) / 1024).' KB/s'.PHP_EOL;
					}
				}
				
				fclose($fpdst);
				
				if(filesize($path) != $file['size']) die('??? 6');
//			}
			
			touch($path, $file['start']);
		}
	}
}

//

echo date('c', $since).' - '.date('c').' END'.PHP_EOL;

if(!empty($fpflock))
{
	fclose($fpflock);
	@unlink($options['l']);
}

?>
