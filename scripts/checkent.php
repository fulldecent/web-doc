<?php
/**
 * +----------------------------------------------------------------------+
 * | PHP Documentation Site Source Code                                   |
 * +----------------------------------------------------------------------+
 * | Copyright (c) 1997-2005 The PHP Group                                |
 * +----------------------------------------------------------------------+
 * | This source file is subject to version 3.0 of the PHP license,       |
 * | that is bundled with this package in the file LICENSE, and is        |
 * | available at through the world-wide-web at                           |
 * | http://www.php.net/license/3_0.txt.                                  |
 * | If you did not receive a copy of the PHP license and are unable to   |
 * | obtain it through the world-wide-web, please send a note to          |
 * | license@php.net so we can mail you a copy immediately.               |
 * +----------------------------------------------------------------------+
 * | Authors: Georg Richter <georg@php.net>                               |
 * |          Gabor Hojsty <goba@php.net>                                 |
 * | Docweb port: Nuno Lopes <nlopess@php.net>                            |
 * |              Mehdi Achour <didou@php.net>                            |
 * +----------------------------------------------------------------------+
 *
 * $Id$
 */


/*
 * checks for errors in URL entities. Currently supports FTP and HTTP.
 *
 * Based on phpdoc/scripts/checkent.php by Georg Richter and Gabor Hojtsy.
 */

error_reporting(E_ALL);
 
set_time_limit(0);
$inCli = true;
include '../include/init.inc.php';

$filename = CVS_DIR . '/phpdoc-all/entities/global.ent';

// Schemes currently supported
$schemes = array('http');
if (function_exists('ftp_connect')) {
    $schemes[] = 'ftp';
}

// constants for errors
define('UNKNOWN_HOST', 0);
define('FTP_CONNECT', 1);
define('FTP_LOGIN', 2);
define('FTP_NO_FILE', 3);
define('HTTP_CONNECT', 4);
define('HTTP_MOVED', 5);
define('HTTP_WRONG_HEADER', 6);
define('HTTP_INTERNAL_ERROR', 7);
define('HTTP_NOT_FOUND', 8);


if (!$file = file_get_contents($filename)) {
    exit;
}

$array = explode('<!-- Obsoletes -->', $file);


// Find entity names and URLs
$schemes_preg = '(?:' . join('|', $schemes) . ')';
preg_match_all("@<!ENTITY\s+(\S+)\s+([\"'])({$schemes_preg}://[^\\2]+)\\2\s*>@U", $array[0], $entities_found);

// These are the useful parts
$entity_names = $entities_found[1];
$entity_urls  = $entities_found[3];

$errors = array();

// Walk through entities found
foreach($entity_urls as $num => $entity_url) {

    
    // Get the parts of the URL
    $url = parse_url($entity_url);
    $entity = $entity_names[$num];
  
    // Try to find host
    if (gethostbyname($url['host']) == $url['host']) {
        $errors[UNKNOWN_HOST][] = array($num);
        continue;
    }
    


    switch($url['scheme']) {
    
        case 'http':
            $url['path'] = isset($url['path']) ? $url['path'] : '/';

            if (!$fp = @fsockopen($url['host'], 80, $errno, $errstr, 30)) {
                $errors[HTTP_CONNECT][] = array($num);

            } else {
                fputs($fp, "HEAD {$url['path']} HTTP/1.0\r\nHost: {$url['host']}\r\nConnection: close\r\n\r\n");

                $str = '';
                while (!feof($fp)) {
                    $str .= fgets($fp, 2048);
                }
                fclose ($fp);

                if (preg_match('@HTTP/1.\d (\d+)(?: .+)?@S', $str, $match)) {

                    if ($match[1] != '200') {

                        switch ($match[1])
                        {
                            case '500' :
                            case '501' :
                            $errors[HTTP_INTERNAL_ERROR][] = array($num);
                            fputs($o, $str);
                            break;
                            
                            case '404' :
                            $errors[HTTP_NOT_FOUND][] = array($num);
                            break;

                            case '301' :
                            case '302' :
                            if (preg_match('/Location: (.+)/', $str, $redir)) {
                                $errors[HTTP_MOVED][] = array($num, $redir[1]);
                            } else {
                                $errors[HTTP_WRONG_HEADER][] = array($num, $str);
                            }
                            break;
                        
                            default :
                            $errors[HTTP_WRONG_HEADER][] = array($num, $str);
                        }
                    } // error != 200
                } else {
                    $errors[HTTP_WRONG_HEADER][] = array($num, $str);
                }
            }
        break;
    

        case 'ftp':
            if ($ftp = @ftp_connect($url['host'])) {
                
                if (@ftp_login($ftp, 'anonymous', 'IEUser@')) {
                    $flist = ftp_nlist($ftp, $url['path']);
                    if (!count($flist)) {
                        $errors[FTP_NO_FILE][] = array($num);
                    }
                } else {
                    $errors[FTP_LOGIN][] = array($num);
                }
                @ftp_quit($ftp);
            } else {
                $errors[FTP_CONNECT][] =  array($num);
            }
        break;

    }
}

// ouput the html
echo "<?php include_once '../include/init.inc.php'; 
echo site_header('docweb.common.header.checkent'); 
?><p>Last check: " . date('r') . "</p>";

if (isset($errors[UNKNOWN_HOST])) {
    echo '<h2>Unknown host (' . count($errors[UNKNOWN_HOST]) . ')</h2>' .
'<table >
  <tr class="blue">
    <th>Entity Name</th>
    <th>URL</th>
  </tr>';

    foreach ($errors[UNKNOWN_HOST] as $infos) {
        echo '<tr>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_names[$infos[0]] . '</a></td>
        '//<td>' . $entity_names[$infos] . '</td>
        .'<td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_urls[$infos[0]] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}

if (isset($errors[HTTP_CONNECT])) {
    echo '<h2>HTTP Failed to connect (' . count($errors[HTTP_CONNECT]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>URL</th>
  </tr>';

    foreach ($errors[HTTP_CONNECT] as $infos) {
        echo '<tr>
        <td>' . $entity_names[$infos[0]] . '</td>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_urls[$infos[0]] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}

if (isset($errors[FTP_CONNECT])) {
    echo '<h2>FTP Failed to connect (' . count($errors[FTP_CONNECT]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>URL</th>
  </tr>';

    foreach ($errors[FTP_CONNECT] as $infos) {
        echo '<tr>
        <td>' . $entity_names[$infos[0]] . '</td>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_urls[$infos[0]] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}

if (isset($errors[FTP_LOGIN])) {
    echo '<h2>FTP Cannot login (' . count($errors[FTP_LOGIN]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>URL</th>
  </tr>';


    foreach ($errors[FTP_LOGIN] as $infos) {
        echo '<tr>
        <td>' . $entity_names[$infos[0]] . '</td>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_urls[$infos[0]] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}

if (isset($errors[FTP_NO_FILE])) {
    echo '<h2>FTP File not found (' . count($errors[FTP_NO_FILE]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>URL</th>
  </tr>';


    foreach ($errors[FTP_NO_FILE] as $infos) {
        echo '<tr>
        <td>' . $entity_names[$infos[0]] . '</td>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_urls[$infos[0]] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}


if (isset($errors[HTTP_INTERNAL_ERROR])) {
    echo '<h2>HTTP Internal error (' . count($errors[HTTP_INTERNAL_ERROR]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>URL</th>
  </tr>';

    foreach ($errors[HTTP_INTERNAL_ERROR] as $infos) {
        echo '<tr>
        <td>' . $entity_names[$infos[0]] . '</td>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_urls[$infos[0]] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}

if (isset($errors[HTTP_NOT_FOUND])) {
    echo '<h2>HTTP 404 Not Found (' . count($errors[HTTP_NOT_FOUND]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>URL</th>
  </tr>';

    foreach ($errors[HTTP_NOT_FOUND] as $infos) {
        echo '<tr>
        <td>' . $entity_names[$infos[0]] . '</td>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_urls[$infos[0]] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}

if (isset($errors[HTTP_MOVED])) {
    echo '<h2>HTTP Moved files (' . count($errors[HTTP_MOVED]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>Redirected to</th>
  </tr>';

    foreach ($errors[HTTP_MOVED] as $infos) {
        echo '<tr>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_names[$infos[0]] . '</a></td>
        <td><a href="' . $infos[1] . '">' . $infos[1] . '</a></td>
       </tr>';
    }
    
    echo '</table>';
}

if (isset($errors[HTTP_WRONG_HEADER])) {
    echo '<h2>HTTP Error (' . count($errors[HTTP_WRONG_HEADER]) . ')</h2>' .
'<table>
  <tr class="blue">
    <th>Entity Name</th>
    <th>Unreconized header</th>
  </tr>';

    foreach ($errors[HTTP_WRONG_HEADER] as $infos) {
        echo '<tr>
        <td><a href="' . $entity_urls[$infos[0]] . '">' . $entity_names[$infos[0]] . '</a></td>
        <td><a href="' . $infos[1] . '">' . $infos[1] . '</a></td>
       </tr>';
    }
    echo '</table>';
}

echo '<?php echo site_footer(); ?>';

?>