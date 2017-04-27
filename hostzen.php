<?php

/**
 * HostZen
 *
 * @version 1.0.0
 * @license MIT
 */

/**
 * Report all errors except E_NOTICE
 */
error_reporting(E_ALL & ~E_NOTICE);

/**
 * Don't display errors
 */
ini_set('display_errors', false);

class HostCheck implements Countable, Iterator {
    const DEBUG = false;
    const EXCLUDED_TEST = '';

    const TEST_PHP_VERSION = '7.1.1';
    const TEST_MAX_EXECUTION_TIME = 30;
    const TEST_MEMORY_LIMIT = 64;
    const TEST_POST_SIZE = 16;
    const TEST_MAX_UPLOAD = 16;
    const TEST_EMAIL_TO = 'support@zenelements.com';
    const TEST_EMAIL_FROM = 'noreply@zenelements.com';
    const TEST_REWRITE_URL = 'test_rewrite_url';
    const TEST_DOMAIN_URL = 'test_domain_url';

    /**
     * Test the "Database connection" (MySQL Like)
     */
    public function databaseConnection() {
        $response = array(
            'id' => 'dabaseconnection',
            'title' => 'Database Connection Test',
            'label' => '',
            'status' => '',
            'fields' => array()
        );

        if (array_key_exists('dbconnect', $_POST)) {
            try {
                $dbhost = strip_tags(trim($_POST['dbhost']));
                $dbuser = strip_tags(trim($_POST['dbuser']));
                $dbpass = strip_tags(trim($_POST['dbpass']));
                $dbname = strip_tags(trim($_POST['dbname']));

                $response['fields']['dbhost'] = array(
                    'status' => empty($_POST['dbhost']) ? 'error' : '',
                    'message' => empty($_POST['dbhost']) ? 'You must enter the Hostname' : '',
                    'value' => $dbhost
                );

                $response['fields']['dbuser'] = array(
                    'status' => empty($dbuser) ? 'error' : '',
                    'message' => empty($dbuser) ? 'You must enter the Username' : '',
                    'value' => $dbuser
                );

                $response['fields']['dbpass'] = array(
                    'status' => empty($dbpass) ? 'error' : '',
                    'message' => empty($dbpass) ? 'You must enter the Password' : '',
                    'value' => $dbpass
                );

                $response['fields']['dbname'] = array(
                    'status' => empty($dbname) ? 'error' : '',
                    'message' => empty($dbname) ? 'You must enter the Database' : '',
                    'value' => $dbname
                );

                if (empty($_POST['dbhost']) || empty($_POST['dbuser']) || empty($_POST['dbpass']) || empty($_POST['dbname'])) {
                    throw new HostCheckException('All fields are required.');
                }

                new PDO(sprintf('mysql:host=%s;dbname=%s', $dbhost, $dbname), $dbuser, $dbpass );
                $response['status'] = 'success';
            }

            catch(HostCheckException $e) {
                $response['status'] = 'danger';
                $response['message'] = $e->getMessage();
            }

            catch(PDOException $e) {
                $response['status'] = 'danger';
                $response['message'] = $e->getMessage();
            }
        }

        return $response;
    }

    /**
     *  Test the "Email" is working
     */
    public function sendEmail() {
        $response = array(
            'id'    => 'send_email',
            'title' => 'Mail',
            'label' => 'Test if we can send email with PHP',
        );

        if (array_key_exists('sendemail', $_POST)) {
            try {
                $to = strip_tags(trim($_POST['emailto']));
                $headers = sprintf("From: %s\r\nReply-To:%s\r\nX-Mailer: PHP/%s",
                    $this->options['mail_from'],
                    $this->options['mail_from'],
                    phpversion());
                $subject = 'Emulsion Check - Test email using PHP';
                $message = 'This is a test email message send at ' . date('c');

                $response['fields']['emailto'] = array(
                    'status' => empty($_POST['emailto']) ? 'error' : '',
                    'message' => empty($_POST['emailto']) ? 'You must enter a valid email' : '',
                    'value' => $to ?: $this->options['mail_to']
                );

                if (empty($to)) {
                    throw new HostCheckException('A valid Email is required.');
                }

                $sent = mail($to, $subject, $message, $headers, '-f'.$this->options['mail_from']);

                $response = array_merge($response, array(
                    'value' => $sent ? 'Yes':'No',
                    'message' => $sent
                        ? 'The test seems to be sent successfully. Check your mail client.'
                        : 'Unable to send the test email.',
                    'status' => $sent ? 'success' : 'danger',
                ));
            }
            catch(HostCheckException $e) {
                $response['status'] = 'danger';
                $response['message'] = $e->getMessage();
            }
        }

        return $response;
    }

    /**
     *  Test the "PHP Version"
     */
    public function testPhpVersion() {
        $sVersion = $this->options['php_version'];

        list($iMajor, $iMinor, $iBuild) = explode('.', $sVersion);
        $iVersion = $iMajor*10000 + $iMinor*100 + $iBuild;

        return array(
            'id' => 'php_version',
            'title' => 'PHP Version',
            'label' => '',
            'value' => PHP_VERSION,
            'message' => sprintf(
                'Your version of PHP is <strong>%s</strong>, we need at least <strong>%s</strong>',
                PHP_VERSION, $sVersion
            ),
            'status' => (PHP_VERSION_ID < $iVersion ) ? 'danger' : 'success',
        );
    }

    /**
     * Test if we have "FQDN / Loopback Domain"
     */
    public function testDomain() {
        if (isset($_GET['url']) && $_GET['url']) {
            $userUrl = parse_url(strip_tags(htmlentities($_GET['url'])));
            $this->json([
                'from_url' => $userUrl['host'],
                'from_host' => $_SERVER['HTTP_HOST'],
                'state' => $_SERVER['HTTP_HOST'] == $userUrl['host']
                    ? 'success' : 'danger'
            ]);
        }

        return array(
            'id' => 'domain',
            'title' => 'Domain',
            'label' => '',
            'value' => "?",
            'message' => 'Please wait, we are testing your domain configuration.',
        );
    }

    /**
     * Test if "Url Rewriting" is enable and working
     */
    public function testUrlRewriting() {
        if (trim($_SERVER['REQUEST_URI'], '/') == $this->options['rewrite_url']) {
            exit(0);
        }

        $curlHandler = null;
        $curlUrl = null;
        $errorMessage = null;

        try {
            $hostNames = [gethostname(), $this->getCalledHost(), 'localhost'];

            $curlHandler = curl_init();
            curl_setopt_array($curlHandler, $this->options['curl']);

            if (!$curlHandler) {
                throw new HostDogException('Unable to initialize cUrl');
            }

            $curlSuccess = false; $infos=[];
            foreach ($hostNames as $host) {
                $curlUrl = sprintf('http://%s/%s', $host, $this->options['rewrite_url']);

                curl_setopt($curlHandler, CURLOPT_URL, $curlUrl);
                curl_exec($curlHandler);

                if (curl_errno($curlHandler)) {
                    $infos = curl_getinfo($curlHandler);

                    if (isset($infos['http_code']) && $infos['http_code'] == 200) {
                        $curlSuccess = true;
                        break;
                    }
                }
            }

            if ($curlSuccess) {
                throw new HostDogException(sprintf(
                    '<strong>Oups</strong> We cannot reach the right page with Url rewriting.<pre>%s</pre>',
                    print_r($infos, true)
                ));
            }

        }

        catch(HostDogException $e) {
            $errorMessage = '<strong>Error :</strong> ' . $e->getMessage();
        }

        finally {
            is_null($curlHandler) or curl_close($curlHandler);
        }

        return [
            'id' => 'url_rewriting',
            'title' => 'Url Rewriting',
            'label' => 'We need to rewrite URL to redirect call to index.php.',
            'value' => $errorMessage ? 'No' : 'Yes',
            'status' =>  $errorMessage ? 'danger' : 'success',
            'message' => $errorMessage ?: sprintf(
                "<strong>Yeah.</strong> We have reached the page below during the test.<pre>%s</pre>",
                $curlUrl
            ),
        ];
    }

    /**
     * Test if the Web user as write access on forder and files
     *
     * @return array
     */
    public function testWriteAccess() {
        $state = null;
        $dirBase = dirname(__FILE__);
        $dirTest = 'files';
        $dirPath = $dirBase . '/' . $dirTest;
        $fileTest = 'testfile_'.time().'.tmp';
        $filePath = $dirPath . '/' . $fileTest;

        $currentUser = get_current_user();
        $dirIterator = new DirectoryIterator($dirBase);
        $dirInformations = posix_getpwuid($dirIterator->getOwner());

        $messages = [
            sprintf('You must create a directory <code>%s</code>.', $dirTest),
            sprintf('And give write access to the user <code>%s</code> on this folder.', $currentUser)
        ];

        if (file_exists($dirPath)) {
            $messages[] = sprintf('The directory <strong>%s</strong> already exist.', $dirTest);
        } elseif (mkdir($dirPath)) {
            $messages[] = sprintf('The directory <strong>%s</strong> has created successfully.', $dirTest);
        } else {
            $messages[] = sprintf('<strong>Error</strong>. Unable to create the directory <strong>%s</strong>.', $dirTest);
            $state = 'danger';
        }

        if (!$state) {
            if (file_exists($filePath)) {
                $messages[] = sprintf('The file <strong>%s</strong> already exist.', $fileTest);
            } elseif (file_put_contents($filePath, 'Test Content')) {
                $messages[] = sprintf('The file <strong>%s</strong> has created successfully.', $fileTest);
            } else {
                $messages[] = sprintf('<strong>Error</strong>. Unable to create the file <strong>%s</strong>.', $fileTest);
                $state = 'danger';
            }
        }

        return [
            'id' => 'write_access',
            'title' => 'Write access',
            'label' => '',
            'value' => $currentUser,
            'status' => $state ?: 'success',
            'message' => implode('<br>', $messages),
            'content' => sprintf("<pre>%s</pre>", print_r($dirInformations, true)),
        ];
    }

    /**
     *  Test PHP parameter "memory_limit"
     */
    public function testMemoryLimit() {
        $limit = ini_get('memory_limit');

        return array(
            'id' => 'memory_limit',
            'title' => 'Memory Limit',
            'label' => '',
            'value' => $limit,
            'message' => sprintf(
                'Your memory limit is <strong>%d Mb</strong>, we need at least <strong>%d Mb</strong>.',
                $limit, $this->options['memory_limit']
            ),
            'status' => ($limit < $this->options['memory_limit']) ? 'danger' : 'success',
        );
    }

    /**
     *  Test PHP parameter "post_max_size"
     */
    public function testPostMaxSize() {
        $minLimit = $this->options['post_size'];

        $limit = ini_get('post_max_size');

        return array(
            'id' => 'post_max_size',
            'title' => 'Post Max Size',
            'label' => '',
            'value' => $limit,
            'message' => sprintf(
                'The max quantity to POST is <strong>%d Mb</strong>, we need at least <strong>%d Mb</strong>.',
                $limit, $minLimit
            ),
            'status' => ($limit < $minLimit) ? 'danger' : 'success',
        );
    }

    /**
     *  Test PHP parameter "post_max_size"
     */
    public function testMaxUploadSize() {
        $minLimit = $this->options['upload_size'];

        $limit = ini_get('upload_max_filesize');

        return array(
            'id' => 'upload_max_filesize',
            'title' => 'Max Upload File Size',
            'label' => '',
            'value' => $limit,
            'message' => sprintf(
                'The max size for send file is <strong>%d Mb</strong>, we need at least <strong>%d Mb</strong>.',
                $limit, $minLimit
            ),
            'status' => ($limit < $minLimit) ? 'danger' : 'success',
        );
    }

    /**
     *  Test PHP parameter "max_execution_time"
     */
    public function testExecutionTime() {
        $minLimit = $this->options['execution_time'];

        $limit = ini_get('max_execution_time');

        return array(
            'id' => 'max_execution_time',
            'title' => 'Max Execution Time',
            'label' => '',
            'value' => $limit,
            'message' => sprintf(
                'Your limit is <strong>%d sec</strong>, we need at least <strong>%d sec</strong>.',
                $limit, $minLimit
            ),
            'status' => ($limit < $minLimit) ? 'danger' : 'success',
        );
    }

    /**
     * Test the presence of GD
     */
    public function testGD() {
        $response = array(
            'id' => 'gd',
            'title' => 'GD (Graphic Library)',
            'label' => 'Test if the GD module is present',
            'value' => 'No',
            'status' => 'danger'
        );

        if ( false !== ($isAvailable = extension_loaded('gd')) ) {
            $gdInfos = gd_info();

            $response['value'] = $gdInfos['GD Version'];
            $response['message'] = sprintf('Your version of <strong>GD</strong> module is <strong>%s</strong>', $gdInfos['GD Version']);
            $response['status'] = 'success';
        }

        return $response;
    }

    /**
     *  Test the function "phpInfo"
     */
    public function testPhpInfo() {
        $isAvailable = function_exists('phpinfo');

        function getPhpInfos() {
            ob_start();
            phpinfo();
            $data = ob_get_contents();
            ob_end_clean();

            preg_match_all("=<body[^>]*>(.*)</body>=siU", $data, $matches);
            return $matches[1][0];
        }

        return array(
            'id' => 'php_info',
            'title' => 'PHP Info',
            'label' => 'Test if the function phpInfo is available',
            'value' => $isAvailable ? 'Yes' : 'No',
            'message' => 'The "phpInfo" function ' . ($isAvailable ? 'is available' : 'is\'nt available'),
            'status' => $isAvailable ? 'success' : 'danger',
            'content' => getPhpInfos(),
        );
    }

    /**
     * Get Server informations (not really a test)
     */
    public function testServerInfos() {
        $sortedServer = $_SERVER;
        ksort($sortedServer);

        $ipServer = gethostbyname($_SERVER['HTTP_HOST']);
        ($ipServer != $_SERVER['SERVER_NAME']) or $ipServer = '???';
        $ipHost = gethostbyname(gethostname());

        $serverInfos = array(
            'Hostname : <strong>' . gethostname() . '</strong>',
            'Ip / Host from SERVER_NAME : <strong>' . sprintf("%s - %s", $ipServer, gethostbyaddr($ipServer) ?: '???') . '</strong>',
            'Ip / Host from gethostname() : <strong>' . sprintf("%s - %s", $ipHost, gethostbyaddr($ipHost) ?: '???') . '</strong>',
            'Uname : <strong>' .php_uname() . '</strong>',
        );

        return array(
            'id' => 'server_info',
            'title' => 'Server Info',
            'label' => 'Retrieve informations about the server',
            'value' => 'Info',
            'status' => 'info',
            'content' => sprintf('<ul>%s</ul><pre>%s</pre>',
                implode("\n", array_map(function($row) {
                    return sprintf('<li>%s</li>', $row);
                }, $serverInfos)),
                print_r($_SERVER, true)),
        );
    }

    // -----------------------------------------------------------------

    protected static $instance = null;

    protected $testResults = array();
    protected $startTime = 0.0;
    protected $stepTime = 0.0;

    protected $options= array(
        'excluded' => self::EXCLUDED_TEST,
        'php_version' => self::TEST_PHP_VERSION,
        'rewrite_url' => self::TEST_REWRITE_URL,
        'domain_url' => self::TEST_DOMAIN_URL,
        'mail_from' => self::TEST_EMAIL_FROM,
        'mail_to' => self::TEST_EMAIL_TO,
        'memory_limit' => self::TEST_MEMORY_LIMIT,
        'post_size' => self::TEST_POST_SIZE,
        'upload_size' => self::TEST_MAX_UPLOAD,
        'execution_time' => self::TEST_MAX_EXECUTION_TIME,

        'curl' => array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 3
        ),
    );

    // -----------------------------------------------------------------

    protected function __construct() {
        $this->init();
    }

    protected function init() {
        $app = $this;
        $dotEnv = __DIR__ . '/.env';

        if (file_exists($dotEnv)) {
            array_map(function($line) use ($app) {
                if (false !== ($commentStart = strpos($line, '#'))) {
                    $line = substr($line, 0, $commentStart);
                }

                if (trim($line) && strpos($line, '=')) {
                    list($key, $value) = explode('=', strtolower($line));
                    $this->options[ trim($key) ] = trim($value);
                }
            }, file($dotEnv));
        }

        $this->stopTime();
    }

    /**
     * Initialize and get the app
     *
     * @return HostCheck|null
     */
    public static function factory() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Start the App
     *
     * @return $this
     */
    public function run() {
        if (array_key_exists('download', $_REQUEST)) {
            $this->download();
        }

        $app = $this;
        $app->testResults = array_map(function($method) use ($app) {
            return call_user_func(array($app, $method))
                + ['time' =>  $app::DEBUG ? $app->stopTime() : null];
        }, array_filter(get_class_methods(__CLASS__), function($method) use ($app) {
            if (strpos($method, 'test') === 0) {
                return !$app->isExcluded(substr($method, 4));
            }
            return false;
        }));

        return $this;
    }

    /**
     * Timer for debug
     *
     * @return string
     */
    public function stopTime() {
        $current = microtime(true);

        if (!$this->startTime) {
            $this->startTime = $current;
            $this->stepTime = $this->startTime;
        }

        $stop = $current - $this->startTime;
        $step = $current - $this->stepTime;
        $this->stepTime = $current;

        return sprintf('%s (%s)',
            number_format($step , 3),
            number_format($stop, 3)
        );
    }

    /**
     * Return the current script for download
     */
    public function download() {
        header('Content-disposition: attachment; filename=index.php');
        header('Content-type: text/html');
        readfile(__FILE__); exit();
    }

    /**
     * Get the current script name (use to construct URL)
     *
     * @return string
     */
    public function scriptName() {
        return basename(__FILE__);
    }

    /**
     * Return JSON data for XHR calls
     *
     * @param array $response
     */
    public function json($response=array()) {
        header('Content-Type:application/json;charset=utf-8');
        echo json_encode($response); exit();
    }

    public function getCalledHost() {
        $possibleHostSources = array('HTTP_X_FORWARDED_HOST', 'SERVER_NAME', 'HTTP_HOST', 'SERVER_ADDR');
        $sourceTransformations = array(
            "HTTP_X_FORWARDED_HOST" => function($value) {
                $elements = explode(',', $value);
                return trim(end($elements));
            },
            "SERVER_ADDR" => function($value) {
                return gethostbyaddr($value);
            }
        );

        $host = null;

        foreach ($possibleHostSources as $source) {
            if (isset($_SERVER[$source]) && $_SERVER[$source]) {
                $host = array_key_exists($source, $sourceTransformations)
                    ? $sourceTransformations[$source]($_SERVER[$source])
                    : $_SERVER[$source];
                break;
            }
        }

        return $host ? trim(preg_replace('/:\d+$/', '', $host)) : 'localhost';
    }

    /**
     * Is the given test is excluded from the list
     *
     * @param string $testName
     * @return bool
     */
    public function isExcluded($testName='') {
        return ($this->options['excluded'] === 'all')
            || (false !== strpos($this->options['excluded'], strtolower($testName)));
    }

    public function count() {
        return count($this->testResults);
    }

    public function rewind() {
        reset($this->testResults);
    }

    public function current() {
        return current($this->testResults);
    }

    public function key() {
        return key($this->testResults);
    }

    public function next() {
        return next($this->testResults);
    }

    public function valid() {
        $key = key($this->testResults);
        return ($key !== NULL && $key !== FALSE);
    }

    public function countError() {
        return array_reduce($this->testResults, function($countErrors, $testResult) {
            return ( ($testResult['status'] == 'danger') ? 1:0 ) + $countErrors;
        });
    }

    public function getIcon($status) {
        $icons = array('success' => 'ok', 'error' => 'remove', 'danger' => 'remove');
        return $icons[$status] ?: 'cloud';
    }
}

class HostCheckException extends Exception {}

// ====================================================================================================================

$app = HostCheck::factory()->run();
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>HostZen - Test your hosting for your zenelements project</title>

        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" crossorigin="anonymous">
    </head>
    <body>
        <div class="container">
            <div class="page-header">

                <?php if (0 < ($countErrors = $app->countError())): ?><button class="btn btn-lg btn-danger pull-right">
                    <?php if ($countErrors < 2) { printf('One problem found.'); } else { printf('%d problems found !', $countErrors); } ?>
                </button>
                <?php endif; ?>

                <h1>HostZen</h1>
                <p class="lead">Test your web hosting configuration.</p>
            </div>

            <div class="panel-group" id="accordion" role="tablist" aria-multiselectable="true">

                <?php if (!$app->isExcluded('database')): $testDbConnection = $app->databaseConnection(); ?>
                <div class="panel panel-<?php echo $testDbConnection['status'] ?: 'default' ?>">
                    <div class="panel-heading" role="button" data-toggle="collapse" data-parent="#accordion" data-target="#panel-<?php echo $testDbConnection['id'] ?>">
                        <span class="badge pull-right"><?php echo $testDbConnection['value'] ?: $testDbConnection['status'] ?></span>
                        <h2 class="panel-title"><?php echo $testDbConnection['title'] ?></h2>
                    </div>

                    <div class="panel-collapse collapse <?php echo $testDbConnection['status']=='success' ? '':'in' ?>" id="panel-<?php echo $testDbConnection['id'] ?>">
                        <div class="panel-body">
                            <?php if ($testDbConnection['message']): ?><p class="text-<?php echo $testDbConnection['status'] ?>"><?php echo $testDbConnection['message'] ?></p><hr />
                            <?php elseif (!$testDbConnection['status']): ?><p>Please, fill the form with your database informations to validate the connection.</p><?php endif ?>

                            <form class="form-horizontal" method="POST">

                                <div class="row">

                                    <div class="form-group col-xs-6 has-feedback<?php if($testDbConnection['fields']['dbhost']['status']) echo ' has-'.$testDbConnection['fields']['dbhost']['status'] ?>">
                                        <label for="dbhost" class="col-sm-2 control-label">Host</label>
                                        <div class="col-sm-10">
                                            <input type="text" id="dbhost" name="dbhost" class="form-control" placeholder="<?php echo $testDbConnection['fields']['dbhost']['message'] ? $testDbConnection['fields']['dbhost']['message'] : 'Host name'; ?>" value="<?php echo $testDbConnection['fields']['dbhost']['value'] ?>">
                                            <?php if ($testDbConnection['fields']['dbhost']['status'] == 'error'): ?><span class="glyphicon glyphicon-remove form-control-feedback" aria-hidden="true"></span><?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="form-group col-xs-6 has-feedback<?php if($testDbConnection['fields']['dbuser']['status']) echo ' has-'.$testDbConnection['fields']['dbuser']['status'] ?>">
                                        <label for="dbuser" class="col-sm-2 control-label">User</label>
                                        <div class="col-sm-10">
                                            <input type="text" id="dbuser" name="dbuser" class="form-control" placeholder="<?php echo $testDbConnection['fields']['dbuser']['message'] ? $testDbConnection['fields']['dbuser']['message'] : 'User name'; ?>" value="<?php echo $testDbConnection['fields']['dbuser']['value'] ?>">
                                            <?php if ($testDbConnection['fields']['dbuser']['status'] == 'error'): ?><span class="glyphicon glyphicon-remove form-control-feedback" aria-hidden="true"></span><?php endif; ?>
                                        </div>
                                    </div>

                                </div>

                                <div class="row">

                                    <div class="form-group col-xs-6 has-feedback<?php if ($testDbConnection['fields']['dbpass']['status']) echo ' has-'.$testDbConnection['fields']['dbpass']['status'] ?>">
                                        <label for="dbpass" class="col-sm-2 control-label">Password</label>
                                        <div class="col-sm-10">
                                            <input type="text" id="dbpass" name="dbpass" class="form-control" placeholder="<?php echo $testDbConnection['fields']['dbpass']['message'] ? $testDbConnection['fields']['dbpass']['message'] : 'Password'; ?>" value="<?php echo $testDbConnection['fields']['dbpass']['value'] ?>">
                                            <?php if ($testDbConnection['fields']['dbpass']['status'] == 'error'): ?><span class="glyphicon glyphicon-remove form-control-feedback" aria-hidden="true"></span><?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="form-group col-xs-6 has-feedback<?php if ($testDbConnection['fields']['dbname']['status']) echo ' has-'.$testDbConnection['fields']['dbname']['status'] ?>">
                                        <label for="dbname" class="col-sm-2 control-label">Database</label>
                                        <div class="col-sm-10">
                                            <input type="text" id="dbname" name="dbname" class="form-control" placeholder="<?php echo $testDbConnection['fields']['dbname']['message'] ? $testDbConnection['fields']['dbname']['message'] : 'Database name'; ?>" value="<?php echo $testDbConnection['fields']['dbname']['value'] ?>">
                                            <?php if ($testDbConnection['fields']['dbname']['status'] == 'error'): ?><span class="glyphicon glyphicon-remove form-control-feedback" aria-hidden="true"></span><?php endif; ?>
                                        </div>
                                    </div>

                                </div>

                                <div class="form-group">
                                    <div class="col-sm-offset-1 col-sm-10">
                                        <button type="submit" name="dbconnect" class="btn btn-primary"<?php if($testDbConnection['status'] == 'success') echo 'disabled="disabled"' ?>>Test your connection</button>
                                    </div>
                                </div>

                            </form>

                            <?php if (isset($testDbConnection['time'])): ?><p class="small">Time: <?php echo $testDbConnection['time'] ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$app->isExcluded('mail')): $testSendEmail = $app->sendEmail(); ?>
                <div class="panel panel-<?php echo $testSendEmail['status'] ?: 'default' ?>">
                    <div class="panel-heading" role="button" data-toggle="collapse" data-parent="#accordion" data-target="#panel-<?php echo $testSendEmail['id'] ?>">
                        <span class="badge pull-right"><?php echo $testSendEmail['value'] ?: $testSendEmail['status'] ?></span>
                        <h2 class="panel-title"><?php echo $testSendEmail['title'] ?></h2>
                    </div>

                    <div class="panel-collapse collapse <?php echo $testSendEmail['status']=='success' ? '':'in' ?>" id="panel-<?php echo $testSendEmail['id'] ?>">
                        <div class="panel-body">
                            <?php if ($testSendEmail['message']): ?><p class="text-<?php echo $testSendEmail['status'] ?>"><?php echo $testSendEmail['message'] ?></p><hr />
                            <?php elseif (!$testSendEmail['status']): ?><p>Please, enter an valid email address to send a test message.</p><?php endif ?>

                            <form class="form-horizontal" method="POST">

                                <div class="row">

                                    <div class="form-group col-xs-12 has-feedback<?php if($testSendEmail['fields']['emailto']['status']) echo ' has-'.$testSendEmail['fields']['emailto']['status'] ?>">
                                        <label for="emailto" class="col-sm-2 control-label">Host</label>
                                        <div class="col-sm-10">
                                            <input type="text" id="emailto" name="emailto" class="form-control" placeholder="<?php echo $testSendEmail['fields']['emailto']['message'] ? $testSendEmail['fields']['emailto']['message'] : 'Host name'; ?>" value="<?php echo $testSendEmail['fields']['emailto']['value'] ?>">
                                            <?php if ($testSendEmail['fields']['emailto']['status'] == 'error'): ?><span class="glyphicon glyphicon-remove form-control-feedback" aria-hidden="true"></span><?php endif; ?>
                                        </div>
                                    </div>

                                </div>

                                <div class="form-group">
                                    <div class="col-sm-offset-1 col-sm-10">
                                        <button type="submit" name="sendemail" class="btn btn-primary"<?php if ($testSendEmail['status'] == 'success') echo 'disabled="disabled"' ?>>Try to send a test message</button>
                                    </div>
                                </div>

                            </form>

                            <?php if (isset($testSendEmail['time'])): ?><p class="small">Time: <?php echo $testSendEmail['time'] ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach($app as $testInfos): if ($testInfos): ?><div id="test_<?php echo $testInfos['id'] ?>" class="panel panel-<?php echo $testInfos['status'] ?: 'default' ?> js-panel-<?php echo $testInfos['id'] ?>">
                    <div class="panel-heading" role="button" data-toggle="collapse" data-parent="#accordion" data-target="#panel-<?php echo $testInfos['id'] ?>">
                        <span class="badge pull-right"><?php echo $testInfos['value'] ?></span>
                        <h2 class="panel-title"><?php echo $testInfos['title'] ?></h2>
                    </div>

                    <div class="panel-collapse collapse" id="panel-<?php echo $testInfos['id'] ?>">
                        <div class="panel-body">
                            <?php if ($testInfos['label'] && false): ?><h4><?php echo $testInfos['label']; ?></h4>
                            <?php endif; ?>
                            <?php if ($testInfos['message']): ?><p><?php echo $testInfos['message'] ?></p>
                            <?php endif; ?>
                            <?php if ($testInfos['content'] && $testInfos['message']): ?><hr>
                            <?php endif; ?>
                            <?php echo $testInfos['content'] ?>

                            <?php if (isset($testInfos['time'])): ?><p class="small">Time: <?php echo $testInfos['time'] ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; endforeach; ?>

            </div>
            <p class="small"><i class="glyphicon glyphicon-eye-open"></i> Click on the box headings to see more information.</p>
        </div>

        <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" crossorigin="anonymous"></script>
        <?php if (true || !$app->isExcluded('domain')): ?><script>
            (function ($) {
                $.get('<?php echo $app->scriptName() ?>', { url: window.location.href }, function(data) {
                    var state = (data.state == 'success') ? 'success':'danger';
                    var badge = data.from_host;
                    var panel_content = $('.js-panel-domain .panel-body > p');

                    if(data.from_host == undefined) {
                        badge =  'Error';
                        state = 'danger';
                    }
                    if(state == 'danger') {
                        panel_content.text('An error occure and we need to test the configuration manually');
                    }
                    else {
                        panel_content.html('Ok, your domain seems correctly configured on <strong>' + data.from_host + '</strong>');
                    }

                    $('.js-panel-domain').removeClass('panel-default').addClass('panel-' + state);
                    $('.js-panel-domain .badge').text(badge);
                });
            })(jQuery);
        </script>
        <?php endif; ?>
    </body>
</html>
