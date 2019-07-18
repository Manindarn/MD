<?php

set_error_handler('errorHandler');
register_shutdown_function("shutdownHandler");

require_once '..' . DIRECTORY_SEPARATOR . 'Framework' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

define('ASSET_PATH', _definingFolder('assets'));
define('DB_PATH', _definingFolder('DB'));

$localLocation  = "/xampp/htdocs";
$baseFolderName = '/Mindspark';

define('LOC_REARCH_BASE_URL', 'http://' . $_SERVER['HTTP_HOST'] . $baseFolderName . '/ReArchitecture/');
define('LOC_FRAMEWORK_BASE_URL', 'http://' . $_SERVER['HTTP_HOST'] . $baseFolderName . '/Framework/');
define('FRAMEWORK_LOCATION', $localLocation . $baseFolderName . '/Framework/');

define('USER_JSON_FILE_LOC', $localLocation . $baseFolderName . "/ReArchitecture/application/config/userList.json");
define('TEMP_BASE_URL', 'http://' . $_SERVER['HTTP_HOST'] . $baseFolderName . '/ReArchitecture/');

define('USERNAME_PASSWORD', json_decode(file_get_contents(USER_JSON_FILE_LOC), true));

if (isJson($configFileContent = file_get_contents('config.json'))) {
    $config = json_decode($configFileContent, true);

    define('CONFIG', $config);

    if (!empty($constants = $config['constants'] ?? [])) {
        foreach ($constants as $key => $value) {
            define($key, $value);
        }
    }

    $frameworkConfigPath = '..' . DIRECTORY_SEPARATOR . 'Framework' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'config.json';

    $frameworkConfig = json_decode(file_get_contents($frameworkConfigPath), true);

    $mongoDBCreds = $frameworkConfig['dataSource'][MONGO_DATA_SOURCE];

    define('MONGO_HOST', $mongoDBCreds['host']);

    define('MONGO_USERNAME', null);
    define('MONGO_PASSWORD', null);

    //define('MONGO_USERNAME', $mongoDBCreds['username']??'');
   // define('MONGO_PASSWORD', $mongoDBCreds['password']??"");


} else {
    die("JSON not in proper format");
}

function _definingFolder($folderName)
{
    return (($_temp = realpath($folderName)) !== false)
    ? $_temp . DIRECTORY_SEPARATOR
    : strtr(rtrim($folderName, '/\\'), '/\\', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

function isJson($string)
{
    json_decode($string);
    return (json_last_error() == JSON_ERROR_NONE);
}

////////////////error handler/////////////////
function errorHandler($error_level, $error_message, $error_file, $error_line, $error_context)
{
    $error = prepareErrorMessage($error_level, $error_message, $error_file, $error_line, $error_context);
    // $error = "lvl: " . $error_level . " | msg:" . $error_message . " | file:" . $error_file . " | ln:" . $error_line;
    switch ($error_level) {
        case E_ERROR:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_PARSE:
            mylog($error, "fatal");
            break;
        case E_USER_ERROR:
        case E_RECOVERABLE_ERROR:
            mylog($error, "error");
            break;
        case E_WARNING:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_USER_WARNING:
            mylog($error, "warn");
            break;
        case E_NOTICE:
        case E_USER_NOTICE:
            mylog($error, "info");
            break;
        case E_STRICT:
            mylog($error, "debug");
            break;
        default:
            mylog($error, "warn");
    }
}

function prepareErrorMessage($error_level, $error_message, $error_file, $error_line, $error_context)
{
    // Reference of error http://php.net/manual/en/errorfunc.constants.php
    $errorLevel = [
        1 => 'E_ERROR',
        2 => 'E_WARNING',
        4 => 'E_PARSE',
        8 => 'E_NOTICE',
        16 => 'E_CORE_ERROR',
        32 => 'E_CORE_WARNING',
        64 => 'E_COMPILE_ERROR',
        128 => 'E_COMPILE_WARNING',
        256 => 'E_USER_ERROR',
        512 => 'E_USER_WARNING',
        1024 => 'E_USER_NOTICE',
        2048 => 'E_STRICT',
        4096 => 'E_RECOVERABLE_ERROR',
        8192 => 'E_DEPRECATED',
        16384 => 'E_USER_DEPRECATED',
        32767 => 'E_ALL',
    ];

    $url = parse_url($_SERVER['REQUEST_URI']);
    $path = isset($url['path']) ? $url['path'] : '';

    // Path Splitting
    if (isset($_SERVER['SCRIPT_NAME'][0])) {
        if (strpos($path, $_SERVER['SCRIPT_NAME']) === 0) {
            $path = (string) substr($path, strlen($_SERVER['SCRIPT_NAME']));
        } else if (strpos($path, dirname($_SERVER['SCRIPT_NAME'])) === 0) {
            $path = (string) substr($path, strlen(dirname($_SERVER['SCRIPT_NAME'])));
        }
    }
    $url = substr($path, 1);
    $msg = [];
    $msg['URL'] = $url;
    $msg['Error Level'] = isset($errorLevel[$error_level]) ? $errorLevel[$error_level] : 'Unknown';

    $msg['POST'] = json_encode($_POST);
    $msg['GET'] = json_encode($_GET);
    $msg['HEADER'] = json_encode(getallheaders());
    $msg['Error Message'] = $error_message;
    $msg['Error on'] = $error_file . ' ( Line: ' . $error_line . ' )';
    $msg['Error Traceback'] = json_encode(debug_backtrace());
    return $msg;
}

function shutdownHandler()
{
    $lasterror = error_get_last();
    $error_level = $lasterror['type'];
    $error_message = $lasterror['message'];
    $error_file = $lasterror['file'];
    $error_line = $lasterror['line'];
    $error_context = '';
    $error = prepareErrorMessage($error_level, $error_message, $error_file, $error_line, $error_context);
    switch ($lasterror['type']) {
        case E_ERROR:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_RECOVERABLE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_PARSE:
            // $error[] = "[SHUTDOWN] lvl:" . $lasterror['type'] . " | msg:" . $lasterror['message'] . " | file:" . $lasterror['file'] . " | ln:" . $lasterror['line'];
            mylog($error, "fatal");
    }
}

function mylog($error, $errlvl)
{

    $localIP = gethostbyname(trim(exec("hostname")));

    if (isset($error['Error Traceback'])) {
        $error['Error Traceback'] = json_decode($error['Error Traceback'], true);
        $errorTrace = [];
        if (isset($error['Error Traceback'][0])) {
            $errorTrace[] = $error['Error Traceback'][0];
        }

        if (isset($error['Error Traceback'][1])) {
            $errorTrace[] = $error['Error Traceback'][1];
        }
        $error['Error Traceback'] = $errorTrace;
    }
    $error = print_r($error, true);
    print_r($error);die;
    $credentials = new \Aws\Common\Credentials\Credentials(AWS_ACCESS_KEY, AWS_SECRET_KEY);
    $sns = \Aws\Sns\SnsClient::factory([
        'credentials' => $credentials,
        'region' => 'ap-southeast-1',
        'version' => 'latest',
    ]);

/*     try {
        $res = $sns->publish([
            'TopicArn' => 'arn:aws:sns:ap-southeast-1:910407405854:Re-arch_mailers',
            'Subject' => $errlvl . ': Need attention here - ' . $localIP . ' - UMPort',
            'Message' => $error,
        ]);
    } catch (\Aws\Sns\Exception\SnsException $e) {

    } catch (\Aws\Sns\Exception\InvalidParameterException $e) {

    }
 */
}
