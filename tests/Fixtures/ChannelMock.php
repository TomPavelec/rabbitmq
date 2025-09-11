<?php declare(strict_types = 1);

namespace Tests\Fixtures;

use Bunny\Channel;
use Bunny\ClientInterface;
use Bunny\Message;
use Bunny\Protocol\MethodBasicConsumeOkFrame;
use Nette\Neon\Neon;
use Tests\Fixtures\Helper\RabbitMQMessageHelper;

final class ChannelMock extends Channel
{

	/** @var array<mixed> */
	public array $acks = [];

	public int $ackPos = 0;

	/** @var array<mixed> */
	public array $nacks = [];

	public int $nackPos = 0;

	private RabbitMQMessageHelper $messageHelper;

	/**
	 * Bunny\Client-like instance provided by tests, used to drive the run/stop loop.
	 * @var object
	 */
	private $client;

	public function __construct()
	{
		$config = Neon::decode(file_get_contents(__DIR__ . '/config/config.test.neon'));

		$this->messageHelper = RabbitMQMessageHelper::getInstance($config['rabbitmq']);
	}

	public function runClient($ttl = null): void
	{
		if ($this->client && method_exists($this->client, 'run')) {
			$this->client->run($ttl);
		}
	}


	/**
	 * @param array<string> $headers
	 */
	public function publish(
		$body,
		array $headers = [],
		$exchange = '',
		$routingKey = '',
		$mandatory = false,
		$immediate = false
	): bool|int
	{
		if ($exchange === '') {
			$this->messageHelper->publishToQueueDirectly($routingKey, $body, $headers);
		} else {
			$this->messageHelper->publishToExchange($exchange, $body, $headers, $routingKey);
		}

		return true;
	}

	public function consume(callable $callback, string $queue = '', string $consumerTag = '', bool $noLocal = false, bool $noAck = false, bool $exclusive = false, bool $nowait = false, array $arguments = [], int $concurrency = 1): MethodBasicConsumeOkFrame
	{
		$this->client->setCallback($callback);

		return new MethodBasicConsumeOkFrame;
	}

	public function ack(Message $message, $multiple = false): bool
	{
		if (!isset($this->acks[$this->ackPos])) {
			$this->acks[$this->ackPos] = [];
		}

		$this->acks[$this->ackPos][$message->deliveryTag] = $message->content;

		return true;
	}

	public function nack(Message $message, $multiple = false, $requeue = false): bool
	{
		if (!isset($this->nacks[$this->nackPos])) {
			$this->nacks[$this->nackPos] = [];
		}

		$this->nacks[$this->nackPos][$message->deliveryTag] = $message->content;

		return true;
	}

	public function setClient($client): void
	{
		$this->client = $client;
		$this->client->setChannel($this);
	}


	public function close(int $replyCode = 0, string $replyText = ''): void
	{
		if ($this->client && method_exists($this->client, 'stop')) {
			$this->client->stop();
		}
	}
}
