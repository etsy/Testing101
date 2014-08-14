<?php

require_once "phplib/Testing/BackgroundAppRunner.php";


/**
 * Tests for Testing_BackgroundAppRunner.
 */
// NOTE: You shouldn't modify this class. These tests ensure that the given scaffolding for this
//       CodeLab still works.
class Testing_BackgroundAppRunnerTest extends PHPUnit_Framework_TestCase {

    private $appRunner = null;

    protected function tearDown() {
        if (!is_null($this->appRunner)) {
            $this->appRunner->stop();
            $this->appRunner = null;
        }
    }

    public function testStartAndGetServerData() {
        $this->appRunner = new Testing_BackgroundAppRunner();
        $this->appRunner->start();

        list($dataString, $resultInfo) = $this->doCurl();

        $responseCode = $resultInfo["http_code"];
        $this->assertEquals(200, $responseCode);
        $data = json_decode($dataString, true);
        $this->assertEquals("Plush lobster", $data[0]["item"]);
        $this->assertEquals(1, $data[0]["quantity"]);
        $this->assertEquals("Plush lobster food", $data[1]["item"]);
        $this->assertEquals(10, $data[1]["quantity"]);
    }

    private function doCurl() {
        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $curl_handle,
            CURLOPT_URL,
            "http://localhost:" . Testing_BackgroundAppRunner::PORT . "/server_request_data.php?userid=1234");
        $dataString = curl_exec($curl_handle);
        $resultInfo = curl_getinfo($curl_handle);

        return [$dataString, $resultInfo];
    }

    /**
     * Tests that we can start and stop multiple app instances in sequence.
     */
    public function testStartAndStopMultiple() {
        for ($i = 0; $i < 5; $i++) {
            $this->appRunner = new Testing_BackgroundAppRunner();
            $this->appRunner->start();
            list($dataString, $resultInfo) = $this->doCurl();
            $responseCode = $resultInfo["http_code"];
            $this->assertEquals(200, $responseCode);
            $this->appRunner->stop();
        }
    }
}
