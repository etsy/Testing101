<?php

/**
 * Fetches API data.
 */
final class ApiClient {

    const API_SERVER_URL = "localhost:9726";
    const FETCH_DATA_ENDPOINT = "/server_request_data.php";

    private static $retryBackoffIntervals = [1, 10, 60, -1];

    public function __construct() {
    }

    /**
     * Attempts to retrieve and parse API data from some source, backing off and retrying a total of four times.
     *
     * @return array API data
     * @throws RuntimeException if there's any error retrieving the data
     */
    public function getData() {
        foreach(self::$retryBackoffIntervals as $oneRetryBackoffInterval) {
            $curl_handle = curl_init();
            curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl_handle, CURLOPT_URL, "http://" . self::API_SERVER_URL . self::FETCH_DATA_ENDPOINT . "?userid=1234");

            $dataString = curl_exec($curl_handle);
            $resultInfo = curl_getinfo($curl_handle);
            $responseCode = $resultInfo["http_code"];

            if ($responseCode == 200) {
                return json_decode($dataString, true);
            } elseif ($oneRetryBackoffInterval >= 0) {
                error_log("Curl failed. Sleeping for $oneRetryBackoffInterval seconds...\n", 3, "php://stderr");
                sleep($oneRetryBackoffInterval);
            }
        }

        throw new RuntimeException("Failed getting data!");
    }
}
