<?php

$options = getopt('i:o:h:m:l:c:f:v');

if(empty($options['m']))
{
	if(!empty($options['h']) && preg_match('/^(\\d+):(\\d{2})$/', $options['h'], $m))
	{
		$options['h'] = (int)$m[1];
		$options['m'] = (int)$m[2];
	}
	else if(!empty($options['h']) && preg_match('/^(\\d+)\\.(\\d+)$/', $options['h'], $m))
	{
		$options['h'] = (int)$m[1];
		$options['m'] = (int)((float)('0.'.$m[2]) * 60);
	}
	else
	{
		$options['m'] = 0;
	}
}

$dir = rtrim($options['i'], "\\/");
$since = isset($options['h']) ? (strtotime(sprintf("-%d hour -%d minute", (int)$options['h'], (int)$options['m']), time())) : 0;
$length = !empty($options['l']) ? (int)$options['l']*3600 : 0;
$dstdir = !empty($options['o']) ? rtrim($options['o'], "\\/") : $dir;
$dstfn = basename(realpath($dir));
$dst = $dstdir.'/'.$dstfn.'.mkv';
$list = $dstdir.'/'.$dstfn.'.txt';
$framestep = isset($options['f']) ? (int)$options['f'] : 15;
$verify = isset($options['v']);

echo date('c', $since).PHP_EOL;

sleep(1);

if(!is_dir($dir)) die($dir.' ???');

function scanvideos($basedir, $dir = '.')
{
	global $since;
	global $length;
	global $jpgs;
	
	foreach(scandir($basedir.'/'.$dir) as $fn)
	{
		if($fn[0] == '.') continue;
		
		$path = str_replace('/./', '/', $basedir.'/'.$dir.'/'.$fn);
		
		if(is_dir($path))
		{
			$subdir = $dir.'/'.$fn;
			
			$time = null;

			if(preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})/', $subdir, $m))
			{
				$time = mktime($m[4], 59, 59, $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/([0-9]{4})Y([0-9]{2})M([0-9]{2})D([0-9]{2})H/', $subdir, $m))
			{
				$time = gmmktime($m[4], 59, 59, $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/([0-9]{4})[\\/\\\\]([0-9]{2})[\\/\\\\]([0-9]{2})/', $subdir, $m))
			{
				$time = mktime(23, 59, 59, $m[2], $m[3], $m[1]);
			}
			else if(preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $fn, $m))
			{
				$time = mktime(23, 59, 59, $m[2], $m[3], $m[1]);
			}
			
			if(!empty($time))
			{
//echo 'subdir '.$subdir.' '.date('c', $time).' < '.date('c', $since).' ?'.PHP_EOL;
				if($time < $since) continue;
				if($length > 0 && $time > $since + $length) break;
			}
			
			scanvideos($basedir, $subdir);
		}

		if(!preg_match('/\\.mp4$/i', $fn)) continue;
		
		$time = filectime($path);

		if(preg_match('/_([0-9]{10})\\./', $fn, $m))
		{
			$time = $m[1];
		}
		else if(preg_match('/([0-9]{4})Y([0-9]{2})M([0-9]{2})D([0-9]{2})H.+?([0-9]{2})M([0-9]{2})S/', $path, $m))
		{
//print_r($m);
			//$tz = date_default_timezone_get();
			//date_default_timezone_set('UTC');
			$time = gmmktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
			//date_default_timezone_set($tz);
		}
		//Zs4 Raolink Doorbell_00_20240605152917.mp4
		else if(preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})\\.mp4$/', $fn, $m))
		{
			$time = mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
		}
		else if(preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})[\\/\\\\]([0-9]{2})H([0-9]{2})M([0-9]{2})S\\.mp4$/', $fn, $m))
		{
			$time = mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
		}
		
//echo date('c', $time).' < '.date('c', $since).' ?'.PHP_EOL;
		if($time < $since) continue;
		if($length > 0 && $time > $since + $length) break;

		echo $path.PHP_EOL;
		
		$res = 0;

		$cmd = 'ffprobe -hide_banner -loglevel warning -i "'.$path.'"';

		passthru($cmd, $res);

		if($res == 0)
		{
			global $verify;
		
			if($verify)
			{
				passthru('ffmpeg -hide_banner -v error -xerror -i "'.$path.'" -f null -', $res);
				if(!empty($res)) {echo 'skip'.PHP_EOL; continue;}
			}
	
			$jpgs[$time] = $path;
		}
	}
}

scanvideos($dir);

if(empty($jpgs)) die('empty');

$fp = fopen($list, 'w');

ksort($jpgs);

foreach($jpgs as $time => $jpg)
{
	fprintf($fp, "file '%s'\n", $jpg);
	fprintf($fp, "file_packet_meta fn '%s'\n", substr($jpg, strrpos($jpg, '\\') + 1));
	fprintf($fp, "file_packet_meta t '%s'\n", strftime('%A %H:%M:%S', $time));
	fprintf($fp, "file_packet_meta ts '%s'\n", $time);
}

fclose($fp);

$cmd = 'ffmpeg -hide_banner -safe 0 -f concat -i "'.$list.'" -map 0:v:0';

if($framestep > 0)
{
	$cmd .= ' -vf "';
	// $vf .= 'framestep=150,setpts=N/30/TB,fps=30,crop=960:1080:960:0';
	// $vf .= 'framestep=60,setpts=N/60/TB,fps=60';
	//$cmd .= 'framestep=15,setpts=N/60/TB,fps=60';
	$cmd .= 'framestep='.$framestep.',setpts=N/60/TB,fps=60';
	$cmd .= ',drawtext=text=\'%{metadata\:fn}\':font=Arial:fontcolor=White:fontsize=24:x=w-tw-10:y=10';
	$cmd .= ',drawtext=text=\'%{metadata\:t}\':font=Arial:fontcolor=White:fontsize=24:x=w-tw-10:y=40';
	$cmd .= ',drawtext=text=\'%{pts}\':font=Arial:fontcolor=White:fontsize=24:x=w-tw-10:y=70';
	$cmd .= '"';

	//if(!empty($options['c']) && ($options['c'] == 'hevc_amf' || $options['c'] == 'amd')) $codec = '-c:v hevc_amf -profile_tier high -quality:v quality -rc cqp';
	//if(!empty($options['c']) && ($options['c'] == 'hevc_amf' || $options['c'] == 'amd')) $codec = '-c:v hevc_amf -profile_tier high -quality:v quality -b:v 5000k -minrate:v 500k -maxrate:v 50000k';
	//if(!empty($options['c']) && ($options['c'] == 'hevc_amf' || $options['c'] == 'amd')) $codec = '-c:v h264_amf -profile_tier high -quality:v quality -b:v 5000k -minrate:v 500k -maxrate:v 50000k';
	if(!empty($options['c']) && ($options['c'] == 'hevc_amf' || $options['c'] == 'amd'))
	{
		$codec = '-c:v h264_amf -profile_tier high -quality:v quality -b:v 2500k -minrate:v 100k -maxrate:v 50000k';
	}
	else if(!empty($options['c']) && ($options['c'] == 'h264_nvenc' || $options['c'] == 'nvidia'))
	{
		$codec = '-c:v h264_nvenc -profile:v high -preset:v medium -tune hq -b:v 5000k -minrate:v 100k -maxrate:v 50000k';
	}
	else
	{
		$codec = '-c:v libx264 -profile:v high -preset:v medium -crf 23';
	}

	$cmd .= ' '.$codec;
}
else
{
	$cmd .= ' -c:v copy -map 0:a:0 -c:a aac';
	//$cmd .= ' -c copy';
}

$cmd .= ' "'.$dst.'"';

//die($cmd);

passthru($cmd);

@unlink($list);

?>
