<?php

/**
 * 从网络地址装载图片,返回一个图片对象
 */
function imageCreateFromUrl($url) {
	$content = @file_get_contents($url);
	if ($content === false) {
		return false;
	}
	$image = imagecreatefromstring($content);
	unset($content);
	return $image;
}

/**
 * 图像缩放为固定宽度,高度按比例自动大小
 */
function imageResizeByWidth($src, $width) {
	$src_width = imagesx($src);
	$src_height = imagesy($src);
	$height = $src_height * $width / $src_width;
	$dist = imagecreatetruecolor($width, $height);
	if (function_exists("imagecopyresampled")) {
		imagecopyresampled($dist, $src, 0, 0, 0, 0, $width, $height, $src_width, $src_height);
	} else {
		imagecopyresized($dist, $src, 0, 0, 0, 0, $width, $height, $src_width, $src_height);
	}
	return $dist;
}

/**
 * 图像向外扩张,会裁减超出边界的像素
 */
function imageResizeOut($src, $minwidth, $minheight) {
	$src_width = imagesx($src);
	$src_height = imagesy($src);

	$rx = $minwidth / $src_width;
	$ry = $minheight / $src_height;
	$r = max($rx, $ry);

	$dist_width = round($src_width * $r);
	$dist_height = round($src_height * $r);

	$dist_x = round(($minwidth - $dist_width) / 2);
	$dist_y = round(($minheight - $dist_height) / 2);

	$dist = imagecreatetruecolor($minwidth, $minheight);
	if (function_exists("imagecopyresampled")) {
		imagecopyresampled($dist, $src, $dist_x, $dist_y, 0, 0, $dist_width, $dist_height, $src_width, $src_height);
	} else {
		imagecopyresized($dist, $src, $dist_x, $dist_y, 0, 0, $dist_width, $dist_height, $src_width, $src_height);
	}
	return $dist;
}

/**
 * 图像向内缩,保持图像全部,不裁减,周围填充白色
 */
function imageResizeIn($src, $maxwidth, $maxheight) {
	$src_width = imagesx($src);
	$src_height = imagesy($src);

	$rx = $maxwidth / $src_width;
	$ry = $maxheight / $src_height;
	$r = min($rx, $ry);
	$dist_width = round($src_width * $r);
	$dist_height = round($src_height * $r);

	$dist_x = round(($maxwidth - $dist_width) / 2);
	$dist_y = round(($maxheight - $dist_height) / 2);

	$dist = imagecreatetruecolor($maxwidth, $maxheight);
	$white = imagecolorallocate($dist, 255, 255, 255);
	imagefilledrectangle($dist, 0, 0, $maxwidth, $maxheight, $white);
	if (function_exists("imagecopyresampled")) {
		imagecopyresampled($dist, $src, $dist_x, $dist_y, 0, 0, $dist_width, $dist_height, $src_width, $src_height);
	} else {
		imagecopyresized($dist, $src, $dist_x, $dist_y, 0, 0, $dist_width, $dist_height, $src_width, $src_height);
	}
	return $dist;
}

/**
 * 记得要销毁图像对象: imagedestroy($src);
 * 输出图像用: imagejpeg(),imagegif()或imagepng()
 */
function imageResize($src, $maxwidth = 620, $maxheight = 960, $minwidth = 480) {
	$pic_width = imagesx($src);
	$pic_height = imagesy($src);

	$ratiox = $maxwidth / $pic_width;
	$ratioy = $maxheight / $pic_height;

	if ($ratiox > $ratioy) {
		$width = round($ratioy * $pic_width);
		$height = $maxheight;
	} else {
		$width = $maxwidth;
		$height = round($ratiox * $pic_height);
	}
	$y = 0;
	if ($width < $minwidth) {
		$height = round($pic_height * $minwidth / $pic_width);
		if ($height > $maxheight) {
			$y = round(($height - $maxheight) / 2);
			$height = $maxheight;
		}
		$width = $minwidth;
	}
	// echo "resize :$width, $height ,";
	$dist = imagecreatetruecolor($width, $height);
	if (function_exists("imagecopyresampled")) {
		imagecopyresampled($dist, $src, 0, 0, 0, $y, $width, $height, $pic_width, $pic_height);
	} else {
		imagecopyresized($dist, $src, 0, 0, 0, $y, $width, $height, $pic_width, $pic_height);
	}
	return $dist;
}

/**
 * 将数据信息存放在图像的像素中,输出时要选择无损压缩png等,否则无法还原信息
 */
function text2image($text) {
	$len = strlen($text);
	$text = $len . ' ' . $text;
	$len += strlen($len . " ");
	$width = ceil(sqrt(ceil($len / 3)));
	$height = $width;
	$img = imagecreatetruecolor($width, $height);
	$count = 0;
	$colors = array();
	for ($i = 0; $i < $len; $i += 3) {
		$red = hexdec(bin2hex(substr($text, $i, 1)));
		$green = ($i + 1 > $len ? 0 : hexdec(bin2hex(substr($text, $i + 1, 1))));
		$blue = ($i + 2 > $len ? 0 : hexdec(bin2hex(substr($text, $i + 2, 1))));
		$rgb = $red<<16 | $green<<8 | $blue;
		if (array_key_exists($rgb, $colors)) {
			$color = $colors[$rgb];
		} else {
			$color = imagecolorallocate($img, $red & 0xFF, $green & 0xFF, $blue & 0xFF);
			$colors[$rgb] = $color;
		}
		$y = floor($count / $width);
		$x = $count % $width;
		// echo "$x,$y,$red,$green,$blue,$color,$rgb <br>";
		imagesetpixel($img, $x, $y, $color);
		$count++;
	}
	return $img;
	// imagepng($img );return;
}

/**
 * 从图像中还原信息(之前调用text2image存放的).
 */
function image2text($image) {
	$text = "";
	$count = 0;
	$width = imagesx($image);
	$height = imagesy($image);
	$read = 0;
	$temp = "";
	for ($y = 0; $y < $height; $y++) {
		for ($x = 0; $x < $width; $x++) {
			$color = imagecolorat($image, $x, $y);
			$rgb = imagecolorsforindex($image, $color);
			$r = chr($rgb['red']);
			$g = chr($rgb['green']);
			$b = chr($rgb['blue']);

			if ($count == 0) {
				if ($r == ' ') {
					$count = $temp + 0;
					$text .= ($g . $b);
				} elseif ($g == ' ') {
					$count = ($temp . $r) + 0;
					$text .= ($b);
				} elseif ($b == ' ') {
					$count = ($temp . $r . $g) + 0;
				} else {
					$temp .= ($r . $g . $b);
				}
			} else {
				if (($read++) < $count) {
					$text .= $r;
				}
				if (($read++) < $count) {
					$text .= $g;
				}
				if (($read++) < $count) {
					$text .= $b;
				} else {
					return $text;
				}
			}
		}
	}
}
