# camsync
PHP script to download recordings from reolink and yihack cameras to a local directory.

### Example:

I have something similar in my crontab on a router running openwrt and an ssd connected to its usb port. The script should also work in Windows.

```
timeout 29m ~/camsync.php --yihack="http://yicam:password@192.168.0.2:8080/" -d /mnt/usb/camera/yicam -l /tmp/camsync_yicam.flock -h 72 2>&1 >> /mnt/usb/camera/yicam.log
timeout 29m ~/camsync.php --reolink="http://admin:password@192.168.0.3/" -d /mnt/usb/camera/reolink -l /tmp/camsync_reolink.flock -h 72 --throttle=500 2>&1 >> /mnt/usb/camera/reolink.log
```

Recommended to use `timeout` to kill stuck downloads.

```
-d target directory
-h hours to download and auto-delete older files from the local mirror
-l lock file to prevent multiple runs (optional)
--throttle KB/s, this only works with reolink, as HD files can kill wifi, this isn't really a problem with yi-hack
--reolink http server has to be enabled on the camera
--yihack needs ffmpeg to remux bogus mp4 files
```
