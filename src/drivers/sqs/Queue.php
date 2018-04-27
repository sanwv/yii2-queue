<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\queue\sqs;

use Aws\Credentials\CredentialProvider;
use Aws\Sqs\SqsClient;
use yii\base\NotSupportedException;
use yii\queue\cli\Queue as CliQueue;

/**
 * SQS Queue.
 *
 * @author Max Kozlovsky <kozlovskymaxim@gmail.com>
 * @author Manoj Malviya <manojm@girnarsoft.com>
 */
class Queue extends CliQueue
{
    /**
     * The SQS url.
     * @var string
     */
    public $url;

    /**
     * aws access key.
     * @var string|null
     */
    public $key;

    /**
     * aws secret.
     * @var string|null
     */
    public $secret;

    /**
     * region where queue is hosted.
     * @var string
     */
    public $region = '';

    /**
     * API version.
     * @var string
     */
    public $version = 'latest';

    /**
     * @var string command class name
     */
    public $commandClass = Command::class;

    /**
     * @var SqsClient
     */
    private $_client;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
    }

    /**
     * Listens queue and runs each job.
     *
     * @param bool $repeat whether to continue listening when queue is empty.
     * @param int $timeout number of seconds to sleep before next iteration.
     * @return null|int exit code.
     * @internal for worker command only
     */
    public function run($repeat, $timeout = 0)
    {
        return $this->runWorker(function (callable $canContinue) use ($repeat, $timeout) {
            while ($canContinue()) {
                if ($payload = $this->getPayload($timeout)) {
                    list($ttr, $message) = explode(';', $payload['Body'], 2);
                    //reserve it so it is not visible to another worker till ttr
                    $this->reserve($payload, $ttr);

                    if ($this->handleMessage(null, $message, $ttr, 1)) {
                        $this->release($payload);
                    }
                } elseif (!$repeat) {
                    break;
                }
            }
        });
    }

    /**
     * Gets a single message from SQS queue.
     *
     * @param int $timeout number of seconds for long polling. Must be between 0 and 20.
     * @return null|array payload.
     */
    private function getPayload($timeout = 0)
    {
        $payload = $this->getClient()->receiveMessage([
            'QueueUrl' => $this->url,
            'AttributeNames' => ['ApproximateReceiveCount'],
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => $timeout,
        ]);

        $payload = $payload['Messages'];
        if ($payload) {
            return array_pop($payload);
        }

        return null;
    }

    /**
     * @return \Aws\Sqs\SqsClient
     */
    protected function getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        if ($this->key !== null && $this->secret !== null) {
            $credentials = [
                'key' => $this->key,
                'secret' => $this->secret,
            ];
        } else {
            // use default provider if no key and secret passed
            //see - http://docs.aws.amazon.com/aws-sdk-php/v3/guide/guide/credentials.html#credential-profiles
            $credentials = CredentialProvider::defaultProvider();
        }
        $this->_client = new SqsClient([
            'credentials' => $credentials,
            'region' => $this->region,
            'version' => $this->version,
        ]);

        return $this->_client;
    }

    /**
     * Sets the AWS SQS client instance for the queue.
     *
     * @param SqsClient $client AWS SQS client object.
     */
    public function setClient(SqsClient $client)
    {
        $this->_client = $client;
    }

    /**
     * Set the visibility to reserve message.
     * So that no other worker can see this message.
     *
     * @param array $payload
     * @param int $ttr
     */
    private function reserve($payload, $ttr)
    {
        $receiptHandle = $payload['ReceiptHandle'];
        $this->getClient()->changeMessageVisibility([
            'QueueUrl' => $this->url,
            'ReceiptHandle' => $receiptHandle,
            'VisibilityTimeout' => $ttr,
        ]);
    }

    /**
     * Mark the message as handled.
     *
     * @param array $payload
     * @return bool
     */
    private function release($payload)
    {
        if (empty($payload['ReceiptHandle'])) {
            return false;
        }

        $receiptHandle = $payload['ReceiptHandle'];
        $response = $this->getClient()->deleteMessage([
            'QueueUrl' => $this->url,
            'ReceiptHandle' => $receiptHandle,
        ]);

        return $response !== null;
    }

    /**
     * Clears the queue.
     */
    public function clear()
    {
        $this->getClient()->purgeQueue([
            'QueueUrl' => $this->url,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function status($id)
    {
        throw new NotSupportedException('Status is not supported in the driver.');
    }

    /**
     * @inheritdoc
     */
    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        if ($priority) {
            throw new NotSupportedException('Priority is not supported in this driver');
        }

        $model = $this->getClient()->sendMessage([
            'DelaySeconds' => $delay,
            'QueueUrl' => $this->url,
            'MessageBody' => "$ttr;$message",
        ]);

        return $model['MessageId'];
    }
}