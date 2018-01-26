<?php
/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
require 'vendor/autoload.php';
use Aws\Sqs\SqsClient;
use Codeception\Task\MergeReports;
use Codeception\Task\SplitTestsByGroups;

class RoboFile extends \Robo\Tasks
{
    use MergeReports;
    use SplitTestsByGroups;

    protected $numGroups;
    private $queueUrl = '';
    private $awsKey = '';
    private $awsSecret = '';
    private $awsRegion = '';
    private $awsVersion = 'latest';

    /**
     * @var SqsClient
     */
    private $sqsClient;

    /**
     * function for sending to SQS messages
     *
     * @param $envs
     * @param $tests
     * @param $buildName
     * @param $project
     */
    public function sendMessageToSQS($envs, $tests, $buildName, $project)
    {
        foreach ($envs as $env) {
            foreach ($tests as $test) {
                $testData = '{"project":"' . $project . '","env":"' . $env . '","test":"' . $test . '","build":"' . $buildName . '"}';
                echo "Sending to SQS the next data: $testData. \n";

                $this->sqsClient->sendMessage([
                    'QueueUrl' => $this->queueUrl . $project,
                    'MessageBody' => $testData
                ]);
            }
        }
    }

    /**
     * function for waiting till all tests will be finished
     *
     * @param $envs
     * @param $tests
     * @param $project
     */
    public function waitForTestsFinish($envs, $tests, $project)
    {
        $expectedCount = count($envs) * count($tests);
        //check messages in Queue
        do {
            sleep(60);
            $countResults = shell_exec("cd /var/www/llsoft/tests/_output/$project && find -maxdepth 1 -type f | wc -l");
            echo "Let's wait a little bit. Tests are in progress. $countResults groups of tests are over. " .
                ($expectedCount - $expectedCount) . " are left \n";
        } while ($countResults < $expectedCount);
    }

    public function mergeSqsReports($envs, $tests, $buildName, $project)
    {
        $merge = $this->taskMergeHTMlReports();
        foreach ($envs as $env) {
            foreach ($tests as $test) {
                $merge->from("tests/_output/$project/$env/$test/result_$buildName.html");
            }
        }
        $result = $merge->into("tests/_output/$project/result_$buildName.html")->run();
        echo $result;
    }

    public function runSqsTests($envs, $tests, $buildName, $project)
    {
        //converting data to array
        $envs = explode(',', $envs);
        $tests = explode(',', $tests);

        //deleting old reports
        shell_exec('rm --rf /var/www/llsoft/tests/_output/' . $project);

        $this->sqsClient = SqsClient::factory(array(
            'key' => $this->awsKey,
            'secret' => $this->awsSecret,
            'region' => $this->awsRegion,
            'version' => $this->awsVersion
        ));
        $this->sendMessageToSQS($envs, $tests, $buildName, $project);
        $this->waitForTestsFinish($envs, $tests, $project);
        $this->mergeSqsReports($envs, $tests, $buildName, $project);
    }

    /**
     * function for split groups of tests into few threads
     *
     * @param $groups
     * @return mixed
     */
    public function parallelSplitTests($groups)
    {
        $groups = explode(',', $groups);
        $count = count($groups);

        //checking count of groups
        if ($count < 5) {
            $l = $count;
        } else {
            $l = 5;
        }

        //checking if we have less than 5 groups selected
        for ($k = 0; $k < $l; $k++) {
            $groupedGroups[$k] = '';
        }

        $i = 0;
        foreach ($groups as $group) {
            if ($i < 5) {
                $groupedGroups[$i] .= ' -g ' . $group;
                $i++;
            } else {
                $i = 0;
                $groupedGroups[$i] .= ' -g ' . $group;
            }
        }
        return $groupedGroups;
    }

    //function for run tests parallel by groups

    /**
     * @param $groups
     * @param $environments
     * @param $buildNumber
     * @return \Robo\Result
     */
    public function runParallel($groups, $environments, $buildNumber)
    {
        $envList = '';
        $environments = explode(',', $environments);
        foreach ($environments as $environment) {
            $envList .= ' --env ' . $environment;
        }

        $parallel = $this->taskParallelExec();
        $i = 1;

        foreach ($groups as $group) {
            $parallel->process(
                $this->taskExec("docker-compose run codecept run $group --html result_$buildNumber$i.html $envList")
            );
            $i++;
        }
        return $parallel->run();
    }

    //function for merging 5 resulst into 1

    /**
     * @param $buildNumber
     * @param $groups
     */
    public function parallelMergeResults($buildNumber, $groups)
    {
        $merge = $this->taskMergeHTMlReports();
        if (count($groups) < 6) {
            $n = count($groups);
        } else {
            $n = 6;
        }
        for ($i = 1; $i < $n; $i++) {
            $merge->from("tests/_output/result_$buildNumber$i.html");
        }
        $merge->into("tests/_output/result_$buildNumber.html")->run();
    }

    //Function for run all grouped tests in 5 threads

    /**
     * @param $environments
     * @param $groups
     * @param $buildNumber
     * @return \Robo\Result
     */
    public function runParallelGroups($environments, $groups, $buildNumber)
    {
        $groups = $this->parallelSplitTests($groups);
        $result = $this->runParallel($groups, $environments, $buildNumber);
        $this->parallelMergeResults($buildNumber, $groups);
        return $result;
    }

    public function splitTests($suites)
    {
        //Converting string of folders to array
        $testPath = getcwd() . "/tests/acceptance/PCS/";
        $suites = explode(',', $suites);

        //Getting all tests from choosed folders to array
        foreach ($suites as $suite)
        {
            foreach (scandir($testPath.$suite) as $file) {
                if ('.' === $file) continue;
                if ('..' === $file) continue;

                $tests[] = 'tests/acceptance/PCS/'.$suite.'/'.$file;
            }
        }

        $i=0;
        $groups = [];
        $this->numGroups = 1;

        // splitting tests by groups
        foreach ($tests as $tmp) {
            $groups[($i % $this->numGroups) + 1][] = $tmp;
            $i++;
        }

        // saving group files
        foreach ($groups as $i => $tests) {
            $filename = "tests/_data/paracept_$i";
            file_put_contents($filename, implode("\n", $tests));
        }
    }

    public function changeYml($buildNumber, $lastBuildNumber){
        $oldMessage = "    log: tests/_output/$lastBuildNumber
";
        $deletedFormat = "    log: tests/_output/$buildNumber
";
        $str=file_get_contents('/var/www/irm/codeception.yml');
        $str=str_replace("$oldMessage", "$deletedFormat",$str);
        file_put_contents('/var/www/irm/codeception.yml', $str);
    }

    public function takeFiles()
    {
        $testsList = ("/var/www/irm/tests/_data/GroovyScriptTests");
        exec("rm -rf $testsList");
        $path = realpath('/var/www/irm/tests/acceptance/');
        $objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
        foreach($objects as $name => $object){
            if (!is_dir($name)) {
                $name = substr($name, 13);
                file_put_contents($testsList, '"' . $name . '"' . ',' . PHP_EOL, FILE_APPEND);
            }
        }
        file_put_contents($testsList, ']', FILE_APPEND);
        //TODO add return[ at beginning of file
    }

    public function runLoad($test){
        $parallel = $this->taskParallelExec();
        for ($i = 1; $i <= $test; $i++) {
            $parallel->process(
                $this->taskExec("/home/vage/Downloads/apache-jmeter-3.2/bin/jmeter -n -t /home/vage/LoadTest.jmx")
            );
        }
        return $parallel->run();
    }

    public function runDev($environment, $buildNumber)
    {
        $tenants = '';
        $environments = explode(',', $environment);
        foreach ($environments as $environment) {
            $tenants .= ' --env ' . $environment;
        }

        $command = "docker-compose run --rm codecept run acceptance -g paracept_1 --html result_$buildNumber.html$tenants";
        $result = $this->taskExec($command)->run();

        return $result;
    }

    //function for running all tests
    public function runDevTests($environment, $suites, $buildNumber)
    {
        $this->splitTests($suites);
        $result = $this->runDev($environment, $buildNumber);
        return $result;
    }

    //function for running tests in 1 thread by groups
    public function runGroupTests($environment, $groups, $buildNumber)
    {
        $tenants = '';
        $environments = explode(',', $environment);
        foreach ($environments as $environment) {
            $tenants .= ' --env ' . $environment;
        }

        $groupNames = '';
        $groups = explode(',', $groups);
        foreach ($groups as $group) {
            $groupNames .= ' -g ' . $group;
        }

        $command = "docker-compose run --rm codecept run acceptance$groupNames --html result_$buildNumber.html$tenants";

        $result = $this->taskExec($command)->run();
        return $result;
    }

    public function runPhp($environment)
    {
        $environments = explode(',', $environment);
        $parallel = $this->taskParallelExec();
        foreach ($environments as $environment) {
            $tenant = ' --env ' . $environment;
            $parallel->process(
                $this->taskExec("docker-compose run --rm codecept run request --html result_php_$environment.html$tenant")
            );
        }
        return $parallel->run();
    }

    public function mergePhpResults($environment)
    {
        $environments = explode(',', $environment);
        $merge = $this->taskMergeHTMLReports();
        foreach ($environments as $environment){
            $merge->from("tests/_output/result_php_$environment.html");
        }
        $merge->into("tests/_output/result_php.html")
            ->run();
    }

    /**
     * function for split tests
     * @param $suites
     */
    public function splitTestsPhp($suites)
    {
        //Converting string of folders to array
        $testPath = getcwd() . "/tests/request/";
        $suites = explode(',', $suites);

        //Getting all tests from choosed folders to array
        foreach ($suites as $suite)
        {
            foreach (scandir($testPath.$suite) as $file) {
                if ('.' === $file) continue;
                if ('..' === $file) continue;

                $tests[] = 'tests/acceptance/PCS/'.$suite.'/'.$file;
            }
        }

        $i=0;
        $groups = [];
        $this->numGroups = 3;

        // splitting tests by groups
        foreach ($tests as $tmp) {
            $groups[($i % $this->numGroups) + 1][] = $tmp;
            $i++;
        }

        // saving group files
        foreach ($groups as $i => $tests) {
            $filename = "tests/_data/paracept_$i";
            file_put_contents($filename, implode("\n", $tests));
        }
    }

    public function runPhpTests($environments)
    {
        $results = $this->runPhp($environments);
        $this->mergePhpResults($environments);
        return $results;
    }

    /**
     * function for running technical tests
     * @param $env
     * @param $test
     */
    public function runTechnicalTest($env, $test)
    {
        $command = "docker-compose run --rm codecept run acceptance tests/acceptance/TechnicalSuites/"
            .$test. "Cest.php --steps --html technical_result.html";
        $env = explode(',', $env);
        foreach ($env as $item) {
            $command .= ' --env ' . $item;
        }
        $this->taskExec($command)->run();
    }
}
