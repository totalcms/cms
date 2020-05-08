<?php
namespace Dynamics;

//---------------------------------------------------------------------------------
// PASSPORT
//---------------------------------------------------------------------------------
class Passport
{
    private $cms_dir;
    private $logfile;
    private $tfile;
    private $kfile;
    private $pfile;
    private $ifile;
    private $efile;
    private $lfile;

    const INTERIM_ALLOWED = 'Past allowed timeframe for interim passport. '
        .'Please verify that your server can properly communicate to the passport server.';
    const INTERIM_CREATE  = 'Unable to create passport interim.';
    const PASSPORT_CREATE = 'Unable to create passport.';
    const FAILURE         = 'Passport check failed. You will need to register a passport to use TotalCMS further.';
    const SUCCESS         = 'Passport check succeeded';
    const EASYERROR       = 'Total CMS content must be removed from tcms-data if you want to use Easy CMS.'
        .'Checking for valid Total CMS license...';
    const PASSPORT_URL    = 'https://passport.joeworkman.net/total-cms/';
    const SALT            = 'T0talCMSR0cks!';
    const KFILE           = 'total-key.php';
    const TFILE           = 'trial.total';
    const PFILE           = 'license.total';
    const IFILE           = 'interim.check';
    const EFILE           = 'passport.easy';
    const MAXP            = 1;
    const MAXI            = 10;

    //-----------------------------------------------------------
    // Public Methods
    //-----------------------------------------------------------
    public function __construct($cms_dir = null, $logfile = null)
    {
        $site_root = preg_replace('/(.*)\/rw_common.+/', '$1', __DIR__);
        $default_dir = "$site_root/tcms-data";

        $this->cms_dir = isset($cms_dir) ? $cms_dir : $default_dir;
        $this->logfile = isset($logfile) ? $logfile : "$this->cms_dir/cms.log";
        $this->tfile = dirname(__DIR__).DIRECTORY_SEPARATOR.self::TFILE;
        $this->pfile = dirname(__DIR__).DIRECTORY_SEPARATOR.self::PFILE;
        $this->ifile = dirname(__DIR__).DIRECTORY_SEPARATOR.self::IFILE;
        $this->kfile = dirname(__DIR__).DIRECTORY_SEPARATOR.self::KFILE;
        $this->lfile = $this->cms_dir.DIRECTORY_SEPARATOR.self::PFILE;
        $this->efile = dirname(dirname(__DIR__)).DIRECTORY_SEPARATOR.self::EFILE;
    }

    public function check($data = null)
    {
        if (file_exists($this->efile)) {
            if ($this->checkEasy() === true) {
                return true;
            } else {
                $this->logMessage(self::EASYERROR);
            }
        }
        return $this->checkTotal($data);
    }

    public function checkTotalExists()
    {
        $this->check();
        return file_exists($this->pfile);
    }

    public function returnError($msg)
    {
        $msg = trim(strip_tags($msg));
        $this->logMessage($msg);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $post = json_encode($_POST);
            $json = json_encode(array(
                'code'    => 500,
                'message' => $msg,
                'post'    => $post
            ));
            // $this->logMessage("POST INFO: ".$post);

            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json');
            die($json);
        }
        die($msg);
    }

    public function returnSuccess($msg)
    {
        $msg = trim(strip_tags($msg));
        $this->logMessage($msg);
        return true;
    }

    //-----------------------------------------------------------
    // Private Methods
    //-----------------------------------------------------------
    private function checkEasy()
    {
        return $this->totalCount() > 0 ? false : true;
    }

    private function checkTotal($data = null)
    {
        if (file_exists($this->lfile)) {
            if (file_get_contents($this->lfile) === $this->lverify()) {
                return true;
            }
        }
        $passportAge = $this->passportAge();
        if ($passportAge === false || $passportAge > self::MAXP || isset($data)) {
            $verify = isset($data) && $data->type === 'passport' ? $data : $this->verify();
            if ($verify === false) {
                $this->interimCheck();
            } elseif ($verify->status == true) {
                $this->logMessage($verify->info);
                $this->cancelInterim();
            } else {
                $this->logMessage($verify->info);
                $this->returnError(self::FAILURE);
            }

            if ($this->createPassport() === false) {
                $this->returnError(self::PASSPORT_CREATE);
            }
            if (!empty($verify->trial)) {
                $this->createTrial();
            }
            $this->returnSuccess(self::SUCCESS);
        }
        $this->clearEasyPassport();
        return true;
    }

    private function lverify()
    {
        $encode = base64_encode($_SERVER["HTTP_HOST"]).base64_encode(self::SALT);
        return $encode.md5($encode);
    }

    private function countElement($type, $recursive = false)
    {
        $dir = "$this->cms_dir/$type";
        if (file_exists($dir)) {
            if ($recursive) {
                $rd = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
                $fi = new \RecursiveIteratorIterator($rd);
                return iterator_count($fi);
            }
            $fi = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
            return iterator_count($fi);
        }
        return 0;
    }

    private function totalCount()
    {
        $total = 0;

        $multi_types = array('feed','blog','gallery','depot');
        foreach ($multi_types as $multi_type) {
            $total += $this->countElement($multi_type, true);
        }
        // $this->logMessage("total count: $total");

        // Minus Hipwig for Easy CMS
        $total -= $this->countElement("gallery/hipwig", true);
        $total -= $this->countElement("depot/hipwig", true);

        $types = array('file');
        foreach ($types as $type) {
            $total += $this->countElement($type);
        }
        // $this->logMessage("total count: $total");

        return $total;
    }

    public function verify()
    {
        $this->cancelTrial();
        $ch = curl_init();
        $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
        curl_setopt($ch, CURLOPT_URL, self::PASSPORT_URL.$domain);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $contents = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            $this->logMessage("Passport request error for $domain: (http code:".$httpCode.") ".curl_error($ch));
            $this->logMessage("Passport request contents: ".$contents);
            return false;
        }
        curl_close($ch);
        // $this->logMessage('PASSPORT DEBUG: '.$contents);
        $json = json_decode($contents);
        $this->apikeySave($json->passport);
        unset($json->passport);
        return $json;
    }

    public function logMessage($message)
    {
        $logline = date(DATE_RFC2822)." $message\n";
        error_log($logline, 3, $this->logfile);
    }

    private function age($file)
    {
        $oneday = 60*60*24;
        if (file_exists($file)) {
            return (time()-filemtime($file))/$oneday;
        }
        return false;
    }

    private function passportAge()
    {
        return $this->age($this->pfile);
    }

    private function interimCheck()
    {
        $interimAge = $this->interimAge();
        if ($interimAge !== false && $interimAge > self::MAXI) {
            $this->returnError(self::INTERIM_ALLOWED);
        }
        if ($this->createInterim() === false) {
            $this->returnError(self::INTERIM_CREATE);
        }
        return file_exists($this->ifile);
    }

    private function interimAge()
    {
        return $this->age($this->ifile);
    }

    private function createPassport()
    {
        return file_put_contents($this->pfile, date(DATE_RFC2822));
    }

    private function createInterim()
    {
        $this->logMessage("WARNING: Unable to contact Passport server. Generating provisional license.");
        return file_put_contents($this->ifile, date(DATE_RFC2822));
    }

    private function cancelInterim()
    {
        if (file_exists($this->ifile)) {
            unlink($this->ifile);
        }
        if (file_exists($this->pfile)) {
            unlink($this->pfile);
        }
        return true;
    }

    public function inTrial()
    {
        return file_exists($this->tfile)||file_exists($this->efile)||file_exists($this->ifile);
    }

    private function createTrial()
    {
        return file_put_contents($this->tfile, date(DATE_RFC2822));
    }

    private function cancelTrial()
    {
        if (file_exists($this->tfile)) {
            unlink($this->tfile);
        }
        if (file_exists($this->pfile)) {
            unlink($this->pfile);
        }
        return true;
    }

    private function apikeySave($key)
    {
        return file_put_contents($this->kfile, '<?php const TOTALKEY = "'.trim($key).'";');
    }

    public function apikey()
    {
        if (file_exists($this->kfile)) {
            include_once $this->kfile;
            return TOTALKEY;
        }
        return null;
    }

    private function clearEasyPassport()
    {
        if (file_exists($this->efile)) {
            return unlink($this->efile);
        }
        return true;
    }
}
