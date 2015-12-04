<?php namespace Revolverobotics\HelperTools;

class HelperTools {
    public static function debugLog($resourse,$info)
    {
        if (getenv('APP_DEBUG') == true) {
            \Log::info("[".$resourse."] ".$info);
        }
    }

    // Does what it says.
    public static function prettyJson($code=200, $input=[])
    {
        $responseData = $input;
        $statusArray = [];

        if (gettype($code) == 'array'){
            // let's assume 200 OK if we're only passing data
            $statusArray = ['statusCode' => 200];
            $responseData = $statusArray + $code;
            $code = 200;	// data has been assigned to responseData
                            // let's reassign $code to the status code
        } else {
            // otherwise, read the status code, and include data
            $statusArray = ['statusCode' => $code];
            if (gettype($input) == 'array') {
                $responseData = $statusArray + $input;
            } else {
                $responseData = [];
                $responseData['data'] = $input;
                $responseData['statusCode'] = $code;
            }
        }

        // Finally, if we're in debug mode, let us know about it.
        if (getenv('APP_DEBUG') == true) {
            $responseData['kubi_frontend'] = 'debug';
            $responseData['response_time'] = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        }

        return response()->json($responseData, $code, array('Content-Type' => 'application/json'), JSON_PRETTY_PRINT);
    }

    // instead of our old prettyJson function, we'll rely on the backend server
    // to provide all relevant status codes

    public static function prettyJsonRedis($input=[])
    {
        $responseData = $input;
        $code = $input['statusCode'];

        // Finally, if we're in debug mode, let us know about it.
        if (getenv('APP_DEBUG') == true) {
            $responseData['kubi_frontend'] = 'debug';
            $responseData['response_time'] = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        }

        return response()->json($responseData, $code, array('Content-Type' => 'application/json'), JSON_PRETTY_PRINT);
    }

    // Helper to make Guzzle requests to other microservices
    public static function sendRequest($method, $microservice, $url, $data)
    {
        if (\App::environment() == 'local') {
            $extension = '.dev';
        } else {
            $extension = '.com';
        }

        $microserviceArray = [
            'kubi-service' => 'service.kubi-vpc'.$extension,
            'kubi-auditing' => 'auditing.kubi-vpc'.$extension,
            'kubi-users' => 'users.kubi-vpc'.$extension,
        ];

        $client = new \GuzzleHttp\Client();

        // Sending application/x-www-for-urlencoded POST, PUT, & PATCH (non-GET) requests requires
        // `form_params` request options, instead of `query`
        if ($method != 'GET') {
            $data['form_params'] = $data['query'];
            unset($data['query']);
        }

        $dataArray = $data;
        $dataArray['http_errors'] = false; // don't fail on error (400, 500, etc.)

        // Forward headers (modified) to backend
        $headers = \Request::header();
        $headers['host'][0] = $microserviceArray[$microservice];
        $headers['connection'][0] = "close";
        // We're always going to be sending data to the backend
        // in a certain way, so let's let guzzle detect the Content-Type:
        unset($headers['content-type']);
        unset($headers['content-length']);
        $dataArray['headers'] = $headers;

        $rawResponse = $client->request($method, $microserviceArray[$microservice].$url, $dataArray);

        $parsedResponse = [];
        $parsedResponse['json'] = json_decode($rawResponse->getBody(), true);
        $parsedResponse['code'] = $rawResponse->getStatusCode();


        // NOTE: Now let's send an asynchronous request to our auditing server
        // so we can keep track of internal microservice communications

        if (strpos($url, 'admin/logs') !== false) { return $parsedResponse; } // if we're fetching logs from other servers, don't notify the auditing server

        // Get rid of any sensitive or unwanted information
        $protectedData = ['password', 'password_confirmation', 'new_password', 'new_password_confirmation', 'secret', 'api_secret', 'client_secret'];
        $responseData = $parsedResponse['json'];

        foreach($protectedData as $sensitive) {
            if (isset($dataArray['query']) && is_array($dataArray['query']) && array_key_exists($sensitive, $dataArray['query'])) {
                unset($dataArray['query'][$sensitive]);
            }
            if (isset($dataArray['form_params']) && is_array($dataArray['form_params']) && array_key_exists($sensitive, $dataArray['form_params'])) {
                unset($dataArray['form_params'][$sensitive]);
            }
            if (isset($responseData) && is_array($responseData) && array_key_exists($sensitive, $responseData)) {
                unset($responseData[$sensitive]);
            }
        }

        // Clear header data
        unset($dataArray['headers']);

        // We couldn't get the Guzzle/Promise sendAsync to work:
        // It seems that this interface is for use within a loop, otherwise
        // the synchronous sendAsync()->wait() method needs to be used.
        //
        // So instead:
        //
        // Let's do a manual cURL request and fork the process with exec():

        $auditData = http_build_query([
            'from'	=> 'kubi-frontend',
            'to'	=> $microservice,
            'data'	=> $dataArray,
            'status_code' => $parsedResponse['code'],
            'response' => $responseData
        ]);

        // Send an asynchronous request to our auditing server.
        self::forkCurl("POST", $auditData, "http://auditing.kubi-vpc" . $extension . "/internal");

        return $parsedResponse;
    }

    public static function getAccessTokenFromHeader($request)
    {
        $chunks = explode(" ", $request->header('Authorization'));
        if (isset($chunks[1])) {
            return $chunks[1];
        }
        return null;
    }

    public static function getAuthorizationHeader($request)
    {
        $chunks = explode(" ", $request->header('Authorization'));
        if (isset($chunks[1])) {
            return $chunks[1];
        }
        return null;
    }

    public static function verifyRefererDomain($request)
    {
        // verify if a request is coming from our domain or not
        $domain = $request->header()['referer'][0];
        $foundLocal = strpos($domain, 'api.kubi-vpc.dev');
        $foundServer = strpos($domain, 'api.kubi.me');

        if ($foundLocal !== false || $foundServer !== false) {
            return true;
        }

        return false;
    }

    public static function forkCurl($method, $inputData, $url)
    {
        // sends an asynchronous request using a forked cURL process.
        // does not wait for a response.

        $data = http_build_query($inputData);
        $hdr  = "-H 'Content-Type: application/x-www-form-urlencoded' ";
        $cmd  = "curl --silent -X " . $method . " " . $hdr . " -d \"" . $data . "\" " . $url;

        if (strpos(php_uname('s'),'Windows') !== false) {
            \Log::info('windows async');
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            $cmd .= " > /dev/null 2>&1 &";
            exec($cmd, $output, $exit);
        }

        return true;
    }
}
