<?php
/**
 * Fax Server SMS Module Member
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
$ignoreAuth = 1;
require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Common\Crypto\CryptoGen;

class FaxServer
{
    private $baseDir;
    private $crypto;
    private $authToken;

    public function __construct()
    {
        $this->baseDir = $GLOBALS['temporary_files_dir'];
        $this->cacheDir = $GLOBALS['OE_SITE_DIR'] . '/documents/logs_and_misc/_cache';
        $this->serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $this->crypto = new CryptoGen();
        $this->getCredentials();
        $this->verify();
        $this->dispatchActions();
    }

    private function dispatchActions()
    {
        $action = $_GET['_FAX'];

        if ($action) {
            if (method_exists($this, $action)) {
                call_user_func(array($this, $action), array());
            } else {
                http_response_code(404);
            }
        } else {
            http_response_code(401);
        }

        exit;
    }

    private function serveFax()
    {
        $file = $_GET['file'];
        $FAX_FILE = $this->baseDir . '/send/' . $file;
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Content-Type: application/pdf");
        header("Content-Length: " . filesize($FAX_FILE));
        header("Content-Disposition: attachment; filename=" . basename($FAX_FILE));
        header("Content-Description: File Transfer");

        if (is_file($FAX_FILE)) {
            $chunkSize = 1024 * 1024;
            $handle = fopen($FAX_FILE, 'rb');
            while (!feof($handle)) {
                $buffer = fread($handle, $chunkSize);
                echo $buffer;
                ob_flush();
                flush();
            }
            fclose($handle);
        } else {
            error_log(errorLogEscape("Serve File Not Found " . $FAX_FILE));
            http_response_code(404);
            exit;
        }
        unlink($FAX_FILE);
        exit;
    }

    private function getCredentials()
    {
        if (!file_exists($this->cacheDir . '/_credentials_twilio.php')) {
            http_response_code(404);
            exit;
        }
        $credentials = file_get_contents($this->cacheDir . '/_credentials_twilio.php');
        $credentials = json_decode($this->crypto->decryptStandard($credentials), true);
        $this->authToken = $credentials['password'];
        unset($credentials);

        return;
    }

    // verify request signature from twilio
    private function verify($file = null)
    {
        $url = $this->serverUrl . $_SERVER['REQUEST_URI'];
        $me = $this->computeSignature($url, $_POST);
        $them = $_SERVER["HTTP_X_TWILIO_SIGNATURE"];
        $agree = $me === $them;
        if ($agree) {
            return $agree;
        } else {
            error_log(errorLogEscape("Failed request verification me: " . $me . ' them: ' . $them));
            http_response_code(401);
            exit;
        }
    }

    private function computeSignature($url, $data = array())
    {
        ksort($data);
        foreach ($data as $key => $value) {
            $url = $url . $key . $value;
        }
        // calculates the HMAC hash of the data with the key of authToken
        $hmac = hash_hmac("sha1", $url, $this->authToken, true);
        return base64_encode($hmac);
    }

    protected function faxCallback()
    {
        $file_path = $_POST['OriginalMediaUrl'];
        ['basename' => $basename, 'dirname' => $dirname] = pathinfo($file_path);
        $file = $this->baseDir . '/send/' . $basename;
        // they own it now so throw away.
        unlink($file);
        http_response_code(200);
        exit;
    }

    protected function receivedFax()
    {
        $dispose_uri = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-faxsms/faxserver/receiveContent';
        $twimlResponse = new SimpleXMLElement("<Response></Response>");
        $receiveEl = $twimlResponse->addChild('Receive');
        $receiveEl->addAttribute('action', $dispose_uri);
        header('Content-type: text/xml');
        echo $twimlResponse->asXML();
        exit;
    }

    protected function receiveContent()
    {
        // Throw away content. we'll manage on their server.
        $file = $_POST["MediaUrl"];
        header('Content-type: text/xml');
        http_response_code(200);
        echo '';
        exit;
    }
}
