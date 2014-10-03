# Testing Best Practices at Etsy

## What is this document?

This is an introduction to the ideas and approaches that motivate good testing.
We'll walk through a concrete example of a system written in PHP and tested in
PHPUnit (as we use at Etsy) and discuss how to test it for good design and how
to design it for testability.

## Audience and prerequisites

This doc is intended for any Etsy engineer.

Before working through this guide, you should already have made a few changes to
Etsy's codebase, preferably but not necessarily in PHP, including work on unit
tests.

## Why test?

Here are a few chief reasons:

- **Correctness**: Make sure, as best you can, that the code you've written
  doesn't have bugs and treats its cases correctly.

- **Stability**: Make sure, as best you can, that future changes to the codebase
  (in whatever component, by whatever engineer) don't break your code. Every
  day, crises are averted in an unsung and straightforward manner simply because
  a test broke.

- **Design**: A test is a proof of concept that shows what it's like to work
  with your code. How readable is the API? How easy is it to accomplish a given
  task? If tests are hard to write, then your code is hard to use.

These go in order from less important to more important. By far the most
important thing you test in your code is its usability.

## Think in terms of inputs and outputs

The basic idea of a test is to give your code some input, let it do its thing,
then check that its output is what you expect. This is true whether you're
testing a single public method or an entire system. Let's see what that looks
like at the lowest level, where you're testing a single public method.

Throughout this guide we're going to test one example system: a *job queue*
where you submit a piece of "work" (a "job") to some "central" "server" that
carries out the "work" in some way. The rampant quotes are deliberate. The
technical details of what a piece of work entails or how the server does that
work are practically irrelevant. The important things to capture in your code
and tests are the abstractions involved and how they're used.

Here's a `JobServer` class with a public method that runs jobs you "send" to it:

```php
<?php

class JobServer {

    /** @var array a set of jobs we've already run recently and don't want to run again for now */
    private $recentlyRunJobs = array();

    /**
     * Attempts to run a given Job and returns a result code.
     *
     * @param Job $job the Job to run
     * @return int a code for the result of attempting to run $job:
     *     JobResult::SUCCESS if the job was successfully run,
     *     JobResult::FAILED_DURING_RUN if the job threw an exception,
     *     JobResult::FAILED_TO_SCHEDULE if this job has already been run recently
     */
    public function runJob(Job $job) {
        if (in_array($job, $this->recentlyRunJobs)) {
            return JobResult::FAILED_TO_SCHEDULE;
        }
        try {
            $job->run();
        } catch (Exception $jobException) {
            Logger::log_info("Exception during execution: " . $jobException->getMessage());
            return JobResult::FAILED_DURING_RUN;
        }
        $this->recentlyRunJobs[] = $job;
        return JobResult::SUCCESS;
    }
}
```

So. What do you want to test? Think in terms of the API and the class's
input/output contract:

- If you send it a job it hasn't recently run, it'll run it and return
  `JobResult::SUCCESS`.
- If you send it a job that throws an Exception during `run()`, it'll run it and
  return `JobResult::FAILED_DURING_RUN`.
- If you send it a job it *has* recently run, it'll return
  `JobResult::FAILED_TO_SCHEDULE`.

So in your unit test, test each aspect of the contract/API. Put it in
`tests/phpunit/JobServerTest.php`, and use method names that convey you're
testing one aspect of the `runJob()` API at a time:

```php
<?php

class JobServerTest extends PHPUnit_Framework_TestCase {

    public function testRunJob_success() {
        $jobServer = new JobServer();
        $job = new SuccessfulTestJob();

        $jobResult = $jobServer->runJob($job);
        $this->assertEquals(JobResult::SUCCESS, $jobResult);
        $this->assertTrue($job->jobRan);
    }

    public function testRunJob_failsDuringRun() {
        $jobServer = new JobServer();
        $jobResult = $jobServer->runJob(new FailingTestJob());
        $this->assertTrue($job->jobRan);
        $this->assertEquals(JobResult::FAILED_DURING_RUN, $jobResult);
    }

    public function testRunJob_failsToSchedule() {
        $jobServer = new JobServer();
        $job = new SuccessfulTestJob();

        $jobResult = $jobServer->runJob($job);
        $this->assertEquals(JobResult::SUCCESS, $jobResult);
        $jobResult = $jobServer->runJob($job);
        $this->assertEquals(JobResult::FAILED_TO_SCHEDULE, $jobResult);
    }
}

/* A test-specific implementation like this is called a "stub". */
class SuccessfulTestJob implements Job {

    public $jobRan = false;

    public function run() {
        $this->jobRan = true;
    }
}

class FailingTestJob implements Job {

    public $jobRan = false;

    public function run() {
        $this->jobRan = true;
        throw new RuntimeException("Oopsie");
    }
}
```

Each test method answers the all-important question **"What am I testing,
exactly?"**

(Note: Some people prefer to approach this in reverse order, by writing tests
first that exercise the API, then filling in the implementation of the class
with code that honors that API. This is test-driven development, or TDD.)


## Choose your abstractions

We want to start writing the client side of our job queue. A client "sends" a
"job" to the "server" in some particular way.

**What are we testing, exactly?**

...which is, in this case, a way of asking "How do we specify a client?" Let's
say a client has a `sendJob()` method. (That seems easy enough to test, right?)
And in that method it has a short conversation with a server somewhere asking it
to execute the given job.

If we bake the conversation in:

```php
<?php

class JobClient {

    const JOB_BATCH_SIZE = 20;

    /**
     * @var array the current batch of jobs, to be sent to the server when there
     *     are JOB_BATCH_SIZE of them
     */
    private $currentJobBatch = array();

    /**
     * Tells this client to send off a job for execution. Jobs may be queued
     * for batch sending.
     */
    public function sendJob($jobName, $jobParametersJson) {

        $this->currentJobBatch[] = [$jobName, $jobParametersJson];

        if (count($this->currentJobBatch) + 1 >= self::JOB_BATCH_SIZE) {
            $curlHandle = curl_init("jobserver.etsy.com/submit");
            curl_setopt($curlHandle, CURLOPT_POST, 1);
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, ["data" => $this->currentJobBatch]);
            $result = curl_exec($curlHandle);
            $this->currentJobBatch = array();
            return $result;

        }
    }
}
```

...then a) we have to find a way to mock those curl calls for testing (gross),
and b) more deeply, we've bound the `JobClient` class to an implementation
decision -- that we'll use HTTP to send jobs over the wire -- that's none of its
business.

To put it another way, what we've got here is a script, not code. It doesn't
manage one part in a system of abstractions -- instead, it just runs some
commands. Scripts are hard to test, and they don't convey the purpose of their
code *within a system* the way an abstraction could -- an abstraction like:

```php
<?php

class JobClient {
    public function sendJob($jobName, $jobParametersJson) {
        if (count($this->currentJobBatch) + 1 >= self::JOB_BATCH_SIZE) {
            (new HttpJobTransmitter("jobserver.etsy.com"))->transmitJobs($this->currentJobBatch);
            $this->currentJobBatch = array();
        } else {
            $this->currentJobBatch[] = [$jobName, $jobParametersJson];
        }
    }
}
```

Now the act of serializing a job into an HTTP request, which is a deep enough
consideration to deserve its own abstraction, has its own class. But our
`JobClient` is still bound to using HTTP because it decides on its own, in the
body of `sendJob()`, to create a new `HttpJobTransmitter`. The choice of wire
protocol is no business of the `JobClient`. It should be *told* how to send a
job out:

```php
<?php

class JobClient {

    public function __construct(JobTransmitter $transmitter) {
        $this->transmitter = $transmitter;
    }

    public function sendJob($jobName, $jobParametersJson) {
        if (count($this->currentJobBatch) + 1 >= self::JOB_BATCH_SIZE) {
            $this->transmitter->transmitJobs($currentJobBatch);
            $this->currentJobBatch = array();
        } else {
            $this->currentJobBatch[] = [$jobName, $jobParametersJson];
        }
    }
}
```

...where `JobTransmitter` is some interface.

Now you can test that your `JobClient` implementation uses a `JobTransmitter`
correctly, similarly to how we tested successful and failing jobs for the
`JobServer` above.

*Choosing the right abstractions*, and the right structure for your project, is
the bedrock that makes everything else -- testing, maintenance, adding features,
usability -- easy or hard.

#### Exercises
- How do we test the batching of jobs in the client? What if we don't want
  batching for a particular test?
- What if, instead of writing a new `HttpJobTransmitter` class, we instead wrote
  different kinds of clients, one of them being `HttpJobClient`, each
  encapsulating its own job-sending protocol? What *other* classes would this
  approach necessitate to complete the `JobClient` system? Do you think this
  approach is better than the one above?
- What do you think of the signature of the `sendJob()` method? If you don't
  like it, how would you improve it?


## Testing parts together

The code is written for both client and server, but we've only tested each in
isolation. We want to test that the system itself actually works, for example
that we can successfully send a job from a real-life `JobClient` to a real-life
`JobServer`.

A common way to approach this is to start by testing each *interaction* between
any two parts of your system (sometimes called an "integration test").

**What are we testing, exactly?**

Ask yourself what happens between a client and server that can't be tested in
one or the other individually. Those are the only things you should worry about
testing at this level, because setting up two real-life components for testing
at the same time is harder than testing one by itself. So make the effort count.

Interactions we might consider testing:

- If we send a job from the client, the server receives it.
- If we send a job from the client when the server is down, the client fails as
  expected (or hangs on to it to retry, or something).
- If we send 1,000 jobs to a server that can only remember 500 at a time, it
  stops accepting jobs.

There's no particular trick to setting up an integration test. Stand up a
server, and stand up a client. Then have the client send a job, and check that
the server receives it.

You can use your imagination as to how this looks in code. Since the goal is to
test interactions as they'd occur in prod, have your client send a job the prod
way:

```php
<?php

class ClientServerIntegrationTest extends PHPUnit_Framework_TestCase {
    public function testSendAndReceiveJob() {
        $server = new HttpJobServer("localhost:8888");
        $this->startInBackground($server);  // Start the server in a background process.
        $client = new JobClient(new HttpJobTransmitter("localhost:8888"));
        $client->sendJob("test_job_type", '{ "param1": "value1" }');

        $serverQueue = $server->getJobQueue();
        $this->assertEquals("test_job_type", $serverQueue[0]->getJobType());
    }
}
```

There are many details elided over in this snippet. For example, how do you
prevent the job server from immediately dequeueing the incoming job before the
assert happens? To isolate the interaction between client and server for this
test, you may want to configure the job server not to execute at all, since job
execution isn't part of this test. Maybe you could pass it some kind of no-op
`JobExecutor` implementation. But the important thing here is that the structure
of the test is the same as before -- set up the parts under test, feed them
inputs, and check their outputs.

#### Exercises
- How would you test that if your `JobServer` only queues up to 500 jobs, and
  your client sends it 1,000, that the server stops accepting jobs and the
  client fails as expected?
- Do you think that calling `$server->getJobQueue()` for a test breaks the
  `JobServer` class's encapsulation? How would you change this test or the code
  under test so that we don't have to ask the `JobServer` to tell us about its
  internal state (i.e. the size of its job queue)?


## Testing an entire system

Finally, think about testing your system from the top down. The goals of testing
a whole system are the same as those of testing an individual class: does it do
what it promises users, and is it easy to use? Top-level tests, starting from
your system's user-facing entry point, are a way of making sure all of your
design decisions have successfully come together.

For example, if your system is a web app, the user-facing entry point is an HTTP
request. The request goes through a controller and is fulfilled by different
sub-systems in the controller logic: a database, a template system, a search
indexer, whatever.

To make a distinction, you can test the controller itself, say with an
integration test:

```php
<?php

/* PurchaseConfirmationPageController.php */
class PurchaseConfirmationPageController extends EtsyController {
    public function doPurchaseConfirmations(PurchaseObject $purchase) {
        $this->purchaseDatabase->store($purchase);
        echo '<h2>Purchase complete!</h2>';
        $this->jobClient->sendJob(
            "send_purchase_confirmation_email",
            "{ purchase_id: \"{$purchase->purchaseId}\" }");
    }
}


/* PurchaseConfirmationPageControllerTest.php */
class PurchaseConfirmationPageControllerStubTest extends PHPUnit_Framework_TestCase {
    public function testDoPurchaseConfirmations() {
        $stubJobClient = new NoOpClient();
        $stubDatabase = new TestPurchaseDatabase();

        $controller = new PurchaseConfirmationPageController(
            $stubDatabase,
            $stubJobClient
        );

        $controller->doPurchaseConfirmations(new PurchaseObject([1234 => "Plush lobster"]));

        // The getAllSentJobs() method is specific to the NoOpClient class.
        $this->assertEquals(1, count($stubJobClient->getAllSentJobs()));
    }
}
```

...but what are you testing, exactly? If you've broken up your code into
separate parts and your controller merely coordinates those parts, then this
isn't much more than a check for typos in your controller code.

On the other hand, you can test your system just as users do with an actual
end-to-end test where you set up an instance of your webapp and make HTTP
requests to it. The idea here is to test your code in as naive a way as possible
-- by using it the way your end users do. So there's no PHPUnit and no direct
interaction with the code. You treat your codebase as one big black box, and you
give it real-life inputs and outputs.

In this case, the input is an HTTP request, and the outputs are -- well, it
depends. In this case, you want to check a) that the HTTP response is 200 and
looks sane, and b) that you receive a purchase confirmation email.

Setting up these resources in a test environment is an exercise for the reader.
For this test, you'd have to set up a real database and load it with data for
the purchase you want to confirm. And you'd have to set up Apache to run your
PHP webapp locally. And you've have to set up a local mail server. (See Etsy's
other testing documentation on how to write tests that use a real MySQL
database, for example.)

Once you've set that up, your end-to-end test could be a shell script that curls
your test webapp, a Selenium test that opens it in a browser, or a PHPUnit test
that uses PHP curl calls. It doesn't matter, so long as you do what a user does
and inspect your app's behavior as naturally and naively as possible. These
tests are a lot of work to set up, and they take a long time to run, so consider
them sanity checks that complement a suite of deeper and more fine-grained
PHPUnit tests. But do make sure your system is easy to test, i.e. easy to use.

## Balance different types of tests

Some code lends itself to thorough testing with unit tests because it breaks
down naturally into modules of plain logic. On the other hand, some code demands
more high-level integration and system testing because its substance is in
coordinating other parts or systems -- filesystems, network services, databases,
etc.

In any project you work on, you'll have to use your judgment to determine the
right kinds of tests, how to use them, and to what degree. Ask yourself, **"what
do I need to test, exactly?"** and let that determine the nature of your effort.

## Mocking

Many of our tests use an alternative approach to passing in stubs. In our
`JobServer` unit test above, we tested handling of a failing job by creating a
simple implementation of the `Job` interface whose `run()` method just throws an
exception. But often in code that doesn't use interfaces, we create trivial test
versions of concrete classes by *mocking* them.

For example, if you have some code that uses a database helper class, and that
helper class makes real MySQL calls, then the code using that helper is bound to
having a real MySQL instance running in the background:

```php
<?php

class MyDataProcessor {

    public function processData(DatabaseHelper $dbHelper) {
        $rows = $dbHelper->lookUpData(self::DATABASE_KEY);  // This runs a real MySQL query.

        $result = 0;
        foreach ($rows as $row) {
            // Process this $row...
        }
        return $result;
    }
}
```

If `DatabaseHelper` is a concrete class, you can use mocking to test this code
by creating a trivial, mock `DatabaseHelper` object:

```php
<?php

class MyDataProcessorTest extends PHPUnit_Framework_TestCase {
    public function testProcessData() {
        // Create a mock DatabaseHelper object.
        $mockDbHelper = $this->getMockBuilder('DatabaseHelper')
            ->disableOriginalConstructor()
            ->setMethods(array('lookUpData'))
            ->getMock();

        // Mock out a call to its lookUpData() method.
        $mockDbHelper->expects($this->once())
            ->method('lookUpData')
            ->with($this->equalTo(MyDataProcessor::DATABASE_KEY))
            ->will($this->returnValue(array('Data1', 'Data2', 'Data3')));

        $dataProcessorUnderTest = new MyDataProcessor();
        $result = $dataProcessorUnderTest->processData($mockDbHelper);
        $this->assertEquals(7, $result);
    }
}
```

Mocking can make it easy to test a piece of code in isolation when it doesn't
use interfaces. Or in some cases, it may be cleaner to mock a method or two than
to introduce a new abstraction. But if you use mocking as a substitute for
abstraction -- as a way to get passing tests without revisiting the design of
your code -- then you're undermining your code and your design in a deep way. As
in all these examples, use tests to evaluate your design, and be ready to
revisit your code (or anyone else's) when you find it's difficult to use.

Mocking is described in the [PHPUnit documentation][phpunit_mocking_link].

## Legacy code

Most codebases that have survived to importance have crucial code that wasn't
written with clean design and usability in mind. It may have tricky global
state, or lack abstractions (so that, for example, it does hard-coded database
operations in the middle of a class or method that's not database-related).

You'll have to use your imagination when you're working with such code. In the
end, your job is still to make your code well designed according to your
standards, regardless of the tricky bits it interacts with.

Say you need to use some legacy code that looks up a user's email address from
the database:

```php
<?php

class LegacyCode {

    private static function databaseLookUpOfEmailAddress($user) {
        // Do MySQL stuff...
    }

    public static function getEmailAddress($user) {
        return self::databaseLookUpOfEmailAddress($user);
    }
}
```

...and your code calls it like this:

```php
<?php

class MyNewClass {

    public function sendWelcomeEmail($user) {
        $this->sendEmail($this->welcomeText, LegacyCode::getEmailAddress($user));
    }
}
```

Now your code, through the `LegacyCode` class, has a hard-coded dependency on a
real MySQL database. In other words, there's no abstraction for the database
part of the code. Naturally, it's also hard to test. Why should you have to set
up a whole MySQL instance just to test the `sendWelcomeEmail()` function?

One approach to make your code how you want it is to devise an abstraction that
wraps the legacy code:

```php
<?php

class MyNewClass {

    public function __construct($emailLookerUpper) {
        $this->emailLookerUpper = $emailLookerUpper;
    }

    public function sendWelcomeEmail($user) {
        $this->sendEmail(
            $welcomeText,
            $this->emailLookerUpper->lookUpEmail($user));
    }
}

class ProdEmailLookerUpper implements EmailLookerUpper {
    public function lookUpEmail($user) {
        return LegacyCode::getEmailAddress($user);
    }
}
```

This solution frames the inputs to your class the way you want them. The
downside here is you risk creating many narrow and ad hoc abstractions for your
code.

Alternatively, you could try to improve the legacy code itself. You could start
by writing tests to cover the legacy code as is. This prepares you for safer
refactoring. If it's really hard to test the code as it's written, you'll have
to weigh the net benefit of writing complicated tests for brittle code that
you're planning to refactor anyway.

As for making the actual improvement, you could introduce the new abstraction
inside the legacy class -- but this may introduce more design problems than it
solves:

```php
<?php

class LegacyCode {

    private static $emailLookerUpper;

    // Introduce the EmailLookerUpper abstraction into all this static code
    // by using a static setter.
    public static function setEmailLookerUpper($emailLookerUpper) {
        self::$emailLookerUpper = $emailLookerUpper;
    }

    public static function getEmailAddress($user) {
        return self::$emailLookerUpper->lookUpEmail($user);
    }
}

class ProdEmailLookerUpper implements EmailLookerUpper {
    public function lookUpEmail($user) {
        // Do MySQL stuff from the old databaseLookUpOfEmailAddress() function...
    }
}
```

Global/static setters suck because they add a whole realm of complexity to your
code besides inputs and outputs, and they make it easy to introduce weird
entanglements between unrelated parts of your code. But the setter in this
example serves as a temporary measure to introduce an abstraction. Be warned --
this 'temporary' measure may be around a long time in the absence of a concerted
migration effort. At least you can test it.

While you're in there, you should add or adapt tests for the old functionality
you've refactored. Without tests, you're just rephrasing the code instead of
investing in the process of improving its design.

Another alternative is to just refactor the whole `LegacyCode` class with a
better object-oriented design -- *if* you can do it correctly, and *if* it's
worth the effort. But whenever you're tempted to gut a piece of legacy code and
rewrite it or significantly refactor it, do a cost-benefit analysis:

- How long will it take to rewrite?
- What features would it enable? How long would those features take without this
  rewrite?
- How much maintenance headache will it relieve us of? Consider the total value
  of this benefit across the whole engineering team.
- How many classes would I need to change to the rewritten API? How many of
  those classes are under active development and would benefit from easier use?

It may be better to only generalize or improve the code to the point required by
your current task (and add tests!).

## Conclusions

- To sum up, testing helps you think about your code in terms of inputs,
  outputs, and parts.
- The choice of the "right" abstraction can be difficult to judge, so try
  thinking about how your tests would look under a given set of abstractions, as
  a form of feedback.
- **Experiment.** Take your time. Enjoy the process.

## Try the CodeLab

The Testing 101 CodeLab (in this repo under `CodeLab/`) gives you in-depth
practice applying these ideas in real code. It's highly recommended that you
work through it.

## Appendix: Revision history

Version|Date|Author
-------|----|------
1.0|6/2014|Yash Parghi (yash@)
1.1|8/2014|Yash Parghi (yash@)

[phpunit_mocking_link]: http://phpunit.de/manual/current/en/test-doubles.html
