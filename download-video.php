<?php
  set_time_limit(0);
  if(isset($_REQUEST['f'])) {
    $file = base64_decode(str_replace(array('-', '_'), array('+', '/'), $_REQUEST['f']));
    //$filedata = @file_get_contents($file);

    header('Content-Type: application-x/force-download');
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    //header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="'.basename($file).'"');
    //header("Content-length: " . (string)(strlen($filedata)));
    header('Content-Transfer-Encoding: binary');
    header("Expires: ".gmdate("D, d M Y H:i:s", mktime(date("H")+2, date("i"), date("s"), date("m"), date("d"), date("Y")))." GMT");
    header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");

    // THIS HEADER MUST BE OMITTED FOR IE 6+
    if (FALSE === strpos($_SERVER["HTTP_USER_AGENT"], 'MSIE ')) {
      header("Cache-Control: no-cache, must-revalidate");
    }
    header('Pragma: public');

    flush();
    ob_clean();
    $rfile = fopen($file, 'r');
    while(!feof($rfile)) {
      echo fread($rfile, 4095);
      flush();
    }
    fclose($rfile);

    //ob_clean();
    //flush();
    //file_get_contents($file);
    //readfile($file);
  }
  exit;
?>
