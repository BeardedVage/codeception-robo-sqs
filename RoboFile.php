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
            $countResults = shell_exec("cd tests/_output/$project && find -maxdepth 1 -type f | wc -l");
            echo "Let's wait a little bit. Tests are in progress. \n $countResults groups of tests are over. " .
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
        shell_exec('rm --rf tests/_output/' . $project);

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
}
