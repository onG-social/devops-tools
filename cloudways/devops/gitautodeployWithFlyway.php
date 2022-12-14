<?php

/* ----------------------------------------------------------------
* CONFIGURATION SECTION
* Author: CloudWays and Phillip Rumple
* Date: 10/12/2022
* License: MIT License
* ----------------------------------------------------------------
*/

// The CloudWays API key
const API_KEY = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXX";

// The CloudWays API URL, this usually doesn't change
const API_URL = "https://api.cloudways.com/api/v1";

// Your registered CloudWays Email Address (note this should be the account of the root owner)
const EMAIL = "AAAAAAAAAAA@BBBBBBB.CCCCCCCC";

// The desired Git Repository URL
const GIT_URL = "git@github.com:YOUR_REPOSITORY_URL.git";

// If you have flyway configured, and running on the server
// then set this to yes, if you wish to run the migrations
const SHOULD_USE_FLYWAY = false;

// path to flyway exec
// it is recommended you install this in /home/master
// LEAVE THE TRAILING SLASH OFF
const FLYWAY_EXEC_LOCATION = '/home/master/flyway';

// flyway configuration location (note, this is a json object)
// your first object should be the name of the branch/application
// your objects value should be the name of the configuration file
const FLYWAY_CONFIG_FILE = [
    "dev-env-1" => "/home/master/flyway/conf/dev1.cnf",
    "dev-env-2" => "/home/master/flyway/conf/dev2.cnf",
    "dev-env-3" => "/home/master/flyway/conf/dev3.cnf",
    "dev-env-4" => "/home/master/flyway/conf/dev4.cnf",
    "staging" =>  "/home/master/flyway/conf/staging.cnf"
];

// what log file should we use?
// note, you should have permissions setup for this
const LOG_FILE = "log.log";

// logging is enabled by default
const SHOULD_LOG = true;

// what is your certified timezone?
const TIME_ZONE = "America/Los_Angeles";


/* ----------------------------------------------------------------
* NEEDED FILES
* ----------------------------------------------------------------
*  you will need to make an env.php that lives in the same directory
* as this application.
*
* File: env.php
* <?php
*   define('GITHUB_WEBHOOOK_SECRET', 'WHATEVER_YOUR_SECRET_IS');
* ?>
* note, you should also make sure you have an .htaccess file in place
* that denies access to this file to the public
*
* File .htaccess
* <FilesMatch "(env.php)$">
*	Order Allow,Deny
*	Deny from all
*</FilesMatch>
*
* ----------------------------------------------------------------
*/


function outLog($message)
{
    date_default_timezone_set(TIME_ZONE);
    $date = date('m/d/Y h:i:s a', time());
    $tempMessage = "\n[" . $date . "] "  . $message;
    echo $tempMessage;
    error_log($tempMessage, 3, LOG_FILE);
    return $tempMessage;
}

function fetchAccessToken()
{
    return callCloudwaysAPI(
        'POST',
        '/oauth/access_token',
        null,
        [
            'email' => EMAIL,
            'api_key' => API_KEY
        ]
    )->access_token;
}


// require the environment file for the secret
require("env.php");

// normalize that
$hookSecret = GITHUB_WEBHOOK_SECRET;

// handle errors
set_error_handler(function ($severity, $message, $file, $line) {
    outLog($message);
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// handle exceptions
set_exception_handler(function ($e) {
    header('HTTP/1.1 500 Internal Server Error');
    die(outLog("Error on line {$e->getLine()}: " . htmlSpecialChars($e->getMessage())));
});

// hold the raw post data
$rawPost = NULL;

// verify if the secret is set
if ($hookSecret !== NULL) {
    // verify if this comes from the github process
    if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
        outLog("HTTP header 'X-Hub-Signature' is missing.");
        throw new \Exception("HTTP header 'X-Hub-Signature' is missing.");
    } elseif (!extension_loaded('hash')) {
        outLog("Missing 'hash' extension to check the secret code validity.");
        // verify if we have the hash extension loaded, which is critical
        throw new \Exception("Missing 'hash' extension to check the secret code validity.");
    }
    // explode the signature
    list($algo, $hash) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE'], 2) + array('', '');

    // check if we can decrypt the signature
    if (!in_array($algo, hash_algos(), TRUE)) {
        outLog("Hash algorithm '$algo' is not supported.");
        throw new \Exception("Hash algorithm '$algo' is not supported.");
    }

    // read the contents of the payload form github
    $rawPost = file_get_contents('php://input');

    // if the secrets don't match quit
    if (!hash_equals($hash, hash_hmac($algo, $rawPost, $hookSecret))) {
        outLog('Hook secret does not match.');
        throw new \Exception('Hook secret does not match.');
    }
}

// if content type isn't set, then quit, because we don't know how to read the payload
if (!isset($_SERVER['CONTENT_TYPE'])) {
    outLog("Missing HTTP 'Content-Type' header.");
    throw new \Exception("Missing HTTP 'Content-Type' header.");
} elseif (!isset($_SERVER['HTTP_X_GITHUB_EVENT'])) {
    outLog("Missing HTTP 'X-Github-Event' header.");
    // if it's not a github event, quit
    throw new \Exception("Missing HTTP 'X-Github-Event' header.");
}

// handle the events
switch ($_SERVER['CONTENT_TYPE']) {
        // if it's JSON
    case 'application/json':
        // set the JSON data
        $json = $rawPost ?: file_get_contents('php://input');
        break;
    case 'application/x-www-form-urlencoded':
        // set the form encoded data
        $json = $_POST['payload'];
        break;
    default:
        outLog("Unsupported content type: $_SERVER[CONTENT_TYPE]");
        // we don't handle any other types, and Github doesn't send any other types
        throw new \Exception("Unsupported content type: $_SERVER[CONTENT_TYPE]");
}

// decode the GIT response
$payload = json_decode($json);

// fetch the branch by getting rid of the ref/heads
$branch = str_replace('refs/heads/', '', $payload->ref);

outLog("Branch in question: " . $branch);

//Fetch Access Token
$accessToken = fetchAccessToken();

// get a list of servers for the CW account
$servers = callCloudWaysAPI('GET', '/server', $accessToken);

// PROCESS the github event
switch (strtolower($_SERVER['HTTP_X_GITHUB_EVENT'])) {
        // handle github's keep alive event
    case 'ping':
        echo 'pong';
        break;
        // handle pushes to the branches
    case 'push':
        // because push events are important, we need to know if the branch
        // we are being sent is a branch we can push too locally
        // first we need to check our applications array for the proper ID
        $appId = 0;
        $serverId = 0;

        // if the return from Cloudways isn't passing, then quit
        if ($servers->status !== true) {
            die(outLog("no servers!"));
        }

        // parse for our app and server id
        foreach ($servers->servers as $server) {
            outLog("looking on server: " . $server->label);
            $success = false;
            foreach ($server->apps as $app) {
                outLog("looking at app: " . $app->label);
                if ($app->label == $branch) {
                    $appId = $app->id;
                    $serverId = $server->id;
                    $success = true;
                    break;
                }
            }
            // if we found our app/server, then break and continue on
            if ($success === true) {
                break;
            }
        }

        // do we have any apps and servers?
        if ($serverId == 0 || $appId == 0) {
            die(outLog("App not found that matches our branch, skipping!"));
        } else {
            outLog("App found that matches our branch, publishing! \nID: " . $appId . " On server: " . $serverId);
        }

        // now that we know our app and server Id we can push to it
        $gitPullResponse = callCloudWaysAPI('POST', '/git/pull', $accessToken, [
            'server_id' => $serverId,
            'app_id' => $appId,
            'git_url' => GIT_URL,
            'branch_name' => $branch
        ]);


        // should we do migrations using flyway?
        if (SHOULD_USE_FLYWAY) {

            $urlToCheck = 'git/history?server_id=' . $serverId . '&app_id=' . $appId;
            outLog("URL To Check: " .  API_URL . '/' . $urlToCheck);
            $done = false;
            $error = false;
            $errorMessage = '';
            $cycleCount = 0;
            while (!$done) {
                $cycleCount += 1;
                if ($cycleCount > 5) {
                    die(outlog("Reached maximum attempts to query deployment status, failing..."));
                }
                outLog("Fetching deployment status... (try " . $cycleCount . " of 5)");
                $result = 0;
                $description = '';
                try {
                    $logs = callCloudWaysAPI('GET', '/git/history?server_id=' . $serverId . '&app_id=' . $appId, $accessToken, [], false);
                    $result = $logs->logs[0]->result;
                    $description = $logs->logs[0]->description;
                    outlog("Result from Deployment check: " . $result . " description: " . $description);
                    if ($result === 0) {
                        outlog("not done with deployment...continuing...");
                        sleep(4);
                        continue;
                    }
                } catch (exception $e) {
                    outlog("Couldn't fetch log status: " . $e);
                    sleep(4);
                    continue;
                }

                // check if the result is '1' - which means the command was successfully set to the server
                // check if the description isn't set - which means the deploy was successful
                if ($result == '1' && $description == "") {
                    $error = false;
                    $done = true;
                    outLog("Success! Deployment finished!");
                    break;
                }

                // check if the result is a failure, if it is quit the while loop
                if ($result == '-1') {
                    $error = true;
                    $errorMessage = $description;
                    $done = true;
                    outLog("Failure! Deployment Failed!");
                }

                // sleep 4 seconds, and try again
                sleep(4);
            }

            if ($error) {
                outLog("Push completed, but pull didn't deploy: " . $errorMessage);
                header('HTTP/1.0 201 Created');
            }

            // find the config file to use for flwyay
            $configFileFlyway = FLYWAY_CONFIG_FILE[$branch];
            if ($configFileFlyway == null || $configFileFlyway = '') {
                die(outLog("unable to find the config file for branch: " . $branch));
            }

            // execute migration script
            $output = null;
            $retval = null;
            exec(FLYWAY_EXEC_LOCATION . '/flyway -configFile=' . $configFileFlyway, $output, $retval);

            if (!$retval) {
                die(outLog(json_encode("{ 'data': '" . $output . "'}")));
            }
        }


        // we'll die if the above doesn't parse properly anyway
        // so now just return the response to github
        outLog(json_encode($gitPullResponse));
        header('HTTP/1.0 200 OK');

        break;
    default:
        // let github know we don't handle other requests currently
        header(outLog('HTTP/1.0 404 Not Found'));
        die(outLog('HTTP/1.0 404 Not Found'));
}

/**
 * Use this function to interact with the CloudWays API
 *
 * @param mixed $method - GET/POST/DELETE etc
 * @param mixed $url - the api endpoint to interact with
 * @param mixed $accessToken - the access token
 * @param array $post - any parameters to apply
 *
 * @return json - the response
 *
 */
function callCloudwaysAPI($method, $url, $accessToken, $post = [], $dieOnError = true)
{
    $baseURL = API_URL;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_URL, $baseURL . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //Set Authorization Header
    if ($accessToken) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    }

    //Set Post Parameters
    $encoded = '';
    if (count($post)) {
        foreach ($post as $name => $value) {
            $encoded .= urlencode($name) . '=' . urlencode($value) . '&';
        }
        $encoded = substr($encoded, 0, strlen($encoded) - 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_POST, 1);
    }
    $output = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpcode != '200') {
        if ($dieOnError) {
            die(outLog('An error occurred at CloudWays API Call code: ' . $httpcode . ' output: ' . substr($output, 0, 10000)));
        }
        return json_decode("{'status': false}");
    } else {
        outLog("Success: " . $httpcode);
    }
    curl_close($ch);
    return json_decode($output);
}
