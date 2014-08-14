# CodeLab: Testing 101

In this CodeLab you'll redesign and refactor a piece of code so that it's
designed for testability, and you'll write high- and low-level tests for it.

## Prerequisites

- Required: You've read the
  [Etsy Testing Best Practices guide][testing_best_practices_link].

- Required: You have a basic understanding of what HTTP requests look like, and
  basic experience using a command-line HTTP tool like curl.

- Required: You've done basic work with JSON.

- Required: Apache is installed, and the `httpd` command is on the system path.

- Recommended: You have a basic understanding of what a REST API is and how it
  works over HTTP. (You might want to read up a little on Etsy's own REST API.)

- Recommended: You have **4 hours** set aside to do this CodeLab. There's not
  much code, but the involved refactoring is substantial.

## The example project

The codebase you'll be working on in this CodeLab is a small PHP webapp
consisting of three REST API-powered parts: an API **server** that serves data,
an API **client** that requests data, and a **controller** (corresponding to a
page a user visits) that populates API data in some template.

We want to use tests to make this system better.

### How things stand

Take a look at the server, client, and controller classes in `phplib/` to see
how they work. Also look at the PHP files in `htdocs/` to see how they're used
in our little website.

The client uses curl calls to fetch cart data from the server. The server sends
back cart data for a user. The controller renders the cart data that the client
fetched into an HTML template.

There are a few simultaneous testing and design questions that arise from asking
"How do we test this?":
- How can we test the `ApiClient`'s responsibilities cleanly -- say, without
  standing up a server in the background? What are the `ApiClient`'s
  responsibilities?
- How do we test the interaction between an ApiClient and an ApiServer?
- How do we test the controller? What are the controller's responsibilities?
- How do we test the whole website?

You're going to have to figure out answers to all of these in this CodeLab.

### Run the tests

Some helper code is already provided for you, to remove some grunt work from the
CodeLab. First, make sure this helper code still passes. Run the tests from
inside `CodeLab/`:

```
$ phpunit tests
```

If tests fail in a clean checkout, figure out why, and if you get stuck, ask for
help. Note this CodeLab assumes Apache is on the system path as the `httpd`
command.

### Create a branch for your work

Keep your work for this CodeLab separate from the `master` branch since it's not
intended to be pushed:

```
$ git checkout -b <whatever branch name you want>
```

## 1. Write unit tests for ApiClient

The file `tests/phpunit/ApiClientTest.php` contains a class for tests of the
`ApiClient` class. Add unit tests to make sure the ApiClient class handles its
responsibilities correctly.

This is tough as things stand, because the ApiClient is currently written as a
scripted conversation with a certain HTTP server at a certain address. Try to
look past this code at the abstract responsibilities this class takes on:

- Fetching API data from some source
- Backing off and retrying if the fetch attempt fails, and throwing an exception
  if retries fail

Then write simple, dumb unit tests that check those responsibilities. Your tests
must:

- Run in under 1 second total
- Not use any outside resources (such as a real HTTP API server)

You are free to refactor as much as you want, including adding new classes or
interfaces.

## 2. Write a client-server integration test

Add PHPUnit integration tests in a new file,
`tests/phpunit/ClientServerIntegrationTest.php`, that exercise a real HTTP
conversation between ApiClient and ApiServer. Check that the ApiClient gets back
data as expected. Find a way to test error cases as well.

We've provided a helper class, in `phplib/Testing/BackgroundAppRunner.php`, that
runs Apache in the background and serves this CodeLab's webapp on localhost at a
certain port. Look at that class to see how to use it.

## 3. Write a controller unit test

In `phplib/ShowCartController.php` you can see the controller code, like
`ApiClient`, is a sequence of hard-coded, scripted interactions. Factor it so
you can write simple, dumb unit tests for it in
`tests/phpunit/ShowCartControllerTest.php`.

As before, start by asking yourself what the controller's responsibilities are.
You might answer:

- Asking some client for API data
- Rendering it somehow

And as before, your job is write a test of these responsibilities without having
to, say, talk to a real HTTP server or parse the HTML in a real HTTP response.

## 4. Write an end-to-end system test in a shell script

Your last task is to test this whole website in a setup that's as close to real
life as possible.

We won't use PHPUnit for this test, in the interest of testing/using our website
as a user would. Instead of interacting with the PHP code, we'll make curls to
our website in a shell script and check that our controller returns a proper
webpage with the proper data. Your test should curl to `/your_cart.php` and
check that the cart contents are rendered correctly.

So your test will be a shell script (put it in `tests/end_to_end.sh`) that exits
with status 0 on test success, and something non-0 on test failure.

In your script you'll need to (among other things):

- Start the webapp in the background. See
  `phplib/Testing/BackgroundAppRunner.php` for a potential approach you can
  mimic.
- Make sure the `ApiClient` knows to talk to the `ApiServer` running at
  localhost on the right port.
- Make a curl request to your controller and inspect the response to make sure
  the right items appear in the right quantities.

## Conclusion

- In real life, for greater safety and confidence, we could approach this work
  backwards, by first writing system tests that exercise the code as written.
  These tests would give us confidence that, as we refactor and add integration
  and unit tests, we aren't breaking the overall existing functionality.

- Say you did all this work on real-life code. Ask yourself: what's been gained
  from the time you spent? (Note we didn't add any new functionality to this
  website.) What took the least time and provided the most benefit? What took
  the most time and provided the least benefit?

## Now what?

1. It's *highly* recommended that you send your CodeLab solution out to a
teammate for code review. They can learn from your approach, and you can learn
from their input. Change what you're convinced to change based on review.

1. Consider it your job now to read a lot of code and understand why it works
that way (for reasons good or bad) before you use it or change it.

[testing_best_practices_link]: ../Testing_Best_Practices.md
