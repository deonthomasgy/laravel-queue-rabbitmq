<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue;

use DateTime;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection as AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;

class RabbitMQQueue extends Queue implements QueueInterface
{
    protected $connection;
    protected $channel;

    protected $defaultQueue;
    protected $declaredQueues = [];
    protected $declaredDelayedQueues = [];
    protected $configQueue;
    protected $configExchange;

    /**
     * @param AMQPConnection $amqpConnection
     * @param array          $config
     */
    public function __construct(AMQPConnection $amqpConnection, $config)
    {
        $this->connection = $amqpConnection;
        $this->defaultQueue = $config['queue'];
        $this->configQueue = $config['queue_params'];
        $this->configExchange = $config['exchange_params'];

        $this->channel = $this->getChannel();
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string $job
     * @param  mixed  $data
     * @param  string $queue
     *
     * @return bool
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, []);
    }

    public function pushToRoute($job, $data = '', $queue, $routingKey)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, ['routing_key' => $routingKey]);
    }
    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTime|int $delay
     * @param  string        $job
     * @param  mixed         $data
     * @param  string        $queue
     *
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, ['delay' => $delay]);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string $payload
     * @param  string $queue
     * @param  array  $options
     *
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queue = $this->getQueueName($queue);
        $this->declareQueue($queue);
        if (isset($options['delay'])) {
            $queue = $this->declareDelayedQueue($queue, $options['delay']);
        }

        $routingKey = null;
        if (isset($options['routing_key'])) {
            $routingKey = $options['routing_key'];
        }

        // push job to a queue
        $message = new AMQPMessage($payload, [
            'Content-Type'  => 'application/json',
            'delivery_mode' => 2,
        ]);

        // push task to a queue
        if (!is_null($routingKey)) {
            $this->channel->basic_publish($message, $queue, $routingKey);
        }
        else {
            $this->channel->basic_publish($message, $queue, $queue);
        }

        return true;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
     *
     * @return \Illuminate\Queue\Jobs\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueueName($queue);

        // declare queue if not exists
        $this->declareQueue($queue);

        // get envelope
        $message = $this->channel->basic_get($queue);

        if ($message instanceof AMQPMessage) {
            return new RabbitMQJob($this->container, $this, $this->channel, $queue, $message);
        }

        return null;
    }

    /**
     * @param string $queue
     *
     * @return string
     */
    public function getQueueName($queue)
    {
        return $queue ?: $this->defaultQueue;
    }

    /**
     * @return AMQPChannel
     */
    public function getChannel()
    {
        return $this->connection->channel();
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public function declareQueue($name)
    {
        $name = $this->getQueueName($name);

        // if the current queue has been already declared, skip this
        if (!in_array($name, $this->declaredQueues)) {
            $this->declaredQueues[]= $name;
        } else {
            return $name;
        }

        // declare queue
        $this->channel->queue_declare(
            $name,
            $this->configQueue['passive'],
            $this->configQueue['durable'],
            $this->configQueue['exclusive'],
            $this->configQueue['auto_delete']
        );

        // declare exchange
        $this->channel->exchange_declare(
            $name,
            $this->configExchange['type'],
            $this->configExchange['passive'],
            $this->configExchange['durable'],
            $this->configExchange['auto_delete']
        );

        // bind queue to the exchange
        $this->channel->queue_bind($name, $name, $name);

        return $name;
    }

    /**
     * @param string       $destination
     * @param DateTime|int $delay
     *
     * @return string
     */
    public function declareDelayedQueue($destination, $delay)
    {
        $delay = $this->getSeconds($delay);
        $destination = $this->getQueueName($destination);
        $name = $this->getQueueName($destination) . '_deferred_' . $delay;

        // if the current delayed queue has been already declared, skip this
        if (!in_array($name, $this->declaredDelayedQueues)) {
            $this->declaredDelayedQueues[]= $name;
        } else {
            return $name;
        }

        // declare exchange
        $this->channel->exchange_declare(
            $name,
            $this->configExchange['type'],
            $this->configExchange['passive'],
            $this->configExchange['durable'],
            $this->configExchange['auto_delete']
        );

        // declare queue
        $this->channel->queue_declare(
            $name,
            $this->configQueue['passive'],
            $this->configQueue['durable'],
            $this->configQueue['exclusive'],
            $this->configQueue['auto_delete'],
            false,
            new AMQPTable([
                'x-dead-letter-exchange'    => $destination,
                'x-dead-letter-routing-key' => $destination,
                'x-message-ttl'             => $delay * 1000,
            ])
        );

        // bind queue to the exchange
        $this->channel->queue_bind($name, $name, $name);

        return $name;
    }
}
