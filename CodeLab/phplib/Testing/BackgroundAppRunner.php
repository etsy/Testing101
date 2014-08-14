<?php

/**
 * Runs the CodeLab's PHP webapp in the background using Apache.
 *
 * You can use this in tests to start an instance of this CodeLab's webapp in the background:
 *
 * <code>
 * $backgroundApp = new Testing_BackgroundAppRunner();
 * $backgroundApp->start();
 * try {
 *     // Make curl calls to localhost at port Testing_BackgroundAppRunner::PORT...
 * } finally {
 *     $backgroundApp->stop();  // Alternatively, you could put this in your test's tearDown() method.
 * }
 * </code>
 */
final class Testing_BackgroundAppRunner {

    // To get this port number:
    // echo -n "Testing_BackgroundAppRunner" | md5sum
    // Then take the first 4 hex digits (25fe) and convert to decimal.
    const PORT = 9726;

    /** @var bool */
    private $running;

    public function __construct() {
        $this->running = false;
    }

    public function start() {
        $user = $_ENV["USER"];
        // For some reason, Apache background startup fails with relative paths when I try it.
        // So to be safe, use absolute paths.
        $server_root = realpath("apache_temp");
        $server_config_file = realpath("testing101.conf");
        exec(
            "httpd"
                . " -d $server_root"
                . " -f $server_config_file"
                . " 2>&1",
            $output,
            $return_var);

        if ($return_var !== 0) {
            throw new RuntimeException("Apache failed to start! Output:\n" . implode("\n", $output));
        }
        $this->running = true;
        $this->registerProcessShutdownHook();
    }

    public function stop() {
        if (!$this->running) {
            return;
        }
        $user = $_ENV["USER"];
        $pidFile = realpath("apache_temp/run/httpd.pid");
        if (!file_exists($pidFile)) {
            // We've outrun Apache's creation of a PID file. So give it a second.
            sleep(1);
        }
        $pid = trim(file_get_contents($pidFile));
        if ($pid === false || trim($pid) === "") {
            throw new RuntimeException("Couldn't read the PID from file $pidFile");
        }
        exec("kill $pid", $output, $return_var);
        if ($return_var !== 0) {
            throw new RuntimeException("Failed to kill Apache! Output:\n" . implode("\n", $output));
        }

        // Another hack. We want to wait until Apache actually has shut down, by checking the PID
        // file. This is so that multiple tests, running quickly in sequence, can each use a
        // different instance of this class safely, without the old Apache instance still running.
        $max_tries = 5;
        $tries = 0;
        while (file_exists($pidFile) && $tries < $max_tries) {
            usleep(500000);
            $tries++;
        }
        if (file_exists($pidFile)) {
            throw new RuntimeException("Apache is still running after $max_tries sleeps!");
        }
        $this->running = false;
    }

    private function registerProcessShutdownHook() {
        register_shutdown_function(array($this, 'stop'));
    }
}
