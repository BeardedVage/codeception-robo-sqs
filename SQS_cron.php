<?php
/**
 * Created by PhpStorm.
 * User: Vage Zakaryan
 * Date: 19.01.18
 */

$queueUrl = 'URL_SQS';
require 'vendor/autoload.php';

use Aws\Sqs\SqsClient;

//checking count of dockers
$dockerCounter = shell_exec('docker ps -q $1 | wc -l');
if ($dockerCounter < 6) {
    //connecting to SQS
    $client = SqsClient::factory(array(
        'key' => 'YOUR_AWS_KEY',
        'secret' => 'YOUR_AWS_SECRET',
        'region' => 'AWS_REGION',
        'version' => 'latest'
    ));

    //Receiving 1 message from SQS
    $result = $client->receiveMessage([
        'AttributeNames' => ['All'],
        'MaxNumberOfMessages' => 1,
        'QueueUrl' => $queueUrl,
    ]);

    //Check that we have messages in Queue
    if (($result->get('Messages')) != null) {
        $testData = json_decode($result->get('Messages')[0]['Body']);

        //Running tests for $testData->test on $testData->env.
        $output = exec("cd /var/www/llsoft && docker-compose run --rm codecept run acceptance -g $testData->test \
        --env $testData->env -c codeception_PCS_Enterprise.yml --html result_$testData->build"."_$testData->env"."_$testData->test.html");
        echo $output . "\n\n";

        exec("cd tests/_output/$testData->project && sudo touch counter/$testData->env" . "_" . "$testData->test");
        //Deleting message from queue
        $result = $client->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $result->get('Messages')[0]['ReceiptHandle']
        ]);
    }
}

die();